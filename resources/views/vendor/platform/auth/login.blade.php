{{-- Override: friendlier heading and a short line saying who this page is for. --}}
@extends('platform::auth')
@section('title', __('Sign in to your account'))

@section('content')
    <h1 class="dts-auth-title">{{ $isLockUser ? __('Welcome back') : __('Staff sign in') }}</h1>
    <p class="dts-auth-subtitle">{{ $isLockUser ? __('Enter your password to continue.') : __('Use the email and password given to you by your system administrator.') }}</p>

    @if (session('status'))
        <div class="alert alert-success d-flex gap-2 align-items-start mb-3" role="status">
            <x-orchid-icon path="bs.check-circle" class="mt-1 shrink-0"/>
            <p class="mb-0">{{ session('status') }}</p>
        </div>
    @endif

    <form role="form"
          method="POST"
          data-controller="form"
          data-form-need-prevents-form-abandonment-value="false"
          data-action="form#submit"
          action="{{ route('platform.login.auth') }}">
        @csrf

        @includeWhen($isLockUser, 'platform::auth.lockme')
        @includeWhen(! $isLockUser, 'platform::auth.signin')
    </form>
@endsection
