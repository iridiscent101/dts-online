<?php

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class StoreDocumentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasAccess('platform.index') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'document.title' => ['required', 'string', 'max:255'],
            'document.type' => ['required', 'string', Rule::in(Document::TYPES)],
            'document.reference' => ['nullable', 'string', 'max:100'],
            'document.description' => ['nullable', 'string', 'max:3000'],
            'document.origin' => ['required', 'string', 'max:180'],
            'document.sender' => ['required', 'string', 'max:180'],
            'document.received' => ['required', 'date'],
            'document.due' => ['nullable', 'date', 'after_or_equal:document.received'],
            'document.destination' => ['required', 'string', 'max:180'],
            'document.priority' => ['required', 'string', Rule::in(['Normal', 'High', 'Urgent'])],
            'document.remarks' => ['nullable', 'string', 'max:2000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => [
                'required',
                File::types(['pdf', 'jpg', 'jpeg', 'png', 'docx', 'xlsx', 'xls', 'csv'])->max('10mb'),
                'extensions:pdf,jpg,jpeg,png,docx,xlsx,xls,csv',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'attachments.max' => 'You may upload up to five attachments.',
            'attachments.*.extensions' => 'Attachments must be PDF, JPG, PNG, DOCX, XLSX, XLS, or CSV files.',
        ];
    }
}
