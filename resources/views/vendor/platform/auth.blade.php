{{-- Override: a two-panel sign-in (office photo and seal beside the form) that stacks on small screens. --}}
@extends('platform::app')

@section('body')
    <div class="dts-auth">
        <aside class="dts-auth-hero" style="--dts-auth-photo: url('{{ asset('images/landing/lobby.jpg') }}')">
            <div class="dts-auth-hero__inner">
                <div class="dts-auth-hero__org">
                    <img src="{{ asset('images/SDO_NV.png') }}" alt="" width="64" height="64">
                    <span>Department of Education<br><strong>Schools Division Office of Nueva Vizcaya</strong></span>
                </div>

                <div>
                    <p class="dts-auth-hero__title">SDO Nueva Vizcaya Records Unit</p>
                    <p class="dts-auth-hero__lead">Receive, record and route the division's documents, and see where every paper is at a glance.</p>
                </div>
            </div>
        </aside>

        <main class="dts-auth-main">
            <div class="dts-auth-panel">
                <a class="dts-auth-brand" href="{{ Dashboard::prefix() }}">
                    @includeFirst([config('platform.template.header'), 'platform::header'])
                </a>

                <div class="dts-auth-card">
                    @yield('content')
                </div>

                @includeFirst([config('platform.template.footer'), 'platform::footer'])
            </div>
        </main>
    </div>
@endsection
