<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Admin\UploadImageRequest;
use App\Http\Requests\Api\V1\Auth\ChangeSelfServicePasswordRequest;
use App\Http\Requests\Api\V1\Auth\UpdateSelfServiceAvatarRequest;
use App\Http\Requests\Api\V1\Auth\UpdateSelfServiceProfileRequest;
use App\Http\Resources\Api\V1\Auth\SelfServiceProfileResource;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\Admin\AdminMediaService;
use App\Services\Customer\ProfileService;
use App\Services\Media\MediaKeyGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class SelfServiceProfileController extends BaseController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ProfileService $profile,
        private readonly AdminMediaService $media,
        private readonly MediaKeyGenerator $mediaKeys,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return $this->profileResponse($request, $this->loadProfile($request->user()), 'Lấy thông tin cá nhân thành công!');
    }

    public function update(UpdateSelfServiceProfileRequest $request): JsonResponse
    {
        $user = $this->users->updateProfile($request->user(), $request->validated());

        return $this->profileResponse($request, $this->loadProfile($user), 'Cập nhật thông tin cá nhân thành công!');
    }

    public function stageAvatar(UploadImageRequest $request): JsonResponse
    {
        $data = $this->media->stageImage($request->user(), $request->file('image'));
        $data['preview_url'] = URL::temporarySignedRoute(
            'api.v1.auth.profile.avatar.uploads.preview',
            CarbonImmutable::parse($data['preview_expires_at']),
            ['uploadToken' => $data['upload_token']],
        );

        return $this->successResponseRaw($request, $data, 'Tải ảnh lên thành công!', 201)
            ->withHeaders(['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache']);
    }

    public function previewAvatar(Request $request, string $uploadToken): StreamedResponse|JsonResponse
    {
        return $this->media->previewStaging($request->user(), $uploadToken)
            ?? $this->errorResponse('Không tìm thấy upload staging', 404);
    }

    public function updateAvatar(UpdateSelfServiceAvatarRequest $request): JsonResponse
    {
        $actor = $request->user();
        $token = $request->validated('avatar_upload_token');
        $promotions = [];

        if ($token !== null) {
            $this->media->assertConsumableTokens($actor, [$token]);
        }

        try {
            [$user, $oldAvatar] = DB::transaction(function () use ($actor, $token, &$promotions): array {
                $user = User::query()->lockForUpdate()->findOrFail($actor->id);
                $oldAvatar = $user->avatar;

                $avatar = $token === null ? null : $this->media->promoteOwned(
                    $actor,
                    $token,
                    fn ($upload): string => $this->mediaKeys->avatar($user->id, $upload->extension),
                    $promotions,
                );

                $user->forceFill(['avatar' => $avatar])->save();

                return [$user->refresh(), $oldAvatar];
            }, 1);
        } catch (Throwable $exception) {
            $this->media->compensateFailedPromotions($promotions);

            throw $exception;
        }

        $this->media->cleanupPromotedStaging($promotions);
        $this->media->cleanupReplaced([$oldAvatar]);

        return $this->profileResponse($request, $this->loadProfile($user), 'Cập nhật ảnh đại diện thành công!');
    }

    public function changePassword(ChangeSelfServicePasswordRequest $request): JsonResponse
    {
        try {
            $this->profile->changePassword($request->user(), $request->validated());
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['current_password' => ['Mật khẩu hiện tại không đúng!']]);
        }

        // Match the existing customer password policy: the current session and
        // other valid sessions/tokens remain active; no generation is changed.
        return $this->successResponseRaw($request, null, 'Đổi mật khẩu thành công!');
    }

    private function loadProfile(User $user): User
    {
        if ($user->role !== UserRole::Customer) {
            $user->load(['branch:id,code,name', 'currentAssignment.branch:id,code,name']);
        }

        return $user;
    }

    private function profileResponse(Request $request, User $user, string $message): JsonResponse
    {
        return $this->successResponse($request, new SelfServiceProfileResource($user), $message);
    }
}
