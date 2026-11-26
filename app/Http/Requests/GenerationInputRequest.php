<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared prompt, size, quality, and reference-link validation for the
 * estimate and submit endpoints. Mirrors the documented provider limits.
 */
class GenerationInputRequest extends FormRequest
{
    /**
     * @var list<string>
     */
    public const array SIZES = ['1024x1024', '1536x1024', '1024x1536'];

    /**
     * @var list<string>
     */
    public const array QUALITIES = ['low', 'medium', 'high'];

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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'min:1', 'max:4000'],
            'size' => ['required', 'string', Rule::in(self::SIZES)],
            'quality' => ['required', 'string', Rule::in(self::QUALITIES)],
            'reference_images' => ['nullable', 'array', 'list', 'max:4'],
            'reference_images.*' => ['string', 'url:http,https'],
        ];
    }
}
