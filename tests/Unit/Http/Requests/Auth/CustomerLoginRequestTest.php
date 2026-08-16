<?php

use App\Http\Requests\Auth\CustomerLoginRequest;

test('it authorizes customer login requests', function (): void {
    $request = new CustomerLoginRequest;

    expect($request->authorize())->toBeTrue();
});

test('it defines customer login validation rules', function (): void {
    $request = new CustomerLoginRequest;
    $rules = $request->rules();

    expect($rules)->toHaveKeys(['email', 'phone', 'password'])
        ->and($rules['email'])->toContain(
            'required_without:phone',
            'prohibits:phone',
            'string',
            'email',
            'max:255',
        )
        ->and($rules['phone'])->toContain(
            'required_without:email',
            'prohibits:email',
            'string',
            'regex:/^0[35789][0-9]{8}$/',
        )
        ->and($rules['password'])->toContain('required', 'string');
});

test('it provides Vietnamese login validation messages', function (): void {
    $request = new CustomerLoginRequest;
    $messages = $request->messages();

    expect($messages['email.required_without'])->toBe('Vui lòng nhập email hoặc số điện thoại')
        ->and($messages['email.email'])->toBe('Email không đúng định dạng')
        ->and($messages['phone.regex'])->toBe('Số điện thoại không đúng định dạng')
        ->and($messages['password.required'])->toBe('Vui lòng nhập mật khẩu');
});
