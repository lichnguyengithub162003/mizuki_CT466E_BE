<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserAddressFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id'         => User::factory(),
            'recipient_name'  => fake()->name(),
            'recipient_phone' => fake()->numerify('09########'),
            'province'        => fake()->randomElement(['Cần Thơ', 'Hồ Chí Minh', 'Hà Nội']),
            'district'        => fake()->randomElement(['Ninh Kiều', 'Cái Răng', 'Bình Thủy']),
            'ward'            => fake()->randomElement(['An Khánh', 'An Bình', 'Long Hòa']),
            'hamlet'          => fake()->optional()->randomElement(['Ấp 1', 'Thôn 2', 'Tổ 3']),
            'address_line'    => fake()->streetAddress(),
            'is_default'      => false,
            'province_code'   => null,
            'ghn_province_id' => null,
            'ghn_district_id' => null,
            'ghn_ward_code'   => null,
        ];
    }
}
