<?php

use App\Enums\MediaUploadStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\MediaUpload;
use App\Models\StaffAssignment;
use App\Models\User;
use App\Repositories\AdminPortalRepository;
use App\Services\Media\CloudinaryClientContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake('local');
    config()->set('media.public_driver', 'local');
    config()->set('media.public_disk', 'public');
    config()->set('media.private_disk', 'local');
});

function profileStaffSession(TestCase $test, User $staff): TestCase
{
    Auth::forgetGuards();
    $test->actingAs($staff, 'web');
    Auth::guard('web')->getSession()->put('staff_auth_generation', $staff->refresh()->auth_generation);

    return $test;
}

function profileBranch(): Branch
{
    return Branch::query()->create([
        'code' => 'PROFILE-BRANCH',
        'name' => 'Mizuki Profile',
        'phone' => '02923880001',
        'email' => 'profile-branch@mizuki.test',
        'address' => 'Ninh Kiều, Cần Thơ',
        'province_code' => '92',
        'ghn_district_id' => 1,
        'ghn_ward_code' => '00001',
        'is_active' => true,
    ]);
}

function profileImage(): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        'avatar.png',
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true),
    )->mimeType('image/png');
}

function stageProfileAvatar(TestCase $test, User $user): string
{
    if ($user->role === UserRole::Customer) {
        Auth::forgetGuards();
        $test->actingAs($user, 'web');
    } else {
        profileStaffSession($test, $user);
    }

    return (string) $test->post('/api/v1/auth/profile/avatar/uploads', ['image' => profileImage()], [
        'Accept' => 'application/json',
    ])->assertCreated()->json('data.upload_token');
}

test('staff sees only their own authoritative branch and current assignment', function (): void {
    $branch = profileBranch();
    $staff = User::factory()->create([
        'role' => UserRole::Technician,
        'branch_id' => $branch->id,
        'job_title' => 'Chuyên viên chăm sóc da',
    ])->refresh();
    $assignment = StaffAssignment::query()->create([
        'staff_id' => $staff->id,
        'branch_id' => $branch->id,
        'role' => UserRole::Technician,
        'job_title' => $staff->job_title,
        'work_area' => 'clinic',
        'effective_from' => now()->subDay(),
    ]);

    profileStaffSession($this, $staff)->getJson('/api/v1/auth/profile')
        ->assertOk()
        ->assertJsonPath('data.id', $staff->id)
        ->assertJsonPath('data.staff_code', $staff->staff_code)
        ->assertJsonPath('data.branch.name', $branch->name)
        ->assertJsonPath('data.work_area', 'clinic')
        ->assertJsonPath('data.current_assignment.id', $assignment->id)
        ->assertJsonPath('data.current_assignment.branch.id', $branch->id)
        ->assertJsonPath('data.employment_status', 'working')
        ->assertJsonMissingPath('data.password')
        ->assertJsonMissingPath('data.auth_generation');
});

test('missing assignment and branch are represented as null, not invented', function (): void {
    $staff = User::factory()->create(['role' => UserRole::SuperAdmin, 'branch_id' => null])->refresh();

    profileStaffSession($this, $staff)->getJson('/api/v1/auth/profile')
        ->assertOk()
        ->assertJsonPath('data.branch', null)
        ->assertJsonPath('data.work_area', null)
        ->assertJsonPath('data.current_assignment', null);
});

test('profile update permits only own name and phone', function (): void {
    $staff = User::factory()->create(['role' => UserRole::Cashier, 'phone' => '0900000001'])->refresh();
    $other = User::factory()->create(['role' => UserRole::Technician]);
    $oldEmail = $staff->email;
    $oldRole = $staff->role;

    profileStaffSession($this, $staff)->patchJson('/api/v1/auth/profile', [
        'name' => 'Tên mới', 'phone' => '0900000002',
    ])->assertOk()
        ->assertJsonPath('data.name', 'Tên mới')
        ->assertJsonPath('data.phone', '0900000002')
        ->assertJsonPath('data.email', $oldEmail);

    expect($staff->refresh()->role)->toBe($oldRole)
        ->and($other->refresh()->name)->not->toBe('Tên mới');
});

