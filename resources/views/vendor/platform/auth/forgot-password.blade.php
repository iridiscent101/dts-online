{{-- Forgot password (ForgotPasswordController): a temporary password is emailed to the confirmed recovery email. --}}
@extends('platform::auth')
@section('title', __('Forgot password'))

@section('content')
    <h1 class="dts-auth-title">{{ __('Forgot your password?') }}</h1>
    <p class="dts-auth-subtitle">{{ __('Enter the email you sign in with. We will send a temporary password to the recovery email saved in your profile.') }}</p>

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
            <label class="form-label" for="forgot-email">{{ __('Email address') }}</label>
            <input id="forgot-email" type="email" name="email" @class(['form-control', 'is-invalid' => $errors->has('email')]) value="{{ old('email') }}" required autofocus autocomplete="email" inputmode="email" placeholder="{{ __('Enter your email') }}" @if ($errors->any()) aria-invalid="true" aria-describedby="forgot-errors" @endif>
        </div>

        <button type="submit" class="btn btn-default dts-auth-submit">
            <x-orchid-icon path="bs.envelope"/>
            {{ __('Send temporary password') }}
        </button>
    </form>

    <p class="dts-auth-help">
        <x-orchid-icon path="bs.question-circle"/>
        <span>{{ __('No recovery email saved, or no email after a few minutes? Ask your system administrator to reset your password.') }}</span>
    </p>
    <p class="dts-auth-help">
        <x-orchid-icon path="bs.arrow-left"/>
        <a href="{{ route('platform.login') }}">{{ __('Back to sign in') }}</a>
    </p>
@endsection
