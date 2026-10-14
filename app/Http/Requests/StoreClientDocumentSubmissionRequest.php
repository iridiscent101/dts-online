<?php

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class StoreClientDocumentSubmissionRequest extends FormRequest
{
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
            'submission_key' => ['required', 'uuid'],
            // Honeypot: people never see this field, so anything in it was filled in by a bot.
            'contact_reference' => ['prohibited'],
            'name' => ['required', 'string', 'max:180'],
            'office' => ['required', 'string', 'max:180'],
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in(Document::typeOptions())],
            'destinations' => ['required', 'array', 'list', 'min:1', 'max:5'],
            'destinations.*' => ['required', 'string', 'max:180', 'distinct', Rule::in(Document::officeOptions())],
            'route_remarks' => ['nullable', 'array', 'list', 'size:'.count((array) $this->input('destinations', []))],
            'route_remarks.*' => ['nullable', 'string', 'max:2000'],
            'attachment' => config('app.document_uploads_enabled')
                ? ['nullable', File::types(['pdf', 'jpg', 'jpeg', 'png', 'docx', 'xlsx', 'xls', 'csv'])->max('10mb'), 'extensions:pdf,jpg,jpeg,png,docx,xlsx,xls,csv']
                : ['prohibited'],
            // Data Privacy Act of 2012: the sender agrees to the privacy notice before personal data is stored.
            'privacy_consent' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'contact_reference.prohibited' => 'The document could not be sent. Please try again.',
            'privacy_consent.accepted' => 'Agree to the privacy notice to send your document.',
            'name.required' => 'Enter your name.',
            'office.required' => 'Enter your office.',
            'type.required' => 'Select the document type.',
            'type.in' => 'Select a recognized document type.',
            'destinations.required' => 'Select at least one destination office.',
            'destinations.min' => 'Select at least one destination office.',
            'destinations.max' => 'Select no more than five destination offices.',
            'destinations.*.required' => 'Select an office for each route.',
            'destinations.*.distinct' => 'Select each destination office only once.',
            'destinations.*.in' => 'Select a recognized destination office.',
            'route_remarks.size' => 'Provide one remarks field for each destination office.',
            'attachment.extensions' => 'The document must be a PDF, JPG, PNG, DOCX, XLSX, XLS, or CSV file.',
        ];
    }
}
