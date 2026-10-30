@extends('platform::auth')
@section('title', __('Demo sign-up'))

@section('content')
    <h1 class="dts-auth-title">{{ __('Create a demo account') }}</h1>
    <p class="dts-auth-subtitle">{{ __('For today\'s demo only. After you create it, sign in with your new email and password.') }}</p>

    @if ($errors->any())
        <div id="demo-errors" class="alert alert-danger d-flex gap-2 align-items-start mb-3" role="alert">
            <x-orchid-icon path="bs.exclamation-circle" class="mt-1 shrink-0"/>
            <div>
                @foreach ($errors->all() as $message)
                    <p class="mb-0">{{ $message }}</p>
                @endforeach
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('demo.register.store') }}" data-demo-register>
        @csrf

        <div class="mb-3">
            <label class="form-label" for="demo-name">{{ __('Full name') }}</label>
            <input id="demo-name" type="text" name="name" @class(['form-control', 'is-invalid' => $errors->has('name')]) value="{{ old('name') }}" required autofocus autocomplete="name" maxlength="255" placeholder="{{ __('Juan Dela Cruz') }}" @if ($errors->has('name')) aria-invalid="true" aria-describedby="demo-errors" @endif>
        </div>

        <div class="mb-3">
            <label class="form-label" for="demo-email">{{ __('Sign-in email') }}</label>
            <input id="demo-email" type="email" name="email" @class(['form-control', 'is-invalid' => $errors->has('email')]) value="{{ old('email') }}" required autocomplete="username" inputmode="email" maxlength="255" placeholder="juan.delacruz{{ '@'.$domain }}" aria-describedby="{{ $errors->has('email') ? 'demo-errors ' : '' }}demo-email-help" @if ($errors->has('email')) aria-invalid="true" @endif>
            <p class="form-text dts-forgot-hint" id="demo-email-help">{!! __('Must end in <strong>@:domain</strong>. You use it to sign in.', ['domain' => e($domain)]) !!}</p>
        </div>

        <div class="mb-3">
            <label class="form-label" for="demo-office">{{ __('Office') }}</label>
            {{-- Type to search (every office starts with "SDO-", so a plain dropdown cannot jump to one). --}}
            <dts-searchable>
                <input id="demo-office" name="office" type="text" @class(['form-control', 'is-invalid' => $errors->has('office')]) value="{{ old('office') }}" required
                       data-searchable data-options="demo-office-options" data-demo-office data-records-offices='@json($recordsOffices)'
                       role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="demo-office-choices" autocomplete="off"
                       placeholder="{{ __('Type to search, e.g. budget, legal, records') }}"
                       aria-describedby="{{ $errors->has('office') ? 'demo-errors ' : '' }}demo-office-help" @if ($errors->has('office')) aria-invalid="true" @endif>
                <div id="demo-office-choices" data-suggestions role="listbox" hidden></div>
            </dts-searchable>
            <p class="form-text dts-forgot-hint" id="demo-office-help">{{ __('Type part of the name, then pick your office from the list.') }}</p>
            <datalist id="demo-office-options">
                @foreach ($offices as $office)
                    <option value="{{ $office }}"></option>
                @endforeach
            </datalist>
        </div>

        <fieldset class="mb-3" aria-describedby="demo-role-help">
            <legend class="form-label mb-1">{{ __('Role') }}</legend>
            <p class="form-text dts-forgot-hint mt-0 mb-2" id="demo-role-help">{!! __('If you are <strong>not</strong> in the Records Unit, choose <strong>Personnel</strong>.') !!}</p>
            <div class="dts-demo-roles">
                @foreach ($roles as $slug => $label)
                    <label @class(['dts-demo-role', 'is-invalid' => $errors->has('role')])>
                        <input type="radio" name="role" value="{{ $slug }}" class="form-check-input" required @checked(old('role', 'personnel') === $slug) data-demo-role>
                        <span>
                            <strong>{{ $label }}</strong>
                            <small>{{ $slug === 'records-unit' ? __('Records Unit staff only: receive and release documents') : __('Every other office') }}</small>
                        </span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        <div class="mb-3">
            <label class="form-label" for="demo-recovery">{{ __('Gmail for password resets') }} <span class="dts-optional">{{ __('Optional') }}</span></label>
            <input id="demo-recovery" type="email" name="recovery_email" @class(['form-control', 'is-invalid' => $errors->has('recovery_email')]) value="{{ old('recovery_email') }}" autocomplete="email" inputmode="email" maxlength="255" placeholder="name@gmail.com" aria-describedby="{{ $errors->has('recovery_email') ? 'demo-errors ' : '' }}demo-recovery-help" @if ($errors->has('recovery_email')) aria-invalid="true" @endif>
            <p class="form-text dts-forgot-hint" id="demo-recovery-help">{{ __('If you forget your password, the reset link goes here. We email you a link to confirm it first; check Spam if it does not arrive.') }}</p>
        </div>

        @php($passwordInvalid = $errors->has('password'))
        <div class="mb-3">
            <label class="form-label" for="demo-password">{{ __('Password') }}</label>
            @include('platform::partials.password-input', [
                'id' => 'demo-password',
                'autocomplete' => 'new-password',
                'placeholder' => __('Choose a password'),
                'invalid' => $passwordInvalid,
                'ariaInvalid' => $passwordInvalid,
                'describedBy' => ($passwordInvalid ? 'demo-errors ' : '').'demo-password-help',
            ])
            <p class="form-text dts-forgot-hint" id="demo-password-help">{{ \App\Models\User::PASSWORD_HINT }}</p>
        </div>

        <div class="mb-3">
            <label class="form-label" for="demo-password-confirmation">{{ __('Type the password again') }}</label>
            @include('platform::partials.password-input', [
                'id' => 'demo-password-confirmation',
                'name' => 'password_confirmation',
                'autocomplete' => 'new-password',
                'placeholder' => __('Same password'),
                'invalid' => $passwordInvalid,
                'ariaInvalid' => $passwordInvalid,
            ])
        </div>

        <button type="submit" class="btn btn-default dts-auth-submit" data-busy-label="{{ __('Creating account…') }}">
            <x-orchid-icon path="bs.person-plus"/>
            <span data-button-label>{{ __('Create account') }}</span>
        </button>
    </form>

    <p class="dts-auth-help">
        <x-orchid-icon path="bs.box-arrow-in-right"/>
        <span>{{ __('Already have an account?') }} <a href="{{ route('platform.login') }}" class="d-inline-block py-1">{{ __('Sign in') }}</a></span>
    </p>
@endsection
