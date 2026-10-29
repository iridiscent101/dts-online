@extends('platform::auth')
@section('title', __('Reset password'))
@section('content')
    <h1 class="dts-auth-title">Reset your password</h1>
    @if ($errors->any())
        <div class="alert alert-danger" role="alert">
            @foreach ($errors->all() as $message)<p>{{ $message }}</p>@endforeach
        </div>
    @endif
    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <input type="hidden" name="email" value="{{ old('email', $request->query('email')) }}">
        <div class="mb-3"><label class="form-label" for="password">New password</label><input class="form-control" id="password" name="password" type="password" required autocomplete="new-password" aria-describedby="password-help"></div>
        <p id="password-help">{{ \App\Models\User::PASSWORD_HINT }}</p>
        <div class="mb-3"><label class="form-label" for="password_confirmation">Confirm new password</label><input class="form-control" id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"></div>
        <button class="btn btn-default dts-auth-submit" type="submit">Reset password</button>
    </form>
@endsection
