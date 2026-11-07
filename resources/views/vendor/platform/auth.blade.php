{{-- Override: a two-panel sign-in (office photo and seal beside the form) that stacks on small screens. --}}
@extends('platform::app')

@section('body')
    {{-- Marks the page as script-capable before the panel first paints. mesh-drift.js is a module, so it only runs
         after the first paint: without this the lobby photo shows for a moment and is then swapped for the shader.
         The script clears the flag on any path where it gives up, and the photo comes back. --}}
    <script nonce="{{ Vite::cspNonce() }}">document.documentElement.classList.add('dts-mesh-js');</script>

    <div class="dts-auth">
        {{-- The shader runs on every auth page. The photo stays the fallback: mesh-drift.js adds .is-mesh over it
             only once it has a WebGL canvas, so no script or no WebGL still gives the lobby photo. --}}
        <aside class="dts-auth-hero" data-mesh-drift style="--dts-auth-photo: url('{{ asset('images/landing/lobby.jpg') }}'); --dts-auth-seal: url('{{ asset('images/SDO_NV.png') }}')">
            <div class="dts-auth-hero__inner">
                <p class="dts-auth-hero__org">
                    <strong>Schools Division Office of Nueva Vizcaya</strong>
                    <span>Department of Education</span>
                </p>

                <div>
                    <p class="dts-auth-hero__title">SDO Nueva Vizcaya Records Unit</p>
                    <p class="dts-auth-hero__lead">Document registration, routing and tracking.</p>
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
