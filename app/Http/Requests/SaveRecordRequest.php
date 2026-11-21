<?php

namespace App\Http\Requests;

use App\Rules\PasswordBytes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web') !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }
    }

    public function rules(): array
    {
        $kind = $this->route('kind');
        $id = $this->route('id');
        $image = match ($kind) {
            'products' => 'image','staff' => 'avatar',default => null
        };
        $rules = match ($kind) {
            'products' => ['name' => 'required|string|max:100', 'price' => 'required|numeric|min:0|max:99999999.99|decimal:0,2', 'stock_quantity' => 'required|integer|min:0|max:2147483647', 'version' => $id ? 'required|string' : 'nullable'],
            'customers' => ['full_name' => 'required|string|max:100', 'email' => ['required', 'email', 'max:100', Rule::unique('customer_accounts', 'email')->ignore($id, 'customer_id')], 'phone' => 'nullable|string|max:20', 'password' => ['nullable', 'string', 'min:8', 'confirmed', new PasswordBytes]],
            'staff' => ['username' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('users')->ignore($id)], 'full_name' => 'required|string|max:100', 'password' => [$id ? 'nullable' : 'required', 'string', 'min:8', 'confirmed', new PasswordBytes]]
        };
        if ($image) {
            $rules[$image] = 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096|dimensions:max_width=6000,max_height=6000';
        }

        return $rules;
    }

    public function attributes(): array
    {
        return ['full_name' => 'full name', 'stock_quantity' => 'stock quantity', 'avatar' => 'profile picture', 'version' => 'record version'];
    }
}
