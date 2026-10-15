<?php

namespace App\Http\Requests;

use App\Models\Document;
use App\Models\DocumentMovement;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDocumentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $document = $this->route('document');
        $user = $this->user();

        return $document instanceof Document
            && $user !== null
            && $document->canBeEditedBy($user);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $types = Document::typeOptions();
        $document = $this->route('document');

        if ($document instanceof Document) {
            $types[] = $document->document_type;
        }

        return [
            'document.title' => ['required', 'string', 'max:255'],
            'document.details_fingerprint' => ['required', 'string', 'size:64'],
            // Changing a locked document's details needs a reason (checked in the save, once the changes are known);
            // it is kept with the change in the history.
            'document.correction_reason' => ['nullable', 'string', 'max:500'],
            'document.type' => ['required', 'string', Rule::in($types)],
            'document.reference' => ['nullable', 'string', 'max:100'],
            'document.description' => ['nullable', 'string', 'max:3000'],
            'document.origin' => ['required', 'string', 'max:180'],
            'document.sender' => ['required', 'string', 'max:180'],
            'document.received' => $document instanceof Document && $document->status === DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT
                ? ['nullable', Rule::prohibitedIf(true)]
                : ['required', 'date'],
            'document.due' => ['exclude'],
            // Priority is no longer shown or changed; existing values stay as they are.
            'document.priority' => ['exclude'],
        ] + $this->routeRules();
    }

    /**
     * Route editing is a separate permission from detail editing. Without it,
     * any submitted destinations are rejected instead of being silently dropped.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    private function routeRules(): array
    {
        if ($this->boolean('details_only')) {
            return ['document.destinations' => ['prohibited']];
        }

        $document = $this->route('document');
        $user = $this->user();

        if (! $document instanceof Document || $user === null) {
            return ['document.destinations' => ['prohibited']];
        }

        if (! $document->canEditOfficeAssignmentsBy($user) || ! $document->routesAreEditable()) {
            return ['document.destinations' => ['prohibited']];
        }

        $assignments = $document->officeAssignments()->get(['office', 'status']);
        $offices = array_values(array_unique(array_merge(
            Document::officeOptions(),
            $assignments->pluck('office')->all(),
        )));
        $maximumRoutes = $assignments->whereIn('status', DocumentMovement::FINISHED_STATUSES)->count() + 5;

        return [
            'document.destinations' => ['required', 'array', 'list', 'min:1', 'max:'.$maximumRoutes],
            'document.destinations.*' => ['required', 'string', 'max:180', 'distinct', Rule::in($offices)],
            'document.route_fingerprint' => ['nullable', 'string', 'size:64'],
        ];
    }

    public function messages(): array
    {
        $document = $this->route('document');
        $user = $this->user();
        $canEditOfficeAssignments = $document instanceof Document
            && $user !== null
            && $document->canEditOfficeAssignmentsBy($user);

        return [
            'document.details_fingerprint.required' => 'This edit form is out of date. Refresh the document and try again.',
            'document.destinations.prohibited' => $canEditOfficeAssignments
                ? ($document->status === DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT
                    ? 'Routes cannot be edited before Records receives the paper document.'
                    : 'Routes cannot be edited after a document is finished.')
                : 'You are not authorized to change document routes.',
            'document.destinations.required' => 'Select at least one destination office.',
            'document.destinations.min' => 'Select at least one destination office.',
            'document.destinations.max' => 'Select no more than five active destination offices.',
            'document.destinations.*.required' => 'Select an office for each route row.',
            'document.destinations.*.distinct' => 'Select each destination office only once.',
            'document.destinations.*.in' => 'Select recognized destination offices.',
        ];
    }

    public function attributes(): array
    {
        return [
            'document.title' => 'document title',
            'document.type' => 'document type',
            'document.reference' => 'external reference',
            'document.origin' => 'originating office or school',
            'document.received' => 'date received',
        ];
    }
}
