<?php

namespace App\Http\Requests;

use App\Models\Document;
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
        return $this->user()?->hasAccess('platform.index') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $action = $this->string('workflow.action')->toString();

        return [
            'workflow.action' => ['required', 'string', Rule::in(['acknowledge', 'forward', 'archive', 'restore'])],
            'workflow.destination' => [
                Rule::requiredIf(in_array($action, ['forward', 'restore'], true)),
                'nullable',
                'string',
                Rule::in(Document::OFFICES),
            ],
            'workflow.remarks' => [
                'required',
                'string',
                'max:1000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'workflow.action.in' => 'Choose an available workflow action.',
            'workflow.destination.required' => 'Select a destination office.',
            'workflow.destination.in' => 'Select a recognized destination office.',
            'workflow.remarks.required' => 'Provide a note for this action.',
        ];
    }
}
