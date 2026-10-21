<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $productId = $this->route('product')?->id ?? $this->route('product');

        return [
            'name' => ['required', 'string', 'max:255'],
            'sku' => [
                'required',
                'string',
                'max:100',
                Rule::unique('products', 'sku')->ignore($productId),
            ],
            'barcode' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('products', 'barcode')->ignore($productId),
            ],
            'category_id' => ['required', 'exists:categories,id'],
            'brand' => ['nullable', 'string', 'max:100'],
            'unit' => ['required', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0', 'max:999999999.99'],
            'cost_price' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'stock' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'min_stock' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'weight' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'is_active' => ['nullable', 'boolean'],
            'images' => ['nullable', 'array', 'max:8'],
            'images.*' => ['image', 'mimes:jpeg,png,jpg,webp', 'max:3072'],
        ];
    }

    /**
     * Custom validation messages in Indonesian.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama produk wajib diisi.',
            'sku.required' => 'SKU produk wajib diisi.',
            'sku.unique' => 'SKU produk ini sudah digunakan oleh produk lain.',
            'barcode.unique' => 'Barcode ini sudah terdaftar pada produk lain.',
            'barcode.max' => 'Barcode maksimal 64 karakter.',
            'category_id.required' => 'Kategori produk wajib dipilih.',
            'category_id.exists' => 'Kategori yang dipilih tidak valid.',
            'unit.required' => 'Satuan produk wajib diisi (misal: Pcs, Rim, Box).',
            'price.required' => 'Harga jual wajib diisi.',
            'price.numeric' => 'Harga jual harus berupa angka.',
            'price.min' => 'Harga jual minimal Rp 0.',
            'price.max' => 'Harga jual terlalu besar (maksimal Rp 999.999.999,99).',
            'cost_price.numeric' => 'Harga modal/pokok harus berupa angka.',
            'cost_price.max' => 'Harga modal terlalu besar (maksimal Rp 999.999.999,99).',
            'stock.required' => 'Jumlah stok awal wajib diisi.',
            'stock.integer' => 'Stok harus berupa bilangan bulat.',
            'stock.min' => 'Stok tidak boleh negatif.',
            'stock.max' => 'Stok terlalu besar (maksimal 2.147.483.647).',
            'min_stock.required' => 'Batas minimum stok alert wajib diisi.',
            'min_stock.max' => 'Batas minimum stok terlalu besar (maksimal 2.147.483.647).',
            'weight.required' => 'Berat produk wajib diisi.',
            'weight.max' => 'Berat produk terlalu besar (maksimal 2.147.483.647 gram).',
            'images.max' => 'Maksimal 8 gambar yang dapat diunggah sekaligus.',
            'images.*.image' => 'Berkas harus berupa gambar.',
            'images.*.mimes' => 'Format gambar harus jpeg, png, jpg, atau webp.',
            'images.*.max' => 'Ukuran setiap gambar maksimal 3MB.',
        ];
    }
}
