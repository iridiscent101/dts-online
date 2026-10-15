{{-- Override: labelled fields (WCAG 1.3.1 / 4.1.2), a larger "Remember me" checkbox, and the sign-in errors shown above
     the fields (Orchid's own form printed them through its field helpers, which these plain inputs do not use). --}}
@if (session('dts_signed_out') && ! $errors->any())
    <div class="alert alert-info d-flex gap-2 align-items-start mb-3" role="status">
        <x-orchid-icon path="bs.clock-history" class="mt-1 flex-shrink-0"/>
        <p class="mb-0">{{ session('dts_signed_out') }}</p>
    </div>
@endif

@if ($errors->any())
    <div id="login-errors" class="alert alert-danger d-flex gap-2 align-items-start mb-3" role="alert">
        <x-orchid-icon path="bs.exclamation-circle" class="mt-1 flex-shrink-0"/>
        <div>
            @foreach ($errors->all() as $message)
                <p class="mb-0">{{ $message }}</p>
            @endforeach
        </div>
    </div>
@endif

<div class="mb-3">
    <label class="form-label" for="login-email">{{ __('Email address') }}</label>
    <input id="login-email" type="email" name="email" @class(['form-control', 'is-invalid' => $errors->has('email')]) value="{{ old('email') }}" required autofocus autocomplete="email" inputmode="email" placeholder="{{ __('Enter your email') }}" @if ($errors->any()) aria-invalid="true" aria-describedby="login-errors" @endif>
</div>

<div class="mb-3">
    <label class="form-label" for="login-password">{{ __('Password') }}</label>
    @include('platform::partials.password-input', ['id' => 'login-password', 'invalid' => $errors->has('password'), 'describedBy' => $errors->any() ? 'login-errors' : null])
</div>

<div class="row align-items-center">
    <div class="col-md-6 col-xs-12">
        <label class="form-check dts-remember">
            <input type="hidden" name="remember">
            <input type="checkbox" name="remember" value="true" class="form-check-input" {{ !old('remember') || old('remember') === 'true' ? 'checked' : '' }}>
            <span class="form-check-label">{{ __('Remember me') }}</span>
        </label>
    </div>
    <div class="col-md-6 col-xs-12">
        <button id="button-login" type="submit" class="btn btn-default btn-block">
            <x-orchid-icon path="bs.box-arrow-in-right" class="small me-2"/>
            {{ __('Sign in') }}
        </button>
    </div>
</div>
