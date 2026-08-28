<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductMutationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $productId = $this->route('product');

        return [
            'category_id' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:categories,id'],
            'brand_id' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:brands,id'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'slug' => [$creating ? 'required' : 'sometimes', 'string', 'max:255', Rule::unique('products', 'slug')->ignore($productId)],
            'short_description' => ['sometimes', 'nullable', 'string'],
            'description' => ['sometimes', 'nullable', 'string'],
            'ingredients' => ['sometimes', 'nullable', 'string'],
            'usage_instructions' => ['sometimes', 'nullable', 'string'],
            'specifications' => ['sometimes', 'nullable', 'array'],
            'origin_country' => ['sometimes', 'nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'variants' => [$creating ? 'required' : 'sometimes', 'array', 'min:1'],
            'variants.*.id' => ['sometimes', 'integer'],
            'variants.*.name' => ['required_with:variants', 'string', 'max:255'],
            'variants.*.sku' => ['required_with:variants', 'string', 'max:100'],
            'variants.*.barcode' => ['nullable', 'string', 'max:100'],
            'variants.*.attributes' => ['nullable', 'array'],
            'variants.*.price' => ['required_with:variants', 'integer', 'min:0'],
            'variants.*.sale_price' => ['nullable', 'integer', 'min:0', 'lte:variants.*.price'],
            'variants.*.weight' => ['required_with:variants', 'integer', 'min:0'],
            'variants.*.sort_order' => ['sometimes', 'integer', 'min:0'],
            'variants.*.is_active' => ['sometimes', 'boolean'],
            'images' => ['sometimes', 'array'],
            'images.*.image_url' => ['required', 'string', 'max:2048'],
            'images.*.product_variant_id' => ['nullable', 'integer'],
            'images.*.alt_text' => ['nullable', 'string', 'max:255'],
            'images.*.sort_order' => ['sometimes', 'integer', 'min:0'],
            'images.*.is_primary' => ['sometimes', 'boolean'],
        ];
    }
}
