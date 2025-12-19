<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class DeductPointsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'points' => ['required', 'integer', 'min:1', 'max:1000000'],
            'description' => ['required', 'string', 'max:255'],
            'metadata' => ['sometimes', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'points.min' => 'Points must be a positive integer.',
            'points.max' => 'Cannot deduct more than 1,000,000 points in a single transaction.',
            'description.required' => 'Description is required.',
        ];
    }
}
