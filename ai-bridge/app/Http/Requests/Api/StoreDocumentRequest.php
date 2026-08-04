<?php

namespace App\Http\Requests\Api;

use App\Services\Rag\DocumentTextExtractor;
use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Validated by extension via a closure, not Laravel's `mimes`
            // rule — Symfony's MIME guesser doesn't reliably map .md to a
            // mime type, which would reject legitimate uploads. Extended
            // beyond mvp-scope.md §8's original txt+md-only decision to
            // also accept pdf/docx (extracted to plain text before
            // chunking — see DocumentTextExtractor).
            'file' => [
                'required',
                'file',
                'max:20480', // KB (~20MB — PDFs/Word docs run larger than plain text)
                function (string $attribute, $value, \Closure $fail): void {
                    $extension = strtolower($value->getClientOriginalExtension());

                    if (! in_array($extension, DocumentTextExtractor::SUPPORTED_EXTENSIONS, true)) {
                        $fail('The file must be a .txt, .md, .pdf, or .docx file.');
                    }
                },
            ],
        ];
    }
}
