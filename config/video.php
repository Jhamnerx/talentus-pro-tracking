<?php

return [

    /*
     * Video JT1078 en tiempo real (HTTP-FLV y audio) servido directamente por el tracker.
     *
     * stream_url: URL pública que llega a `/api/stream/live` del tracker, normalmente un location de nginx
     *             en el mismo dominio de la web (p. ej. https://plataforma.com/tracker-media/stream/live).
     * stream_secret: igual a `media.streamSecret` en la configuración del tracker.
     *
     * Sin ambos valores el reproductor solo ofrece el modo compatible (HLS a través de Laravel).
     */
    'stream_url' => env('VIDEO_STREAM_URL'),

    // Protocolos del tracker con video JT1078. gt06 (JC400/JC261 en protocolo Jimi) usa RTMP y no está soportado.
    // Howen: el fork (rama talentus) lo implementará con el mismo contrato; cuando esté desplegado se activa
    // sin tocar código con VIDEO_PROTOCOLS=jt808,howen (y config:clear). No va activo por defecto.
    'protocols' => array_filter(array_map('trim', explode(',', env('VIDEO_PROTOCOLS', 'jt808')))),
    'stream_secret' => env('VIDEO_STREAM_SECRET'),

    // Orden de los códigos G.726 dentro de cada byte: 'rfc3551' (primer código en los bits bajos) o 'aal2' (altos).
    // No está documentado para las cámaras Jimi; si el audio G.726 se oye como ruido, probar el otro.
    'g726_packing' => env('VIDEO_G726_PACKING', 'rfc3551'),

    // Conversación bidireccional (JT1078 0x9101 tipo de dato 2). El canal lógico de audio no está documentado
    // para Jimi: el estándar reserva 33+ para audio y 36 es el habitual; si el equipo lo rechaza, probar 1.
    'talk_channel' => (int)env('VIDEO_TALK_CHANNEL', 36),
    // Cabecera HiSilicon de 4 bytes (00 01 largo/2 00) delante de cada trama de bajada, como la que envía el equipo.
    'talk_hisilicon_header' => (bool)env('VIDEO_TALK_HISILICON_HEADER', true),
    // Bits por muestra al hablar en G.726 si todavía no se recibió audio del equipo para deducirlos (5 = 40 kbit/s).
    'talk_g726_bits' => (int)env('VIDEO_TALK_G726_BITS', 5),

    // Vigencia del token firmado (s); solo se valida al conectar.
    'stream_token_ttl' => (int)env('VIDEO_STREAM_TOKEN_TTL', 600),

    // Descargas de grabaciones: disco y carpeta donde se guardan los archivos.
    'downloads_disk' => env('VIDEO_DOWNLOADS_DISK', 'local'),
    'downloads_path' => 'video-downloads',

    // Días que se conservan las descargas.
    'downloads_days' => (int)env('VIDEO_DOWNLOADS_DAYS', 7),

    // Cola propia: cada descarga dura lo mismo que la grabación y no debe bloquear la cola por defecto.
    'downloads_queue' => env('VIDEO_DOWNLOADS_QUEUE', 'video'),

    /*
     * Subida nativa por FTP (JT1078 0x9206). El equipo sube el archivo original de la grabación a este servidor;
     * es más rápido que grabar la reproducción. Sin habilitar, las descargas usan la reproducción.
     *
     * host: dirección pública del servidor FTP que se le indica al equipo.
     * root: carpeta local donde quedan los archivos de esa cuenta (home del usuario FTP).
     * Crear la cuenta con `php artisan video:ftp-user` (vsftpd, como las cámaras FTP de GPSWOX).
     */
    'ftp' => [
        'enabled'  => (bool)env('VIDEO_FTP_ENABLED', false),
        'host'     => env('VIDEO_FTP_HOST'),
        'port'     => (int)env('VIDEO_FTP_PORT', 21),
        'username' => env('VIDEO_FTP_USERNAME', 'videoupload'),
        'password' => env('VIDEO_FTP_PASSWORD'),
        'root'     => env('VIDEO_FTP_ROOT', storage_path('app/video-ftp')),
        // Minutos para que el equipo termine la subida (va por la red móvil).
        'timeout'  => (int)env('VIDEO_FTP_TIMEOUT', 60),
    ],

    // Binario para pasar el MPEG-TS a MP4 sin recodificar; si falla se entrega el .ts.
    'ffmpeg' => env('VIDEO_FFMPEG', 'ffmpeg'),
];