test('profile rejects privilege and identity changes instead of silently accepting them', function (): void {
    $branch = profileBranch();
    $staff = User::factory()->create(['role' => UserRole::SalesStaff])->refresh();

    foreach (['email', 'role', 'branch_id', 'staff_code', 'job_title', 'work_area', 'employment_status', 'auth_generation'] as $field) {
        profileStaffSession($this, $staff)->patchJson('/api/v1/auth/profile', [
            $field => $field === 'branch_id' ? $branch->id : 'forbidden',
        ])->assertUnprocessable()->assertJsonPath("data.errors.{$field}.0", 'Trường này không được phép cập nhật trong hồ sơ cá nhân.');
    }

    expect($staff->refresh()->role)->toBe(UserRole::SalesStaff)
        ->and($staff->branch_id)->toBeNull();
});

test('owned staged avatar can be previewed, promoted and removed with local public media', function (): void {
    $staff = User::factory()->create(['role' => UserRole::Technician])->refresh();
    profileStaffSession($this, $staff);
    $stage = $this->post('/api/v1/auth/profile/avatar/uploads', ['image' => profileImage()], [
        'Accept' => 'application/json',
    ])->assertCreated();
    $token = $stage->json('data.upload_token');
    $stage->assertJsonMissingPath('data.staging_key');
    expect($stage->json('data.preview_url'))->toContain('/api/v1/auth/profile/avatar/uploads/');
    $this->get($stage->json('data.preview_url'))->assertOk();
    $this->get("/api/v1/auth/profile/avatar/uploads/{$token}/preview")->assertForbidden();

    $response = $this->patchJson('/api/v1/auth/profile/avatar', ['avatar_upload_token' => $token])
        ->assertOk();
    $key = $staff->refresh()->avatar;
    $upload = MediaUpload::query()->where('upload_token', $token)->firstOrFail();
    expect($key)->toStartWith("public/users/{$staff->id}/avatar/")
        ->and($response->json('data.avatar'))->not->toBeNull()
        ->and($response->json('data.avatar'))->not->toBe($key)
        ->and($upload->uploaded_by_user_id)->toBe($staff->id)
        ->and($upload->final_key)->toBe($key)
        ->and($upload->status)->toBe(MediaUploadStatus::Promoted);
    Storage::disk('public')->assertExists($key);
    $this->getJson('/api/v1/auth/profile')->assertJsonPath('data.avatar', $response->json('data.avatar'));
    $this->patchJson('/api/v1/auth/profile/avatar', ['avatar_upload_token' => $token])->assertUnprocessable();

    $this->patchJson('/api/v1/auth/profile/avatar', ['avatar' => null])
        ->assertOk()->assertJsonPath('data.avatar', null);
    expect($staff->refresh()->avatar)->toBeNull();
    Storage::disk('public')->assertMissing($key);
});

