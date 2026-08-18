@extends('Frontend.Layouts.frontend')

@section('styles')
    {{-- Restyle moderno del login (paleta ui/v2). Solo CSS: el marcado, el
         white-label (Appearance) y los hooks del form no cambian; los inline
         styles de Appearance (color/transparencia del panel) siguen ganando. --}}
    <link rel="preconnect" href="https://rsms.me/" crossorigin>
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    <link rel="stylesheet" href="{{ asset('assets/ui/v2/css/auth.css') }}?v=1">
@endsection

@section('content')
    @if (Appearance::getSetting('welcome_text'))
        <h1 class="sign-in-text text-center">
            {!! Appearance::getSetting('welcome_text') !!}
        </h1>
    @endif

    <div class="panel">
        <div class="panel-background"></div>
        <div class="panel-body">

            @if (Appearance::assetFileExists('logo-main'))
                <a href="{{ route('home') }}">
                    <img class="img-responsive center-block" src="{{ Appearance::getAssetFileUrl('logo-main') }}"
                        alt="Logo">
                </a>
            @endif

            <hr>

            @if (Session::has('success'))
                <div class="alert alert-success alert-dismissible">
                    {!! Session::get('success') !!}
                </div>
            @endif

            @if (Session::has('message'))
                <div class="alert alert-danger alert-dismissible">
                    {!! Session::get('message') !!}
                </div>
            @endif

            @includeWhen(count($internalAuths), 'front::Login.partials.internal', $internalAuths)

            @if (count($externalAuths))
                <div class="row justify-content-center">
                    <h3 class="text-center">{{ trans('front.external_login') }}</h3>

                    @foreach ($externalAuths as $auth)
                        <div class="col-md-4">
                            <div class="form-group">
                                @include("front::Login.partials.{$auth->getKey()}")
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    @if (Appearance::getSetting('google_play_link') || Appearance::getSetting('apple_store_link'))
        <div class="app-links">
            @if (Appearance::getSetting('google_play_link'))
                <div class="app-google">
                    <a href="{{ Appearance::getSetting('google_play_link') }}" target="_blank">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M3.6 1.8 13.7 12 3.6 22.2c-.4-.2-.6-.6-.6-1.2V3c0-.6.2-1 .6-1.2zM15 13.3l2.6 2.6-11 6.3 8.4-8.9zm3.9-4.1 2.5 1.4c.8.5.8 1.7 0 2.2l-2.5 1.4-2.9-2.5 2.9-2.5zM6.6 1.8l11 6.3L15 10.7 6.6 1.8z"/>
                        </svg>
                        <span>
                            <small style="font-size:0.7em;font-weight:400;display:block">DESCARGA AQUI</small>
                            Google Play
                        </span>
                    </a>
                </div>
            @endif
            @if (Appearance::getSetting('apple_store_link'))
                <div class="app-apple">
                    <a href="{{ Appearance::getSetting('apple_store_link') }}" target="_blank">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M16.7 12.9c0-2.4 2-3.6 2.1-3.7-1.1-1.7-2.9-1.9-3.5-1.9-1.5-.2-2.9.9-3.7.9-.8 0-1.9-.9-3.2-.9-1.6 0-3.1 1-4 2.4-1.7 2.9-.4 7.3 1.2 9.7.8 1.2 1.8 2.5 3.1 2.4 1.2-.1 1.7-.8 3.2-.8s1.9.8 3.2.8c1.3 0 2.2-1.2 3-2.4.9-1.4 1.3-2.7 1.3-2.8-.1 0-2.6-1-2.7-3.7zM14.4 5.6c.7-.8 1.1-1.9 1-3.1-1 0-2.2.7-2.9 1.5-.6.7-1.2 1.9-1 3 1.1.1 2.2-.6 2.9-1.4z"/>
                        </svg>
                        <span>
                            <small style="font-size:0.7em;font-weight:400;display:block">DESCARGA AQUI</small>
                            App Store
                        </span>
                    </a>
                </div>
            @endif
        </div>
    @endif

    @if (Appearance::getSetting('bottom_text'))
        <p class="sign-in-text">{!! Appearance::getSetting('bottom_text') !!}</p>
    @endif
@stop
