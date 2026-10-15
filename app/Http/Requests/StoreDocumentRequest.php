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
        return $this->user()?->hasAccess('platform.index') && ! $this->user()->isReadOnlySupervisor();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $officeOptions = Document::officeOptions();

        return [
            'document.title' => ['required', 'string', 'max:255'],
            'document.type' => ['required', 'string', Rule::in(Document::typeOptions())],
            'document.reference' => ['nullable', 'string', 'max:100'],
            'document.description' => ['nullable', 'string', 'max:3000'],
            'document.origin' => ['required', 'string', 'max:180'],
            'document.sender' => ['required', 'string', 'max:180'],
            'document.received' => ['required', 'date'],
            'document.due' => ['exclude'],
            'document.destination' => ['nullable', 'string', 'max:180', Rule::in($officeOptions)],
            'document.destinations' => ['required', 'array', 'list', 'min:1', 'max:5'],
            'document.destinations.*' => ['required', 'string', 'max:180', 'distinct', Rule::in($officeOptions)],
            // Priority is no longer chosen; new documents are saved as "Standard".
            'document.priority' => ['exclude'],
            'document.remarks' => ['nullable', 'string', 'max:2000'],
            'document.route_remarks' => ['nullable', 'array', 'list', 'size:'.count((array) $this->input('document.destinations', []))],
            'document.route_remarks.*' => ['nullable', 'string', 'max:2000'],
            'attachments' => config('app.document_uploads_enabled') ? ['nullable', 'array', 'max:5'] : ['prohibited'],
            'attachments.*' => [
                'required',
                File::types(['pdf', 'jpg', 'jpeg', 'png', 'docx', 'xlsx', 'xls', 'csv'])->max('10mb'),
                'extensions:pdf,jpg,jpeg,png,docx,xlsx,xls,csv',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $document = $this->input('document', []);

        if (! is_array($document)) {
            return;
        }

        if (! array_key_exists('destinations', $document) && filled($document['destination'] ?? null)) {
            $document['destinations'] = [$document['destination']];
        }

        if (! array_key_exists('route_remarks', $document)
            && filled($document['remarks'] ?? null)
            && is_array($document['destinations'] ?? null)) {
            $document['route_remarks'] = array_fill(0, count($document['destinations']), $document['remarks']);
        }

        $this->merge(['document' => $document]);
    }

    public function messages(): array
    {
        return [
            'attachments.max' => 'You may upload up to five attachments.',
            'attachments.*.extensions' => 'Attachments must be PDF, JPG, PNG, DOCX, XLSX, XLS, or CSV files.',
            'document.destination.in' => 'Select a recognized initial destination office.',
            'document.destinations.required' => 'Select at least one initial destination office.',
            'document.destinations.min' => 'Select at least one initial destination office.',
            'document.destinations.max' => 'Select no more than five initial destination offices.',
            'document.destinations.*.required' => 'Select an office for each route row.',
            'document.destinations.*.distinct' => 'Select each destination office only once.',
            'document.destinations.*.in' => 'Select recognized initial destination offices.',
            'document.route_remarks.size' => 'Provide one remarks field for each destination office.',
        ];
    }
}
