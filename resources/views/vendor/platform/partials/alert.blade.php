{{-- Override: the form error box reads like the rest of DTS (same as the sign-in error box) instead of Orchid's
     "Oh snap!" banner, with an icon so it does not rely on color alone. --}}
@if (session()->has(\Orchid\Alert\Alert::SESSION_MESSAGE))
    <div class="alert alert-{{ session(\Orchid\Alert\Alert::SESSION_LEVEL) }} rounded shadow-sm mb-3 p-4 d-flex" data-turbo-temporary>
        {!! session(\Orchid\Alert\Alert::SESSION_MESSAGE) !!}

        @yield('flash_notification.sub_message')
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif

@empty(!$errors->count())
    <div class="alert alert-danger dts-form-errors mb-3" role="alert">
        <x-orchid-icon path="bs.exclamation-circle" class="dts-form-errors-icon" />
        <div>
            <strong>{{ $errors->count() === 1 ? 'Please fix this before saving:' : 'Please fix these before saving:' }}</strong>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
