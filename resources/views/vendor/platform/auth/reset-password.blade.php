@extends('platform::auth')
@section('title', __('Reset password'))

@section('content')
    <h1 class="dts-auth-title">{{ __('Choose a new password') }}</h1>
    <p class="dts-auth-subtitle">{{ __('Enter a strong password that you do not use for another account.') }}</p>

    @if ($errors->any())
        <div id="reset-errors" class="alert alert-danger d-flex gap-2 align-items-start mb-3" role="alert">
            <x-orchid-icon path="bs.exclamation-circle" class="mt-1 flex-shrink-0"/>
            <div>
                @foreach ($errors->all() as $message)
                    <p class="mb-0">{{ $message }}</p>
                @endforeach
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div class="mb-3">
            <label class="form-label" for="reset-email">{{ __('Sign-in email') }}</label>
            <input id="reset-email" type="email" name="email" class="form-control" value="{{ old('email', $email) }}" required autocomplete="username" readonly>
        </div>

        <div class="mb-3">
            <label class="form-label" for="reset-password">{{ __('New password') }}</label>
            <input id="reset-password" type="password" name="password" @class(['form-control', 'is-invalid' => $errors->has('password')]) required autofocus autocomplete="new-password" @if ($errors->any()) aria-invalid="true" aria-describedby="reset-errors" @endif>
            <small class="form-text text-muted">{{ \App\Models\User::PASSWORD_HINT }}</small>
        </div>

        <div class="mb-3">
            <label class="form-label" for="reset-password-confirmation">{{ __('Confirm new password') }}</label>
            <input id="reset-password-confirmation" type="password" name="password_confirmation" class="form-control" required autocomplete="new-password">
        </div>

        <button type="submit" class="btn btn-default dts-auth-submit">
            <x-orchid-icon path="bs.shield-check"/>
            {{ __('Reset password') }}
        </button>
    </form>
@endsection
