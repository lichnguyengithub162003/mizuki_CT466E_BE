<?php

use App\Http\Requests\Auth\CustomerLoginRequest;

test('it authorizes customer login requests', function (): void {
    $request = new CustomerLoginRequest();

    expect($request->authorize())->toBeTrue();
});

test('it defines customer login validation rules', function (): void {
    $request = new CustomerLoginRequest();
    $rules = $request->rules();

    expect($rules)->toHaveKeys(['email', 'password'])
        ->and($rules['email'])->toContain('required', 'string', 'email', 'max:255')
        ->and($rules['password'])->toContain('required', 'string');
});

test('it provides Vietnamese login validation messages', function (): void {
    $request = new CustomerLoginRequest();
    $messages = $request->messages();

    expect($messages['email.required'])->toBe('Vui lòng nhập email')
        ->and($messages['email.email'])->toBe('Email không đúng định dạng')
        ->and($messages['password.required'])->toBe('Vui lòng nhập mật khẩu');
});
