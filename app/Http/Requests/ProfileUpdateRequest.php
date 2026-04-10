<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'              => ['required', 'string', 'max:255'],
            'email'             => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($this->user()->id)],
            'bio'               => ['nullable', 'string', 'max:300'],
            'location'          => ['nullable', 'string', 'max:100'],
            'birth_year'        => ['nullable', 'integer', 'min:1940', 'max:' . (date('Y') - 10)],
            'favorite_distance' => ['nullable', 'string', 'max:50'],
        ];
    }
}
