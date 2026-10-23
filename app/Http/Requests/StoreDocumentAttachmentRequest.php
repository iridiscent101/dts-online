<?php

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

class StoreDocumentAttachmentRequest extends FormRequest
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

        return $document->canHaveAttachmentsAddedBy($user);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'attachments' => ['required', 'array', 'min:1', 'max:5'],
            'attachments.*' => [
                'required',
                File::types(['pdf', 'jpg', 'jpeg', 'png', 'docx', 'xlsx', 'xls', 'csv'])->max('10mb'),
                'extensions:pdf,jpg,jpeg,png,docx,xlsx,xls,csv',
            ],
        ];
    }

    /**
     * @return array<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $document = $this->route('document');

                if (! $document instanceof Document || $validator->errors()->has('attachments')) {
                    return;
                }

                $newAttachmentCount = count($this->file('attachments', []));

                if ($document->attachments()->count() + $newAttachmentCount > 5) {
                    $validator->errors()->add('attachments', 'A document may have no more than five attachments.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'attachments.required' => 'Select at least one file to upload.',
            'attachments.max' => 'You may upload up to five files at a time.',
            'attachments.*.extensions' => 'Attachments must be PDF, JPG, PNG, DOCX, XLSX, XLS, or CSV files.',
        ];
    }
}
