<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Validated by extension via a closure, not Laravel's `mimes`
            // rule — Symfony's MIME guesser doesn't reliably map .md to a
            // mime type, which would reject legitimate uploads.
            // mvp-scope.md §8: txt + md only.
            'file' => [
                'required',
                'file',
                'max:5120', // KB
                function (string $attribute, $value, \Closure $fail): void {
                    $extension = strtolower($value->getClientOriginalExtension());

                    if (! in_array($extension, ['txt', 'md'], true)) {
                        $fail('The file must be a .txt or .md file.');
                    }
                },
            ],
        ];
    }
}
