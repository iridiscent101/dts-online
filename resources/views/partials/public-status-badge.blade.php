{{-- One status badge for the public tracking page; the same colors everywhere a status shows. --}}
@php
    $badgeTone = match ($status) {
        'Awaiting receipt', 'Awaiting physical document', 'Waiting for physical document/s', 'On Hold' => 'border-amber-300 bg-amber-50 text-amber-900',
        'Received', 'Reviewed', 'Approved', 'Released', 'Completed', 'Archived', 'All offices finished' => 'border-emerald-300 bg-emerald-50 text-emerald-800',
        'Forwarded', 'Resubmitted', 'In progress' => 'border-[#86562D]/40 bg-[#F8F4E7] text-[#86562D]',
        'Returned', 'Rejected' => 'border-red-300 bg-red-50 text-red-800',
        'Cancelled' => 'border-slate-400 bg-slate-100 text-slate-700',
        default => 'border-province-line-strong bg-slate-100 text-slate-700',
    };
@endphp
<span class="inline-flex min-h-7 max-w-full items-center whitespace-normal sm:whitespace-nowrap rounded-sm border px-2.5 py-0.5 text-sm font-semibold uppercase tracking-wide {{ $badgeTone }} {{ $class ?? '' }}" {{ \App\Support\StatusLabel::titleAttribute($status) }}>{{ \App\Support\StatusLabel::for($status) }}</span>
