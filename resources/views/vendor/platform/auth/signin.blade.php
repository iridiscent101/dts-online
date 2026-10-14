{{-- Override: labelled fields (WCAG 1.3.1 / 4.1.2) and a larger "Remember me" checkbox. --}}
<div class="mb-3">
    <label class="form-label" for="login-email">{{ __('Email address') }}</label>
    <input id="login-email" type="email" name="email" class="form-control" value="{{ old('email') }}" required autofocus autocomplete="email" inputmode="email" placeholder="{{ __('Enter your email') }}">
</div>

<div class="mb-3">
    <label class="form-label" for="login-password">{{ __('Password') }}</label>
    @include('platform::partials.password-input', ['id' => 'login-password'])
</div>

<div class="row align-items-center">
    <div class="col-md-6 col-xs-12">
        <label class="form-check dts-remember">
            <input type="hidden" name="remember">
            <input type="checkbox" name="remember" value="true" class="form-check-input" {{ !old('remember') || old('remember') === 'true' ? 'checked' : '' }}>
            <span class="form-check-label">{{ __('Remember Me') }}</span>
        </label>
    </div>
    <div class="col-md-6 col-xs-12">
        <button id="button-login" type="submit" class="btn btn-default btn-block">
            <x-orchid-icon path="bs.box-arrow-in-right" class="small me-2"/>
            {{ __('Login') }}
        </button>
    </div>
</div>
