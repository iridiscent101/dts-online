@php
    $profilePresenter = Auth::user()->presenter();
@endphp

<dts-profile-menu class="profile-container d-flex align-items-stretch p-3 rounded lh-sm position-relative">
    <button type="button"
            class="dts-profile-trigger d-flex align-items-center gap-3"
            data-profile-menu-toggle
            aria-expanded="false"
            aria-controls="profile-menu">
        @if ($image = $profilePresenter->image())
            <img src="{{ $image }}" alt="{{ $profilePresenter->title() }}" class="thumb-sm avatar b" type="image/*">
        @endif

        <small class="d-flex flex-column lh-1">
            <span class="text-ellipsis text-white">{{ $profilePresenter->title() }}</span>
            <span class="text-ellipsis text-muted">{{ $profilePresenter->subTitle() }}</span>
        </small>
    </button>

    <x-orchid-notification/>

    <div class="dts-profile-menu" id="profile-menu" data-profile-menu-panel hidden>
        <a href="{{ route('platform.profile') }}" data-profile-menu-link>
            <x-orchid-icon path="bs.gear" />
            <span>Settings</span>
        </a>

        <a href="{{ route('tracking.index') }}" data-profile-menu-link>
            <x-orchid-icon path="bs.house" />
            <span>Fast Track page</span>
        </a>

        <form method="POST" action="{{ route('platform.logout') }}" data-profile-menu-link>
            @csrf
            <button type="submit">
                <x-orchid-icon path="bs.box-arrow-right" />
                <span>Logout</span>
            </button>
        </form>
    </div>
</dts-profile-menu>

{{-- Inactivity sign-out (dts.js IdleSignOut): warns two minutes before the server's idle limit, then signs out. --}}
<dts-idle-sign-out
    data-idle-seconds="{{ \App\Http\Middleware\LogoutInactiveStaff::idleSeconds() }}"
    data-keep-alive-url="{{ route('platform.session.keep-alive') }}"
    data-sign-out-url="{{ route('platform.session.idle-sign-out') }}"
    data-login-url="{{ route('platform.login') }}"
    hidden></dts-idle-sign-out>
