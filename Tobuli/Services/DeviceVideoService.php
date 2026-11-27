<?php

namespace Tobuli\Services;

use Illuminate\Support\Facades\Redis;
use Tobuli\Entities\Device;
use Tobuli\Helpers\Tracker;

/**
 * Video en vivo JT1078 a través del tracker (fork de traccar).
 *
 * Cada ventana del reproductor es un "viewer". El comando videoStart se envía al abrir
 * y videoStop solo cuando se cierra el último viewer del canal, para no cortar
 * el video a otro usuario que esté mirando el mismo canal.
 */
class DeviceVideoService
{
    const STOP_COMMANDS = ['videoStop', 'videoPlaybackControl', 'videoFileUploadControl'];

    /** Un viewer sin señales durante este tiempo se considera cerrado (s). */
    const VIEWER_TTL = 120;

    const TYPE_LIVE = 'live';
    const TYPE_HISTORY = 'history';

    /** Acciones de videoPlaybackControl (JT/T 1078 0x9202). */
    const PLAYBACK_PLAY = 0;
    const PLAYBACK_PAUSE = 1;
    const PLAYBACK_STOP = 2;

    /** @var Tracker */
    private $tracker;

    public function __construct()
    {
        $this->tracker = new Tracker();
    }

    public function channels(Device $device): array
    {
        if ( ! $device->has_camera)
            return [];

        return array_values(array_map('intval', (array)$device->camera_channels));
    }

    public function hasChannel(Device $device, int $channel): bool
    {
        return in_array($channel, $this->channels($device));
    }

    /**
     * @param bool $audio pedir audio y video (JT1078 tipo de dato 0) en vez de solo video
     * @param bool $subStream flujo secundario (SD), más liviano para la cuadrícula o redes lentas
     */
    public function start(Device $device, int $channel, string $viewer, bool $audio = false, bool $subStream = false): array
    {
        $this->touchViewer($device, $channel, $viewer);

        return $this->command($device, 'videoStart', [
            'index'      => $channel,
            'audio'      => $audio,
            'streamType' => $subStream ? 1 : 0,
        ]);
    }

    /**
     * URLs firmadas para el modo tiempo real (FLV) y el audio, o null si no está configurado.
     */
    public function realtimeUrls(Device $device, int $channel): ?array
    {
        $base = rtrim((string)config('video.stream_url'), '/');
        $secret = (string)config('video.stream_secret');

        if ( ! $base || ! $secret)
            return null;

        $expires = time() + config('video.stream_token_ttl', 600);
        $token = $expires . '.' . hash_hmac('sha256', "{$device->imei}:{$channel}:{$expires}", $secret);
        $path = $base . '/' . rawurlencode($device->imei) . '/' . $channel;

        return [
            'flv'     => $path . '/live.flv?token=' . $token,
            'audio'   => $path . '/live.audio?token=' . $token,
            'talk'    => $path . '/talk?token=' . $token,
            'expires' => $expires,
        ];
    }

    /** Un operador habla con el equipo a la vez; el bloqueo se renueva con talkPing. */
    const TALK_LOCK_TTL = 30;

    public function talkChannel(): int
    {
        return (int)config('video.talk_channel', 36);
    }

    /**
     * Abre la conversación: el equipo conecta su canal de audio y envía el micrófono de la cabina.
     */
    public function talkStart(Device $device, string $viewer): array
    {
        $redis = Redis::connection('process');
        $key = $this->talkKey($device);

        $owner = $redis->get($key);
        if ($owner && $owner !== $viewer)
            return ['ok' => false, 'error' => trans('front.video_talk_busy')];

        $redis->setex($key, self::TALK_LOCK_TTL, $viewer);

        $result = $this->command($device, 'videoStart', [
            'index'    => $this->talkChannel(),
            'dataType' => 2,
        ]);

        if ( ! $result['ok'])
            $redis->del($key);

        return $result;
    }

    public function talkPing(Device $device, string $viewer): bool
    {
        $redis = Redis::connection('process');
        $key = $this->talkKey($device);

        if ($redis->get($key) !== $viewer)
            return false;

        $redis->expire($key, self::TALK_LOCK_TTL);

        return true;
    }

    public function talkStop(Device $device, string $viewer): bool
    {
        $redis = Redis::connection('process');
        $key = $this->talkKey($device);

        $owner = $redis->get($key);
        if ($owner && $owner !== $viewer)
            return false;

        $redis->del($key);
        $this->command($device, 'videoStop', [
            'index'   => $this->talkChannel(),
            'control' => 4,
        ]);

        return true;
    }

    private function talkKey(Device $device): string
    {
        return "video.talk.{$device->id}";
    }

    /**
     * Mantiene vivo al viewer mientras el reproductor sigue abierto.
     */
    public function touchViewer(Device $device, int $channel, string $viewer): void
    {
        $key = $this->viewersKey($device, $channel);

        Redis::connection('process')->zadd($key, time(), $viewer);
        Redis::connection('process')->expire($key, self::VIEWER_TTL * 2);
    }

