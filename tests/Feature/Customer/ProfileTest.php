<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// ==================== PROFILE ====================

test('a customer can view their profile', function (): void {
    $user = User::factory()->create(['role' => UserRole::Customer]);
    $this->actingAs($user);

    $this->getJson('/api/v1/customer/profile')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.email', $user->email);
});

test('a guest cannot view profile', function (): void {
    $this->getJson('/api/v1/customer/profile')
        ->assertUnauthorized();
});

test('profile response includes phone and avatar', function (): void {
    $user = User::factory()->create([
        'role' => UserRole::Customer,
        'phone' => '0901234567',
        'avatar' => 'avatars/customer.jpg',
    ]);
    $this->actingAs($user);

    $this->getJson('/api/v1/customer/profile')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.phone', '0901234567')
        ->assertJsonPath('data.avatar', 'avatars/customer.jpg');
});

test('a customer can update their profile', function (): void {
    $user = User::factory()->create(['role' => UserRole::Customer]);
    $this->actingAs($user);

    $this->patchJson('/api/v1/customer/profile', [
        'name' => 'Nguyễn Văn A',
    ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Nguyễn Văn A')
        ->assertJsonPath('message', 'Cập nhật thông tin cá nhân thành công!');
});

test('a customer can update their phone number', function (): void {
    $user = User::factory()->create(['role' => UserRole::Customer]);
    $this->actingAs($user);

    $this->patchJson('/api/v1/customer/profile', [
        'phone' => '0912345678',
    ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.phone', '0912345678');

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'phone' => '0912345678',
    ]);
});

test('a customer can clear their phone number', function (): void {
    $user = User::factory()->create([
        'role' => UserRole::Customer,
        'phone' => '0901234567',
    ]);
    $this->actingAs($user);

    $this->patchJson('/api/v1/customer/profile', [
        'phone' => null,
    ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.phone', null);

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'phone' => null,
    ]);
});

test('a customer can upload their avatar', function (): void {
    Storage::fake('public');
    $user = User::factory()->create(['role' => UserRole::Customer]);
    $this->actingAs($user);

    $response = $this->postJson('/api/v1/customer/profile/avatar', [
        'avatar' => UploadedFile::fake()->createWithContent(
            'avatar.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=')
        ),
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Cập nhật ảnh đại diện thành công!');

    $avatarPath = $response->json('data.avatar');

    expect($avatarPath)->toStartWith('avatars/');
    Storage::disk('public')->assertExists($avatarPath);
    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'avatar' => $avatarPath,
    ]);
});

test('a customer avatar upload rejects invalid files', function (): void {
    Storage::fake('public');
    $user = User::factory()->create(['role' => UserRole::Customer]);
    $this->actingAs($user);

    $this->postJson('/api/v1/customer/profile/avatar', [
        'avatar' => UploadedFile::fake()->create('avatar.pdf', 128, 'application/pdf'),
    ])
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('data.errors.avatar.0', 'Ảnh đại diện phải là tệp hình ảnh.');
});

test('a customer can change their password', function (): void {
    $user = User::factory()->create([
        'role'     => UserRole::Customer,
        'password' => bcrypt('oldpassword123'),
    ]);
    $this->actingAs($user);

    $this->patchJson('/api/v1/customer/profile/change-password', [
        'current_password'      => 'oldpassword123',
        'password'              => 'newpassword456',
        'password_confirmation' => 'newpassword456',
    ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Đổi mật khẩu thành công!');
});

test('change password fails with wrong current password', function (): void {
    $user = User::factory()->create([
        'role'     => UserRole::Customer,
        'password' => bcrypt('oldpassword123'),
    ]);
    $this->actingAs($user);

    $this->patchJson('/api/v1/customer/profile/change-password', [
        'current_password'      => 'wrongpassword',
        'password'              => 'newpassword456',
        'password_confirmation' => 'newpassword456',
    ])
        ->assertStatus(400)
        ->assertJsonPath('success', false);
});

// ==================== ADDRESSES ====================

test('a customer can list their addresses', function (): void {
    $user = User::factory()->create(['role' => UserRole::Customer]);
    UserAddress::factory()->count(3)->create(['user_id' => $user->id]);
    $this->actingAs($user);

    $this->getJson('/api/v1/customer/addresses')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(3, 'data');
});

test('a customer can add a new address', function (): void {
    $user = User::factory()->create(['role' => UserRole::Customer]);
    $this->actingAs($user);

    $this->postJson('/api/v1/customer/addresses', [
        'recipient_name'  => 'Nguyễn Văn A',
        'recipient_phone' => '0901234567',
        'province'        => 'Cần Thơ',
        'district'        => 'Ninh Kiều',
        'ward'            => 'An Khánh',
        'hamlet'          => 'Khu vực 3',
        'address_line'    => '123 Đường 3/2',
        'is_default'      => true,
    ])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.recipient_name', 'Nguyễn Văn A')
        ->assertJsonPath('data.district', 'Ninh Kiều')
        ->assertJsonPath('data.hamlet', 'Khu vực 3')
        ->assertJsonPath('data.is_default', true)
        ->assertJsonPath('message', 'Thêm địa chỉ thành công!');
});

test('a customer can update an address', function (): void {
    $user    = User::factory()->create(['role' => UserRole::Customer]);
    $address = UserAddress::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    $this->patchJson("/api/v1/customer/addresses/{$address->id}", [
        'address_line' => '456 Đường Mới',
    ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.address_line', '456 Đường Mới')
        ->assertJsonPath('message', 'Cập nhật địa chỉ thành công!');
});

test('a customer cannot update another user address', function (): void {
    $user    = User::factory()->create(['role' => UserRole::Customer]);
    $other   = User::factory()->create(['role' => UserRole::Customer]);
    $address = UserAddress::factory()->create(['user_id' => $other->id]);
    $this->actingAs($user);

    $this->patchJson("/api/v1/customer/addresses/{$address->id}", [
        'address_line' => 'Hack attempt',
    ])
        ->assertForbidden();
});

test('a customer can delete an address', function (): void {
    $user    = User::factory()->create(['role' => UserRole::Customer]);
    $address = UserAddress::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    $this->deleteJson("/api/v1/customer/addresses/{$address->id}")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Xóa địa chỉ thành công!');

    $this->assertSoftDeleted('user_addresses', ['id' => $address->id]);
});

test('a customer can set default address', function (): void {
    $user     = User::factory()->create(['role' => UserRole::Customer]);
    $address1 = UserAddress::factory()->create(['user_id' => $user->id, 'is_default' => true]);
    $address2 = UserAddress::factory()->create(['user_id' => $user->id, 'is_default' => false]);
    $this->actingAs($user);

    $this->patchJson("/api/v1/customer/addresses/{$address2->id}/default")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.is_default', true)
        ->assertJsonPath('message', 'Đặt địa chỉ mặc định thành công!');

    $this->assertDatabaseHas('user_addresses', ['id' => $address1->id, 'is_default' => false]);
    $this->assertDatabaseHas('user_addresses', ['id' => $address2->id, 'is_default' => true]);
});
