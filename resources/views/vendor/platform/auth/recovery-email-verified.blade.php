@extends('platform::auth')
@section('title', __('Recovery email verified'))
@section('content')
    <h1 class="dts-auth-title">Recovery email verified</h1>
    <p>Your recovery email can now receive password reset links.</p>
    <a href="{{ route('platform.login') }}">Back to sign in</a>
@endsection
