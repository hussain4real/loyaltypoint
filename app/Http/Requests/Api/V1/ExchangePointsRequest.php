<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ExchangePointsRequest extends FormRequest
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
            'from_provider' => ['required', 'string', 'exists:providers,slug'],
            'to_provider' => ['required', 'string', 'exists:providers,slug', 'different:from_provider'],
            'points' => ['required', 'integer', 'min:1', 'max:10000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'from_provider.exists' => 'The source provider does not exist.',
            'to_provider.exists' => 'The destination provider does not exist.',
            'to_provider.different' => 'Cannot exchange points within the same provider.',
            'points.min' => 'Points must be a positive integer.',
            'points.max' => 'Cannot exchange more than 10,000,000 points in a single transaction.',
        ];
    }
}
