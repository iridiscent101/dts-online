{{-- Edit role: one permission per row. The checkbox and name, a one-line summary beside the name, and for the few
     permissions with rules worth knowing, a "More" toggle (outside the label, so opening it never ticks the box). --}}
@component($typeForm, get_defined_vars())
    <div class="dts-permission-row" data-controller="checkbox"
         data-checkbox-indeterminate="{{$indeterminate}}"
         @isset($permissionGroup) data-permission-group="{{ $permissionGroup }}" @endisset>
        @isset($sendTrueOrFalse)
            <input hidden name="{{$attributes['name']}}" value="{{$attributes['novalue']}}">
        @endisset
        <div class="form-check">
            <input @isset($sendTrueOrFalse) value="{{$attributes['yesvalue']}}" @endisset
                   {{ $attributes }}
                   @if(isset($attributes['value']) && $attributes['value'] && (isset($sendTrueOrFalse) || !isset($attributes['checked']) || $attributes['checked'] !== false)) checked @endif
            >
            <label class="form-check-label" for="{{$id}}">
                <span class="dts-permission-name">{{$placeholder ?? ''}}</span>
                @isset($permissionDescription)
                    <small class="dts-permission-description">{{$permissionDescription}}</small>
                @endisset
            </label>
        </div>
        @if (filled($permissionDetail ?? null))
            <details class="dts-permission-more">
                <summary><span class="dts-more-label">More</span><span class="dts-less-label">Less</span> <span class="visually-hidden">about {{ $placeholder ?? 'this permission' }}</span></summary>
                <p>{{ $permissionDetail }}</p>
            </details>
        @endif
    </div>
@endcomponent
