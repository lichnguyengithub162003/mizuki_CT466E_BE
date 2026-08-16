<?php

use App\Http\Requests\Auth\StaffLoginRequest;

test('it authorizes staff login requests', function (): void {
    expect((new StaffLoginRequest)->authorize())->toBeTrue();
});

test('it keeps staff login email only', function (): void {
    $rules = (new StaffLoginRequest)->rules();

    expect($rules)->toHaveKeys(['email', 'phone', 'password'])
        ->and($rules['email'])->toContain('required', 'string', 'email', 'max:255')
        ->and($rules['phone'])->toContain('prohibited')
        ->and($rules['password'])->toContain('required', 'string');
});

test('it provides Vietnamese staff login validation messages', function (): void {
    $messages = (new StaffLoginRequest)->messages();

    expect($messages['email.required'])->toBe('Vui lòng nhập email')
        ->and($messages['phone.prohibited'])->toBe('Khu vực nhân viên chỉ hỗ trợ đăng nhập bằng email')
        ->and($messages['password.required'])->toBe('Vui lòng nhập mật khẩu');
});
