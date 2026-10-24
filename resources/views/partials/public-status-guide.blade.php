{{-- Status guide dropdown for the public tracking page: the statuses the current workflow uses (Receive, Forward,
     Complete, Release), in the order a document goes through them, each as its badge with a one-line meaning. --}}
@php
    $guideStatuses = [
        // "Pending" covers both waiting statuses; the list under it names each kind.
        \App\Models\DocumentMovement::STATUS_AWAITING_RECEIPT => 'Still waiting on a step. It can mean:',
        \App\Models\DocumentMovement::STATUS_RECEIVED => \App\Models\DocumentMovement::STATUS_MEANINGS[\App\Models\DocumentMovement::STATUS_RECEIVED],
        \App\Models\DocumentMovement::STATUS_FORWARDED => 'The document was sent on to the next office. It waits there until that office receives it.',
        \App\Models\DocumentMovement::STATUS_COMPLETED => \App\Models\DocumentMovement::STATUS_MEANINGS[\App\Models\DocumentMovement::STATUS_COMPLETED],
        \App\Models\DocumentMovement::STATUS_RELEASED => 'The Records Unit released the document. Processing is finished.',
    ];
    $pendingKinds = [
        'Being reviewed' => 'the office is checking your document before acting on it.',
        'Awaiting acknowledgment of receipt' => 'the office has not received the document yet.',
        'Waiting for document/s' => 'bring the document/s to the Records Unit so processing can start.',
    ];
@endphp
<details class="dts-public-status-guide rounded-sm border border-province-line-strong bg-white {{ $class ?? '' }}">
    <summary class="flex min-h-11 cursor-pointer items-center gap-2 px-4 text-sm font-semibold text-[#216E39]">
        <x-orchid-icon path="bs.question-circle" class="size-4 shrink-0" />
        <span class="grow">Status guide: what each status means</span>
        <x-orchid-icon path="bs.chevron-down" class="dts-public-status-guide-chevron size-4 shrink-0" />
    </summary>
    {{-- Every status is laid out the same way: its badge, then its meaning underneath. --}}
    <dl class="grid divide-y divide-province-line border-t border-province-line px-4">
        @foreach ($guideStatuses as $status => $meaning)
            <div class="grid justify-items-start gap-1 py-3">
                <dt>@include('partials.public-status-badge', ['status' => $status])</dt>
                <dd class="text-sm leading-6 text-slate-600">
                    {{ $meaning }}
                    @if ($status === \App\Models\DocumentMovement::STATUS_AWAITING_RECEIPT)
                        <ul class="mt-1 list-disc ps-5">
                            @foreach ($pendingKinds as $kind => $kindMeaning)
                                <li><strong class="font-semibold text-slate-800">{{ $kind }}:</strong> {{ $kindMeaning }}</li>
                            @endforeach
                        </ul>
                    @endif
                </dd>
            </div>
        @endforeach
    </dl>
</details>
