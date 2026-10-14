{{-- Override: the password field has a label (visually hidden; the account shown above makes it clear). --}}
<div class="mb-3 d-flex align-items-center">
    <div class="thumb-sm avatar me-3">
        <img src="{{ $lockUser->presenter()->image() }}" class="b bg-light" alt="">
    </div>
    <div class="d-flex flex-column overflow-hidden small">
        <span class="text-ellipsis">{{ $lockUser->presenter()->title() }}</span>
        <span class="text-muted d-block text-ellipsis">{{ $lockUser->presenter()->subTitle() }}</span>
    </div>
    <input type="hidden" name="email" required value="{{ $lockUser->email }}">
</div>

<div class="mb-3">
    <input type="hidden" name="remember" value="true">
    <label class="visually-hidden" for="lock-password">{{ __('Password for :name', ['name' => $lockUser->presenter()->title()]) }}</label>
    @include('platform::partials.password-input', ['id' => 'lock-password', 'autofocus' => true])

    @error('email')
        <span class="d-block invalid-feedback">{{ $errors->first('email') }}</span>
    @enderror
</div>

<div class="row align-items-center">
    <div class="col-md-6 col-xs-12">
        <a href="{{ route('platform.login.lock') }}" class="dts-switch-user">{{ __('Sign in with another user.') }}</a>
    </div>
    <div class="col-md-6 col-xs-12">
        <button id="button-login" type="submit" class="btn btn-default btn-block">
            <x-orchid-icon path="bs.box-arrow-in-right" class="small me-2"/>
            {{ __('Login') }}
        </button>
    </div>
</div>
