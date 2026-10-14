{{-- Override: toasts stay at least 8 seconds so slower readers can finish the message; errors and warnings stay until closed.
     Each type has its own icon (picked by data-toast-type in dts.css), so the message does not rely on color alone. --}}
<div class="toast-wrapper" data-controller="toast">
    <template id="toast">
        <div class="toast rounded shadow-sm bg-white mb-3"
             role="alert"
             aria-live="assertive"
             aria-atomic="true"
             data-bs-delay="8000"
             data-bs-autohide="true">
            <div class="toast-body dts-toast-body" data-toast-type="{type}">
                @include('orchid.partials.toast-icons')
                <p class="mb-0">{message}</p>
                <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
    </template>

    @if (session()->has(\Orchid\Alert\Toast::SESSION_MESSAGE))
        @php
            $toastLevel = session(\Orchid\Alert\Toast::SESSION_LEVEL);
            $toastAutoHide = session(\Orchid\Alert\Toast::SESSION_AUTO_HIDE) && ! in_array($toastLevel, ['danger', 'warning'], true);
        @endphp
        <div class="toast rounded shadow-sm bg-white mb-3"
             role="alert"
             aria-live="assertive"
             aria-atomic="true"
             data-bs-delay="{{ max((int) session(\Orchid\Alert\Toast::SESSION_DELAY), 8000) }}"
             data-bs-autohide="{{ $toastAutoHide ? 'true' : 'false' }}"
             data-turbo-temporary
        >
            <div class="toast-body dts-toast-body" data-toast-type="{{ $toastLevel }}">
                @include('orchid.partials.toast-icons')
                <p class="mb-0">{!! session(\Orchid\Alert\Toast::SESSION_MESSAGE) !!}</p>
                <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
    @endif
</div>
