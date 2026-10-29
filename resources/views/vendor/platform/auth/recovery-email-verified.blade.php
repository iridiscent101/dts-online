@extends('platform::auth')
@section('title', __('Recovery email verified'))

@section('content')
    <div class="dts-auth-done">
        <span class="dts-auth-done-icon" aria-hidden="true"><x-orchid-icon path="bs.check-lg"/></span>
        <h1 class="dts-auth-title">{{ __('Recovery email verified') }}</h1>
        <p class="dts-auth-subtitle">{{ __('Your recovery email can now receive password reset links.') }}</p>
        <a href="{{ route('platform.login') }}" class="btn btn-default dts-auth-submit">
            <x-orchid-icon path="bs.box-arrow-in-right"/>
            {{ __('Back to sign in') }}
        </a>
    </div>
@endsection
