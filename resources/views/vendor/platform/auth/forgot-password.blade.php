@extends('platform::auth')
@section('title', __('Forgot password'))

@section('content')
    <h1 class="dts-auth-title">{{ __('Forgot your password?') }}</h1>
    <p class="dts-auth-subtitle">{{ __('We will email a reset link to your verified recovery email.') }}</p>

    @if (session('status'))
        <div class="alert alert-success d-flex gap-2 align-items-start mb-3" role="status">
            <x-orchid-icon path="bs.envelope-check" class="mt-1 flex-shrink-0"/>
            <p class="mb-0">{{ session('status') }}</p>
        </div>
    @endif

    @if ($errors->any())
        <div id="forgot-errors" class="alert alert-danger d-flex gap-2 align-items-start mb-3" role="alert">
            <x-orchid-icon path="bs.exclamation-circle" class="mt-1 flex-shrink-0"/>
            <div>
                @foreach ($errors->all() as $message)
                    <p class="mb-0">{{ $message }}</p>
                @endforeach
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('password.forgot.send') }}">
        @csrf
        <div class="mb-3">
            <label class="form-label" for="forgot-email">{{ __('Sign-in email') }}</label>
            {{-- autocomplete="username" makes the browser offer saved sign-in addresses, not every email it remembers
                 (an autofilled recovery Gmail address matches no account, so nothing is sent). --}}
            <input id="forgot-email" type="email" name="email" @class(['form-control', 'is-invalid' => $errors->has('email')]) value="{{ old('email') }}" required autofocus autocomplete="username" inputmode="email" placeholder="{{ __('The email you sign in with') }}" @if ($errors->any()) aria-invalid="true" @endif aria-describedby="{{ $errors->any() ? 'forgot-errors ' : '' }}forgot-email-help">
            <p class="form-text dts-forgot-hint" id="forgot-email-help">
                <strong>{{ __('Type the email you use to sign in to this system,') }}</strong>
                {{ __('not your recovery email. The reset link goes to your verified recovery email.') }}
            </p>
        </div>

        <button type="submit" class="btn btn-default dts-auth-submit">
            <x-orchid-icon path="bs.envelope"/>
            {{ __('Send reset link') }}
        </button>
    </form>

    <p class="dts-auth-help">
        <x-orchid-icon path="bs.question-circle"/>
        <span>{{ __('No recovery email saved, or no email after a few minutes? Ask your system administrator to reset your password.') }}</span>
    </p>
    <p class="dts-auth-help">
        <x-orchid-icon path="bs.arrow-left"/>
        <a href="{{ route('platform.login') }}" class="d-inline-block py-1">{{ __('Back to sign in') }}</a>
    </p>
@endsection
