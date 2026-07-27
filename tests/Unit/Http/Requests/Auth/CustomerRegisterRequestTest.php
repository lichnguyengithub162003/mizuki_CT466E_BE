<?php

use App\Http\Requests\Auth\CustomerRegisterRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rules\Unique;

test('it authorizes customer registration requests', function (): void {
    $request = new CustomerRegisterRequest();

    expect($request->authorize())->toBeTrue();
});

test('it defines customer registration validation rules', function (): void {
    $request = new CustomerRegisterRequest();
    $rules = $request->rules();

    expect($rules)->toHaveKeys(['name', 'email', 'password'])
        ->and($rules['name'])->toContain('required', 'string', 'max:255')
        ->and($rules['email'])->toContain('required', 'string', 'email', 'max:255')
        ->and($rules['password'])->toContain('required', 'string', 'confirmed')
        ->and(collect($rules['email'])->contains(fn (mixed $rule): bool => $rule instanceof Unique))->toBeTrue()
        ->and(collect($rules['password'])->contains(fn (mixed $rule): bool => $rule instanceof Password))->toBeTrue();
});

test('it provides Vietnamese registration validation messages', function (): void {
    $request = new CustomerRegisterRequest();
    $messages = $request->messages();

    expect($messages['name.required'])->toBe('Vui lòng nhập họ tên')
        ->and($messages['email.unique'])->toBe('Email này đã được sử dụng')
        ->and($messages['password.confirmed'])->toBe('Xác nhận mật khẩu không khớp');
});
