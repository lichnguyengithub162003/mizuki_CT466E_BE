<?php

namespace App\Http\Requests\Clinic;

use Illuminate\Foundation\Http\FormRequest;

class AvailableSlotsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $today = now(config('app.timezone'))->startOfDay();

        return [
            'date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:'.$today->toDateString(),
                'before_or_equal:'.$today->copy()->addDays(90)->toDateString(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.required' => "Vui l\u{00F2}ng ch\u{1ECD}n ng\u{00E0}y \u{0111}\u{1EB7}t l\u{1ECB}ch!",
            'date.date_format' => "Ng\u{00E0}y \u{0111}\u{1EB7}t l\u{1ECB}ch ph\u{1EA3}i \u{0111}\u{00FA}ng \u{0111}\u{1ECB}nh d\u{1EA1}ng YYYY-MM-DD!",
            'date.after_or_equal' => "Ng\u{00E0}y \u{0111}\u{1EB7}t l\u{1ECB}ch kh\u{00F4}ng \u{0111}\u{01B0}\u{1EE3}c \u{1EDF} trong qu\u{00E1} kh\u{1EE9}!",
            'date.before_or_equal' => "Ch\u{1EC9} c\u{00F3} th\u{1EC3} xem l\u{1ECB}ch trong 90 ng\u{00E0}y t\u{1EDB}i!",
        ];
    }
}
