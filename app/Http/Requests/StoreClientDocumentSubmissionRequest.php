<?php

namespace App\Http\Requests;

use App\Models\Document;
use App\Rules\EmailDomainReceivesMail;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
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
            'email' => ['bail', 'nullable', 'string', 'email:rfc,filter', 'max:255', new EmailDomainReceivesMail, $this->emailNotOverused(...)],
            'delivery_method' => ['required', 'string', Rule::in(Document::DELIVERY_METHODS)],
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in(Document::typeOptions())],
            'destinations' => ['prohibited'],
            'route_remarks' => ['prohibited'],
            'client_remarks' => ['nullable', 'string', 'max:1000'],
            'attachment' => config('app.document_uploads_enabled')
                ? ['nullable', File::types(['pdf', 'jpg', 'jpeg', 'png', 'docx', 'xlsx', 'xls', 'csv'])->max('10mb'), 'extensions:pdf,jpg,jpeg,png,docx,xlsx,xls,csv']
                : ['prohibited'],
            // Data Privacy Act of 2012: the sender agrees to the privacy notice before personal data is stored.
            'privacy_consent' => ['accepted'],
        ];
    }

    /**
     * One address may be given on only a few online submissions a day, so the form cannot flood someone's inbox.
     * Sending the same submission again (same key) does not count against it.
     */
    private function emailNotOverused(string $attribute, mixed $value, \Closure $fail): void
    {
        $address = Str::lower(trim((string) $value));
        // Removed submissions still count, so deleting spam does not reopen the address to more of it.
        $used = Document::withTrashed()
            ->whereNotNull('client_submission_key')
            ->where('client_submission_key', '!=', (string) $this->input('submission_key'))
            ->where('client_email', $address)
            ->where('created_at', '>=', now()->subDay())
            ->count();

        if ($used >= config('dts.client_email.submissions_per_address_per_day')) {
            $fail('This email was already used on several documents today. Leave it blank to send your document now, or try again tomorrow.');
        }
    }

    public function messages(): array
    {
        return [
            'contact_reference.prohibited' => 'The document could not be sent. Please try again.',
            'privacy_consent.accepted' => 'Agree to the privacy notice to send your document.',
            'name.required' => 'Enter your name.',
            'office.required' => 'Enter your office.',
            'email.email' => 'Enter a valid email address, or leave it blank.',
            'delivery_method.required' => 'Choose how the document will reach the Records Unit.',
            'delivery_method.in' => 'Choose Hand carry or Official channel.',
            'type.required' => 'Select the document type.',
            'type.in' => 'Select a recognized document type.',
            'destinations.prohibited' => 'Records staff choose the destination offices after intake.',
            'route_remarks.prohibited' => 'Give routing instructions to Records staff at the counter.',
            'client_remarks.string' => 'Enter your routing instructions as text.',
            'client_remarks.max' => 'Keep routing instructions within 1,000 characters.',
            'attachment.extensions' => 'The document must be a PDF, JPG, PNG, DOCX, XLSX, XLS, or CSV file.',
        ];
    }
}
