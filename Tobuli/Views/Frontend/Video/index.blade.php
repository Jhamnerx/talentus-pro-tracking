<!DOCTYPE html>
<html lang="{{ Language::iso() }}">
<head>
    @include('Frontend.Layouts.partials.head')
    @yield('styles')
    <style>
        .video-container {
            width: 100%;
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 10px;
            padding: 20px;
        }

        .video-wrapper {
            position: relative;
            width: 300px;
            height: 210px;
            display: none;
        }

        .video-wrapper.active {
            display: block;
        }

        .video-element {
            width: 100%;
            height: 100%;
            background: #000;
        }

        .loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #3498db;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: translate(-50%, -50%) rotate(0deg); }
            100% { transform: translate(-50%, -50%) rotate(360deg); }
        }

        .error-message {
            background-color: #ff5757;
            color: white;
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
            text-align: center;
            width: 100%;
            display: none;
        }
    </style>
</head>
<body style="background: #fff">

<div class="video-container">
    <div class="video-wrapper" id="wrapper1">
        <video id="videoElement1" class="video-element" controls muted></video>
    </div>
    <div class="video-wrapper" id="wrapper2">
        <video id="videoElement2" class="video-element" controls muted></video>
    </div>
    <div class="video-wrapper" id="wrapper3">
        <video id="videoElement3" class="video-element" controls muted></video>
    </div>
    <div class="video-wrapper" id="wrapper4">
        <video id="videoElement4" class="video-element" controls muted></video>
    </div>
    
    <div id="media_error" class="error-message">
        NO SE PUDO REPRODUCIR EL VIDEO, VERIFIQUE QUE EL DISPOSITIVO ESTÉ EN LÍNEA Y LA CÁMARA CORRECTAMENTE CONECTADA.
    </div>
</div>

@yield('self-scripts')

<script src="{{ asset_resource('assets/js/core.js') }}"></script>
<script src="{{ asset_resource('assets/js/app.js') }}"></script>

@include('Frontend.Layouts.partials.trans')
@include('Frontend.Layouts.partials.app')

<script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
<script src="https://cdn.jsdelivr.net/npm/flv.js@1.6.2/dist/flv.min.js"></script>
<script src="{{ asset_resource('assets/js/video-player.js') }}"></script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    VideoPlayer.init('{{ $item->id }}');
});
</script>

</body>
</html>
