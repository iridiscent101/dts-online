{{-- A "Document details updated" entry: why locked details were corrected, and each field's old and new value. --}}
@php
    $changeReason = data_get($activity->properties, 'correction_reason');
    $changedFields = (array) data_get($activity->properties, 'changed_fields', []);
    $changeLabels = [
        'subject' => 'Title',
        'document_type' => 'Document type',
        'external_reference' => 'External reference',
        'description' => 'Description',
        'origin' => 'Originating office / school',
        'sender' => 'Sender',
        'received_at' => 'Date received',
        'due_at' => 'Due date',
        'priority' => 'Priority',
    ];
    $changeValue = function (mixed $value): string {
        if (blank($value)) {
            return '—';
        }
        $value = (string) $value;

        // Dates are stored as timestamps; show them the way the details panel does.
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1
            ? \Illuminate\Support\Carbon::parse($value)->format('d M Y')
            : $value;
    };
@endphp
@if (filled($changeReason) || $changedFields !== [])
    <details class="dts-workspace-history-remarks dts-change-details">
        <summary>{{ filled($changeReason) ? 'View reason and changes' : 'View changes' }}</summary>
        @if (filled($changeReason))
            <p class="dts-change-reason"><strong>Reason for the correction:</strong> {{ $changeReason }}</p>
        @endif
        @if ($changedFields !== [])
            <dl class="dts-change-list">
                @foreach ($changedFields as $field)
                    <div>
                        <dt>{{ $changeLabels[$field] ?? str($field)->headline() }}</dt>
                        <dd><span class="dts-change-before">{{ $changeValue(data_get($activity->properties, "before.$field")) }}</span> <span aria-label="changed to">&rarr;</span> <span>{{ $changeValue(data_get($activity->properties, "after.$field")) }}</span></dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </details>
@endif
