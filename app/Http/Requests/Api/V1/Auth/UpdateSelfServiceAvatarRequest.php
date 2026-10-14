<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateSelfServiceAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'avatar_upload_token' => ['sometimes', 'required', 'uuid'],
            'avatar' => ['sometimes', 'nullable'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->exists('avatar_upload_token') === $this->exists('avatar')) {
                $validator->errors()->add('avatar', 'Gửi một upload token hoặc avatar = null để xóa ảnh.');
            }
            if ($this->exists('avatar') && $this->input('avatar') !== null) {
                $validator->errors()->add('avatar', 'Chỉ hỗ trợ avatar = null để xóa ảnh.');
            }
            foreach (array_diff(array_keys($this->all()), ['avatar_upload_token', 'avatar']) as $field) {
                $validator->errors()->add($field, 'Trường này không được phép cập nhật trong hồ sơ cá nhân.');
            }
        });
    }
}