test('owned avatar promotion and removal use canonical Cloudinary references without local public files', function (): void {
    config()->set('media.public_driver', 'cloudinary');
    config()->set('media.cloudinary_url', 'cloudinary://key:secret@demo-cloud');
    $staff = User::factory()->create(['role' => UserRole::Technician])->refresh();
    $token = stageProfileAvatar($this, $staff);
    $stagedKey = MediaUpload::query()->where('upload_token', $token)->value('staging_key');

    $client = Mockery::mock(CloudinaryClientContract::class);
    $client->shouldReceive('upload')->once()->andReturnUsing(function ($contents, array $options) use ($staff): array {
        expect($contents)->toBeResource()
            ->and($options['public_id'])->toMatch("~^mizuki/users/{$staff->id}/avatar/[0-9a-f-]{36}$~");

        return [
            'public_id' => $options['public_id'],
            'resource_type' => 'image',
            'format' => 'png',
            'bytes' => 68,
            'width' => 1,
            'height' => 1,
        ];
    });
    $client->shouldReceive('destroy')->once()->andReturnUsing(function (string $publicId, array $options) use ($staff): array {
        expect($publicId)->toMatch("~^mizuki/users/{$staff->id}/avatar/[0-9a-f-]{36}$~")
            ->and($options['invalidate'])->toBeTrue();

        return ['result' => 'ok'];
    });
    app()->instance(CloudinaryClientContract::class, $client);

    $response = $this->patchJson('/api/v1/auth/profile/avatar', ['avatar_upload_token' => $token])
        ->assertOk();
    $key = $staff->refresh()->avatar;
    $upload = MediaUpload::query()->where('upload_token', $token)->firstOrFail();
    expect($key)->toMatch("~^cloudinary:mizuki/users/{$staff->id}/avatar/[0-9a-f-]{36}$~")
        ->and($upload->uploaded_by_user_id)->toBe($staff->id)
        ->and($upload->final_key)->toBe($key)
        ->and($upload->status)->toBe(MediaUploadStatus::Promoted)
        ->and($response->json('data.avatar'))->toStartWith('https://res.cloudinary.com/demo-cloud/image/upload/f_auto/q_auto/')
        ->and($response->json('data.avatar'))->not->toBe($key);
    Storage::disk('local')->assertMissing($stagedKey);
    expect(Storage::disk('public')->allFiles())->toBe([]);
    $this->getJson('/api/v1/auth/profile')->assertJsonPath('data.avatar', $response->json('data.avatar'));
    $this->patchJson('/api/v1/auth/profile/avatar', ['avatar_upload_token' => $token])->assertUnprocessable();

    $this->patchJson('/api/v1/auth/profile/avatar', ['avatar' => null])
        ->assertOk()->assertJsonPath('data.avatar', null);
    expect($staff->refresh()->avatar)->toBeNull()
        ->and($upload->refresh()->status)->toBe(MediaUploadStatus::Deleted);
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

test('another user cannot consume or preview an avatar upload token', function (): void {
    $owner = User::factory()->create(['role' => UserRole::Technician]);
    $other = User::factory()->create(['role' => UserRole::Cashier]);
    $token = stageProfileAvatar($this, $owner);
    $preview = $this->post('/api/v1/auth/profile/avatar/uploads', ['image' => profileImage()], [
        'Accept' => 'application/json',
    ])->assertCreated()->json('data.preview_url');

    profileStaffSession($this, $other)->patchJson('/api/v1/auth/profile/avatar', [
        'avatar_upload_token' => $token,
    ])->assertUnprocessable();
    $this->get($preview)->assertNotFound();
    expect($other->refresh()->avatar)->toBeNull();
});

test('avatar request refuses arbitrary URL and cross-field updates', function (): void {
    $staff = User::factory()->create(['role' => UserRole::Technician]);
    profileStaffSession($this, $staff)->patchJson('/api/v1/auth/profile/avatar', [
        'avatar' => 'https://example.test/other.png',
    ])->assertUnprocessable();
    $this->patchJson('/api/v1/auth/profile/avatar', [
        'avatar' => null, 'role' => UserRole::SuperAdmin->value,
    ])->assertUnprocessable()->assertJsonPath('data.errors.role.0', 'Trường này không được phép cập nhật trong hồ sơ cá nhân.');
});

test('password change requires the current password and preserves authorized session', function (): void {
    $staff = User::factory()->create(['role' => UserRole::Technician, 'password' => 'old-password'])->refresh();
    profileStaffSession($this, $staff)->patchJson('/api/v1/auth/profile/password', [
        'current_password' => 'wrong-password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertUnprocessable()->assertJsonPath('data.errors.current_password.0', 'Mật khẩu hiện tại không đúng!');
    expect(Hash::check('old-password', $staff->refresh()->password))->toBeTrue();

    $this->patchJson('/api/v1/auth/profile/password', [
        'current_password' => 'old-password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertOk()->assertJsonMissingPath('data.password');
    expect(Hash::check('new-password', $staff->refresh()->password))->toBeTrue();
    $this->getJson('/api/v1/auth/profile')->assertOk();
    $this->getJson('/api/v1/auth/me')->assertOk();
});

test('password confirmation is required and an existing bearer token remains valid', function (): void {
    $staff = User::factory()->create(['role' => UserRole::Technician, 'password' => 'old-password'])->refresh();
    $token = $staff->createToken('profile-password')->plainTextToken;

    $this->withToken($token)->patchJson('/api/v1/auth/profile/password', [
        'current_password' => 'old-password',
        'password' => 'new-password',
        'password_confirmation' => 'different',
    ])->assertUnprocessable();
    expect(Hash::check('old-password', $staff->refresh()->password))->toBeTrue();

    $this->withToken($token)->patchJson('/api/v1/auth/profile/password', [
        'current_password' => 'old-password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertOk();
    Auth::forgetGuards();
    $this->withToken($token)->getJson('/api/v1/auth/profile')->assertOk()->assertJsonPath('data.id', $staff->id);
});

test('customer can use self-service fields but cannot see staff-only information', function (): void {
    $customer = User::factory()->create(['role' => UserRole::Customer, 'password' => 'old-password']);
    Auth::forgetGuards();
    $this->actingAs($customer, 'web')->getJson('/api/v1/auth/profile')
        ->assertOk()
        ->assertJsonPath('data.role', 'customer')
        ->assertJsonMissingPath('data.staff_code')
        ->assertJsonMissingPath('data.branch')
        ->assertJsonMissingPath('data.current_assignment');
    $this->patchJson('/api/v1/auth/profile', ['name' => 'Khách hàng mới'])->assertOk();
    $this->getJson('/api/v1/customer/profile')->assertOk()->assertJsonPath('data.name', 'Khách hàng mới');
    $this->patchJson('/api/v1/auth/profile/password', [
        'current_password' => 'old-password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertOk();
    expect(Hash::check('new-password', $customer->refresh()->password))->toBeTrue();
});

test('guests and invalidated staff sessions cannot use any self-service mutation', function (): void {
    $this->getJson('/api/v1/auth/profile')->assertUnauthorized();
    $this->patchJson('/api/v1/auth/profile', ['name' => 'No'])->assertUnauthorized();
    $this->patchJson('/api/v1/auth/profile/avatar', ['avatar' => null])->assertUnauthorized();
    $this->patchJson('/api/v1/auth/profile/password', [])->assertUnauthorized();
    $this->postJson('/api/v1/auth/profile/avatar/uploads', [])->assertUnauthorized();

    $staff = User::factory()->create(['role' => UserRole::Technician])->refresh();
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin])->refresh();
    profileStaffSession($this, $staff)->getJson('/api/v1/auth/profile')->assertOk();
    app(AdminPortalRepository::class)->trashStaff($admin, $staff->id);
    Auth::forgetGuards();
    $this->getJson('/api/v1/auth/profile')->assertUnauthorized();
    $this->patchJson('/api/v1/auth/profile', ['name' => 'No'])->assertUnauthorized();
    $this->patchJson('/api/v1/auth/profile/avatar', ['avatar' => null])->assertUnauthorized();
    $this->patchJson('/api/v1/auth/profile/password', [])->assertUnauthorized();
    $this->postJson('/api/v1/auth/profile/avatar/uploads', [])->assertUnauthorized();
});

test('a staff session without a bound generation fails closed on the new profile route', function (): void {
    $staff = User::factory()->create(['role' => UserRole::Technician]);

    $this->actingAs($staff)->getJson('/api/v1/auth/profile')->assertUnauthorized();
});