    /**
     * @return bool true si se envió videoStop (no quedan viewers).
     */
    public function stop(Device $device, int $channel, ?string $viewer): bool
    {
        $redis = Redis::connection('process');
        $key = $this->viewersKey($device, $channel);

        if ($viewer)
            $redis->zrem($key, $viewer);

        $redis->zremrangebyscore($key, '-inf', time() - self::VIEWER_TTL);

        if ($redis->zcard($key) > 0)
            return false;

        $redis->del($key);
        $this->command($device, 'videoStop', ['index' => $channel]);

        return true;
    }

    /**
     * Playlist (.m3u8) o segmento (.ts) del canal, servido por el tracker.
     *
     * @return \Illuminate\Http\Client\Response
     */
    public function streamFile(Device $device, int $channel, string $file)
    {
        return $this->tracker->get("api/stream/imei/{$device->imei}/{$channel}/{$file}");
    }

    /**
     * Estado del stream en el tracker: connected, codec, lastFrameTime, segments, rejectedTime (ms).
     */
    public function status(Device $device, int $channel, string $type = self::TYPE_LIVE): ?array
    {
        $response = $this->tracker->get("api/stream/imei/{$device->imei}/{$channel}/status?type={$type}");

        return $response->successful() ? $response->json() : null;
    }

    /**
     * Pide al equipo su lista de grabaciones (0x9205). Canal 0 = todos.
     * Devuelve el instante del pedido (ms) para reconocer la respuesta nueva.
     *
     * @param string $from instante UTC ISO-8601
     * @param string $to instante UTC ISO-8601
     */
    public function queryRecordings(Device $device, int $channel, string $from, string $to): array
    {
        return $this->command($device, 'videoResourceQuery', [
            'index' => $channel,
            'from'  => $from,
            'to'    => $to,
        ]);
    }

    /**
     * Última lista de grabaciones recibida: fresh si llegó después de $seq, nak si el equipo rechazó la consulta.
     */
    public function recordings(Device $device, int $seq): array
    {
        $response = $this->tracker->get("api/stream/imei/{$device->imei}/resources");

        if ( ! $response->successful())
            return ['ok' => false, 'error' => trans('front.video_err_platform')];

        $data = $response->json();

        return [
            'ok'    => true,
            'fresh' => ! empty($data['time']) && $data['time'] >= $seq,
            'nak'   => ! empty($data['rejectedTime']) && $data['rejectedTime'] >= $seq,
            'items' => (array)($data['items'] ?? []),
        ];
    }

    /** Tipos de carga JT/T 1078 (tabla 12) más comunes en cámaras. */
    const CODECS = [
        6  => 'G.711A',
        7  => 'G.711U',
        8  => 'G.726',
        19 => 'AAC',
        24 => 'AAC-LC',
        25 => 'MP3',
        26 => 'ADPCMA',
        28 => 'AMR',
        98 => 'H.264',
        99 => 'H.265',
    ];

    public static function codecName(?int $payloadType): ?string
    {
        if ($payloadType === null)
            return null;

        return self::CODECS[$payloadType] ?? "#{$payloadType}";
    }

    /**
     * Pide al equipo sus capacidades de audio/video (0x9003); la respuesta 0x1003 llega de forma asíncrona.
     */
    public function queryAttributes(Device $device): array
    {
        return $this->command($device, 'videoAttributes', []);
    }

    /**
     * Últimas capacidades recibidas; si son posteriores a $seq se guardan en el equipo.
     */
    public function attributes(Device $device, int $seq): array
    {
        $response = $this->tracker->get("api/stream/imei/{$device->imei}/attributes");

        if ( ! $response->successful())
            return ['ok' => false, 'error' => trans('front.video_err_platform')];

        $data = $response->json();
        $fresh = ! empty($data['time']) && $data['time'] >= $seq && ! empty($data['attributes']);

        if ($fresh) {
            $device->forceFill(['camera_attributes' => $data['attributes']])->save();
        }

        return [
            'ok'         => true,
            'fresh'      => $fresh,
            'nak'        => ! empty($data['rejectedTime']) && $data['rejectedTime'] >= $seq,
            'attributes' => $fresh ? $this->describeAttributes($data['attributes']) : null,
        ];
    }

    /**
     * Capacidades guardadas, con los nombres de los códecs para mostrarlas.
     */
    public function describeAttributes(?array $attributes): ?array
    {
        if (empty($attributes))
            return null;

        $audioCodec = self::codecName($attributes['audioEncoding'] ?? null);
        $videoCodec = self::codecName($attributes['videoEncoding'] ?? null);

        $summary = trans('front.video_attributes_summary', [
            'audio'    => $audioCodec ?: '-',
            'rate'     => $attributes['audioSampleRate'] ?? '-',
            'frame'    => $attributes['audioFrameLength'] ?? '-',
            'speaker'  => ! empty($attributes['audioOutput']) ? trans('global.yes') : trans('global.no'),
            'video'    => $videoCodec ?: '-',
            'channels' => $attributes['maxVideoChannels'] ?? '-',
        ]);

        return $attributes + [
            'audioCodec' => $audioCodec,
            'videoCodec' => $videoCodec,
            'summary'    => $summary,
        ];
    }

