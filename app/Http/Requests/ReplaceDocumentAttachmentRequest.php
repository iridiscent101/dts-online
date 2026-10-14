<?php

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class ReplaceDocumentAttachmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $document = $this->route('document');
        $user = $this->user();

        if (! $document instanceof Document || $user === null) {
            return false;
        }

        abort_unless($document->isVisibleTo($user), 404);

        return $document->canHaveAttachmentsManagedBy($user);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'replacement' => [
                'required',
                File::types(['pdf', 'jpg', 'jpeg', 'png', 'docx', 'xlsx', 'xls', 'csv'])->max('10mb'),
                'extensions:pdf,jpg,jpeg,png,docx,xlsx,xls,csv',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'replacement.required' => 'Select a replacement file.',
            'replacement.extensions' => 'The replacement must be a PDF, JPG, PNG, DOCX, XLSX, XLS, or CSV file.',
        ];
    }
}
