<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required_without:vendor_email', 'nullable', 'string', 'email', 'exists:users,email'],
            'vendor_email' => ['required_without:email', 'nullable', 'string', 'email'],
            'code' => ['required', 'string', 'size:6'],
            'device_name' => ['required', 'string', 'max:255'],
            'provider' => ['required_without:vendor_email', 'nullable', 'string', 'exists:providers,slug'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.exists' => 'No account found with this email address.',
            'email.required_without' => 'Either email or vendor_email is required.',
            'vendor_email.required_without' => 'Either email or vendor_email is required.',
            'code.size' => 'The verification code must be exactly 6 digits.',
            'provider.required_without' => 'Provider is required when using platform email.',
            'provider.exists' => 'The specified provider does not exist.',
        ];
    }
}