    /** Acciones de videoFileUploadControl (JT/T 1078 0x9207). */
    const UPLOAD_PAUSE = 0;
    const UPLOAD_CONTINUE = 1;
    const UPLOAD_CANCEL = 2;

    public static function ftpEnabled(): bool
    {
        return config('video.ftp.enabled') && config('video.ftp.host') && config('video.ftp.password');
    }

    /**
     * Pide al equipo que suba por FTP la grabación del rango (0x9206). La carpeta identifica la tarea.
     */
    public function ftpUploadStart(Device $device, int $channel, string $from, string $to, string $path): array
    {
        return $this->command($device, 'videoFileUpload', [
            'server'      => config('video.ftp.host'),
            'port'        => (int)config('video.ftp.port', 21),
            'ftpUser'     => config('video.ftp.username'),
            'ftpPassword' => config('video.ftp.password'),
            'ftpPath'     => $path,
            'index'       => $channel,
            'from'        => $from,
            'to'          => $to,
        ]);
    }

    /**
     * Tarea de subida con esa carpeta según el tracker: serial, result (null en curso, 0 éxito, 1 falla).
     */
    public function ftpUpload(Device $device, string $path): ?array
    {
        $response = $this->tracker->get("api/stream/imei/{$device->imei}/uploads");

        if ( ! $response->successful())
            return null;

        return collect((array)$response->json())->where('path', $path)->sortByDesc('requestTime')->first();
    }

    public function ftpUploadControl(Device $device, int $serial, int $action): array
    {
        return $this->command($device, 'videoFileUploadControl', [
            'serial' => $serial,
            'action' => $action,
        ]);
    }

    public function playbackStart(Device $device, int $channel, string $from, string $to): array
    {
        return $this->command($device, 'videoPlayback', [
            'index' => $channel,
            'from'  => $from,
            'to'    => $to,
            'speed' => 0,
        ]);
    }

    public function playbackControl(Device $device, int $channel, int $action): array
    {
        return $this->command($device, 'videoPlaybackControl', [
            'index'  => $channel,
            'action' => $action,
        ]);
    }

    /**
     * @return array ok, error y seq (ms en que se envió; sirve para reconocer respuestas/rechazos posteriores)
     */
    private function command(Device $device, string $type, array $attributes): array
    {
        // Margen para pequeñas diferencias de reloj entre la web y el tracker.
        $seq = (int)round(microtime(true) * 1000) - 2000;

        if ( ! $device->supportsVideo())
            return ['ok' => false, 'error' => $this->protocolError($device->protocol), 'seq' => $seq];

        // Un equipo desconectado no puede transmitir: se avisa en vez de esperar el timeout. Detener sí se permite.
        if ( ! in_array($type, self::STOP_COMMANDS) && $device->getStatus() === Device::STATUS_OFFLINE)
            return ['ok' => false, 'error' => trans('front.video_err_offline'), 'seq' => $seq];

        $result = $this->tracker->sendCommand([
            'uniqueId'   => $device->imei,
            'type'       => $type,
            // Sin atributos (p. ej. videoAttributes) json_encode daría [] y traccar espera un objeto {}.
            'attributes' => $attributes ?: new \stdClass(),
        ]);

        return [
            'ok'    => (bool)$result['status'],
            'error' => $result['status'] ? null : $this->trackerError($result['message']),
            'seq'   => $seq,
        ];
    }

    private function protocolError(?string $protocol): string
    {
        return trans('front.video_protocol_unsupported', ['protocol' => $protocol ?: '-']);
    }

    /**
     * El tracker devuelve la excepción Java completa ("Failed (java.lang...: mensaje at org.traccar...)"):
     * se deja solo el mensaje, y el caso de protocolo sin video se traduce.
     */
    private function trackerError(?string $message): string
    {
        if ( ! $message)
            return trans('front.video_err_platform');

        if (preg_match('/not supported in protocol (\w+)/', $message, $match))
            return $this->protocolError($match[1]);

        $message = preg_replace('/^Failed \(/', '', $message);
        $message = preg_split('/\s+at\s+[\w$.]+\(/', $message)[0];
        $message = preg_replace('/^[\w.]+(Exception|Error):\s*/', '', trim($message, " )"));

        return mb_substr($message, 0, 200) ?: trans('front.video_err_platform');
    }

    private function viewersKey(Device $device, int $channel): string
    {
        return "video.viewers.{$device->id}.{$channel}";
    }
}
