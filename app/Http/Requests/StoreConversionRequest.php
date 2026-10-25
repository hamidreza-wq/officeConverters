<?php

namespace App\Http\Requests;

use App\Services\Conversions\FormatRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConversionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->hasVerifiedEmail();
    }

    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:5'],
            'files.*' => ['required', 'file', 'max:'.config('conversions.max_kb')],
            'target_format' => ['required', Rule::in(app(FormatRegistry::class)->targets())],
            'request_key' => ['required', 'uuid'],
            'accept_limitations' => ['accepted'],
        ];
    }
}
