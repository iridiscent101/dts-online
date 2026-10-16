<?php

namespace App\Http\Requests;

use App\Models\Document;
use App\Models\DocumentMovement;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentMovementRequest extends FormRequest
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
        $action = $this->string('workflow.action')->toString();
        $requiresDestination = DocumentMovement::requiresDestination($action);

        return [
            'workflow.action' => ['required', 'string', Rule::in(DocumentMovement::ROUTING_ACTIONS)],
            'workflow.source_office' => [
                'nullable',
                'string',
                'max:180',
            ],
            'workflow.destination' => [
                'nullable',
                'string',
                Rule::in(Document::officeOptions()),
            ],
            'workflow.destinations' => [
                Rule::requiredIf($requiresDestination),
                'array',
                'list',
                'min:1',
                'max:5',
            ],
            'workflow.destinations.*' => [
                'required',
                'string',
                'distinct',
                Rule::in(Document::officeOptions()),
            ],
            'workflow.remarks' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'workflow.released_to' => [
                Rule::requiredIf($action === DocumentMovement::ACTION_RELEASED),
                'string',
                'max:255',
            ],
            'workflow.destination_remarks' => [
                'array',
                'list',
            ],
            'workflow.destination_remarks.*' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $workflow = $this->input('workflow', []);

        if (! is_array($workflow)) {
            return;
        }

        if (! array_key_exists('destinations', $workflow) && filled($workflow['destination'] ?? null)) {
            $workflow['destinations'] = [$workflow['destination']];
        }

        if (DocumentMovement::requiresDestination((string) ($workflow['action'] ?? ''))
            && ! array_key_exists('destination_remarks', $workflow)
            && filled($workflow['remarks'] ?? null)
            && is_array($workflow['destinations'] ?? null)) {
            $workflow['destination_remarks'] = array_fill(0, count($workflow['destinations']), $workflow['remarks']);
        }

        if (array_key_exists('source_office', $workflow) && is_string($workflow['source_office'])) {
            $workflow['source_office'] = trim($workflow['source_office']);
        }

        if (array_key_exists('released_to', $workflow) && is_string($workflow['released_to'])) {
            $workflow['released_to'] = trim($workflow['released_to']);
        }

        $this->merge(['workflow' => $workflow]);
    }

    public function messages(): array
    {
        return [
            'workflow.action.in' => 'Choose an available workflow action.',
            'workflow.destination.in' => 'Select a recognized destination office.',
            'workflow.destinations.required' => 'Select at least one destination office.',
            'workflow.destinations.min' => 'Select at least one destination office.',
            'workflow.destinations.max' => 'Select no more than five destination offices.',
            'workflow.destinations.*.required' => 'Select a destination office for each route row.',
            'workflow.destinations.*.distinct' => 'Select each destination office only once.',
            'workflow.destinations.*.in' => 'Select recognized destination offices.',
            'workflow.released_to.required' => 'Enter the name of the person receiving the released document.',
        ];
    }
}
