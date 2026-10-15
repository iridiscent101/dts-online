@php
    $statusPresentation = [
        'healthy' => ['Operational', 'bs.check2-circle', 'dts-health-ok'],
        'warning' => ['Attention', 'bs.exclamation-triangle', 'dts-health-warning'],
        'critical' => ['Action required', 'bs.exclamation-octagon', 'dts-health-critical'],
    ];
    [$overallLabel, $overallIcon, $overallClass] = $statusPresentation[$health['overall']];
@endphp

<div class="dts-workspace dts-health-page">
    <section aria-labelledby="health-checks-title">
        <div class="dts-section-heading tw:flex tw:flex-wrap tw:items-end tw:justify-between tw:gap-3">
            <div>
                <h3 id="health-checks-title">Service checks</h3>
                <p class="dts-section-caption">Checked {{ \Carbon\CarbonImmutable::parse($health['checked_at'])->timezone('Asia/Manila')->format('M d, Y · g:i A') }}</p>
            </div>
            <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-2">
                <span class="dts-count">{{ count($health['checks']) }} checks</span>
                <span class="dts-health-overall {{ $overallClass }}">
                    <x-orchid-icon :path="$overallIcon" />
                    {{ $overallLabel }}
                </span>
            </div>
        </div>

        <div class="tw:grid tw:grid-cols-1 tw:gap-4 tw:md:grid-cols-2 tw:xl:grid-cols-3">
            @foreach ($health['checks'] as $check)
                @php([$statusLabel, $statusIcon, $statusClass] = $statusPresentation[$check['status']])
                <article class="dts-health-check {{ $statusClass }}">
                    <div class="tw:flex tw:items-start tw:justify-between tw:gap-3">
                        <div class="dts-health-check-heading">
                            <span class="dts-health-icon"><x-orchid-icon :path="$statusIcon" /></span>
                            <h4>{{ $check['label'] }}</h4>
                        </div>
                        <span class="dts-health-badge">{{ $statusLabel }}</span>
                    </div>
                    <strong>{{ $check['summary'] }}</strong>
                    <p>{{ $check['details'] }}</p>
                </article>
            @endforeach
        </div>
    </section>
</div>
