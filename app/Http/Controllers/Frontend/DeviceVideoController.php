<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use CustomFacades\Repositories\DeviceRepo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Tobuli\Exceptions\ValidationException;
use Tobuli\Services\DeviceVideoService;

class DeviceVideoController extends Controller
{
    /** @var DeviceVideoService */
    private $videoService;

    public function __construct()
    {
        parent::__construct();

        $this->videoService = new DeviceVideoService();
    }

    // =========================================================================
    // Helpers internos
    // =========================================================================

    private function findDevice(int $deviceId)
    {
        $device = DeviceRepo::find($deviceId);

        if (! $device) {
            abort(404);
        }

        if (! $this->user->can('show', $device)) {
            abort(403);
        }

        return $device;
    }

    private function json($data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status);
    }

    // =========================================================================
    // Capacidades del dispositivo
    // =========================================================================

    /**
     * GET /device_video/{device_id}/capabilities
     *
     * Retorna si el protocolo del dispositivo soporta video y quÃ©
     * funcionalidades estÃ¡n disponibles. Usar para adaptar la UI.
     *
     * Response:
     * {
     *   "supported": true,
     *   "protocol":  "howen",
     *   "features": {
     *     "live":      true,
     *     "snapshot":  true,
     *     "audio":     true,
     *     "files":     true,
     *     "playback":  true
     *   }
     * }
     */
    public function capabilities(int $deviceId): JsonResponse
    {
        $device = $this->findDevice($deviceId);

        $protocol  = $device->protocol ?? null;
        $supported = $this->videoService->isSupported($device);

        // Capacidades por protocolo conocido
        $features = [
            'live'      => false,
            'snapshot'  => false,
            'audio'     => false,
            'files'     => false,
            'playback'  => false,
            'recording' => false,
        ];

        if ($supported) {
            switch (strtolower($protocol)) {
                case 'howen':
                    $features = array_fill_keys(array_keys($features), true);
                    break;
                case 'jt1078':
                    $features['live'] = true;
                    break;
            }
        }

        return $this->json([
            'supported' => $supported,
            'protocol'  => $protocol,
            'features'  => $features,
        ]);
    }

    // =========================================================================
    // Estado de sesiÃ³n
    // =========================================================================

    /**
     * GET /device_video/{device_id}/status
     *
     * Howen: retorna { connected, lastSeen }
     * JT1078: retorna { connected, hls_url }
     */
    public function status(int $deviceId): JsonResponse
    {
        $device = $this->findDevice($deviceId);

        return $this->json($this->videoService->status($device));
    }

    // =========================================================================
    // Live View
    // =========================================================================

    /**
     * POST /device_video/{device_id}/live/start
     *
     * Body:
     *   channel      int     1-8             (default 1)
     *   stream       int     1=main, 2=sub   (default 1)
     *   mediaServer  string  IP:port         (Howen, opcional)
     *   frameList    string  "1;2;3"         (Howen, opcional)
     *
     * Response incluye hls_url (ambos protocolos) y session (Howen).
     */
    public function liveStart(int $deviceId): JsonResponse
    {
        $device = $this->findDevice($deviceId);

        $v = Validator::make($this->data, [
            'channel'     => 'integer|min:1|max:8',
            'stream'      => 'integer|in:1,2',
            'mediaServer' => 'nullable|string|max:255',
            'frameList'   => 'nullable|string|max:64',
        ]);

        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }

        $channel = (int) ($this->data['channel'] ?? 1);
        $stream  = (int) ($this->data['stream']  ?? 1);
        $extra   = array_filter([
            'mediaServer' => $this->data['mediaServer'] ?? null,
            'frameList'   => $this->data['frameList']   ?? null,
        ]);

        return $this->json($this->videoService->startLive($device, $channel, $stream, $extra));
    }

    /**
     * POST /device_video/{device_id}/live/stop
     *
     * Body: channel (int, 1-8), stream (int, 1-2)
     */
    public function liveStop(int $deviceId): JsonResponse
    {
        $device  = $this->findDevice($deviceId);
        $channel = (int) ($this->data['channel'] ?? 1);
        $stream  = (int) ($this->data['stream']  ?? 1);

        return $this->json($this->videoService->stopLive($device, $channel, $stream));
    }

    // =========================================================================
    // Snapshot
    // =========================================================================

    /**
     * POST /device_video/{device_id}/snapshot
     *
     * Body:
     *   channels  string  canal Ãºnico "1" o lista separada por semicolon "1;2;3"
     *   session   string  ID de sesiÃ³n opcional (Howen)
     */
    public function snapshot(int $deviceId): JsonResponse
    {
        $device  = $this->findDevice($deviceId);

        return $this->json($this->videoService->snapshot(
            $device,
            (string) ($this->data['channels'] ?? $this->data['channel'] ?? '1'),
            $this->data['session'] ?? null
        ));
    }

    // =========================================================================
    // Audio bidireccional
    // =========================================================================

    /**
     * POST /device_video/{device_id}/audio/start
     *
     * Body:
     *   channel      int     1-8              (default 1)
     *   mode         int     0=escucha, 1=intercomunicador, 2=difusiÃ³n (default 0)
     *   mediaServer  string  IP:port (Howen, opcional)
     */
    public function audioStart(int $deviceId): JsonResponse
    {
        $device      = $this->findDevice($deviceId);
        $channel     = (int) ($this->data['channel']     ?? 1);
        $mode        = (int) ($this->data['mode']        ?? 0);
        $mediaServer = $this->data['mediaServer'] ?? null;

        return $this->json($this->videoService->startAudio($device, $channel, $mode, $mediaServer));
    }

    /**
     * POST /device_video/{device_id}/audio/stop
     *
     * Body: channel (int)
     */
    public function audioStop(int $deviceId): JsonResponse
    {
        $device  = $this->findDevice($deviceId);
        $channel = (int) ($this->data['channel'] ?? 1);

        return $this->json($this->videoService->stopAudio($device, $channel));
    }

    // =========================================================================
    // BÃºsqueda de archivos grabados (Howen)
    // =========================================================================

    /**
     * POST /device_video/{device_id}/files/search
     *
     * Body (nombres exactos del H-Protocol):
     *   channels   string   "1" o "1;2"                      (requerido)
     *   startTime  string   "2024-01-01 00:00:00"             (requerido)
     *   endTime    string   "2024-01-02 00:00:00"             (requerido)
     *   fileType   string   "1"=normal, ver spec Â§3.4         (requerido)
     *   storage    string   "sd1;hd1"                         (opcional)
     *   stream     string   "0"=sub-stream / "1"=main-stream  (opcional)
     *
     * Response: { "session": "search_<id>" }
     */
    public function filesSearch(int $deviceId): JsonResponse
    {
        $device = $this->findDevice($deviceId);

        $v = Validator::make($this->data, [
            'channels'  => 'required|string',
            'startTime' => 'required|date',
            'endTime'   => 'required|date|after:startTime',
            'fileType'  => 'required|string',
            'storage'   => 'nullable|string',
            'stream'    => 'nullable|string|in:0,1',
        ]);

        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }

        return $this->json($this->videoService->searchFiles($device, $this->data));
    }

    /**
     * GET /device_video/{device_id}/files/{session}
     *
     * Polling: retorna los resultados disponibles de la bÃºsqueda y los elimina.
     * Response: { "files": [ {...}, ... ] }
     */
    public function filesPoll(int $deviceId, string $session): JsonResponse
    {
        $device = $this->findDevice($deviceId);

        return $this->json($this->videoService->pollFileSearch($device, $session));
    }

    // =========================================================================
    // ReproducciÃ³n remota (Howen)
    // =========================================================================

    /**
     * POST /device_video/{device_id}/playback/start
     *
     * Howen params: channels, startTime, endTime, mediaServer, fileName, act, frameList, stream
     * JT1078 params: channel, startTime, endTime, avType, streamType, storageType, playbackMode, multiplier, mediaServer
     *
     * Response: { "session": "pb_<id>" } (Howen) | { "mediaServer": "ip:port" } (JT1078)
     */
    public function playbackStart(int $deviceId): JsonResponse
    {
        $device = $this->findDevice($deviceId);

        $v = Validator::make($this->data, [
            'startTime' => 'nullable|date',
            'endTime'   => 'nullable|date',
        ]);

        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }

        return $this->json($this->videoService->startPlayback($device, $this->data));
    }

    /**
     * POST /device_video/{device_id}/playback/control
     *
     * JT1078 playback control (0x9202).
     *
     * Body:
     *   channel    int   (default 1)
     *   control    int   0=start, 1=pause, 2=end, 3=FF, 4=rewind, 5=seek, 6=key-frame (default 2)
     *   multiplier int   FF/rewind speed (default 0)
     *   seekTime   string "yyyy-MM-dd HH:mm:ss" (required when control=5)
     */
    public function playbackControl(int $deviceId): JsonResponse
    {
        $device = $this->findDevice($deviceId);
        return $this->json($this->videoService->controlPlayback($device, $this->data));
    }

    // =========================================================================
    // Video de evidencia de eventos
    // =========================================================================

    /**
     * POST /device_video/{device_id}/event-clip
     *
     * Inicia búsqueda de archivos grabados alrededor de un evento.
     * Pollea GET /device_video/{id}/files/{session} hasta obtener la lista.
     * Luego usa POST /device_video/{id}/playback/start con el fileName elegido.
     *
     * Body:
     *   event_time      int     Unix timestamp del evento                      (requerido)
     *   channels        string  "1" o "1;2;3"                                  (default "1")
     *   seconds_before  int     Segundos antes del evento                      (default 30)
     *   seconds_after   int     Segundos después del evento                    (default 30)
     *   file_type       string  "1"=normal, "2"=alarma (Howen spec §3.4)       (default "2")
     *   storage         string  "sd1;hd1" (opcional)
     *   stream          string  "0"=sub / "1"=main (opcional)
     *
     * Response: { "session": "search_<id>", "startTime": "...", "endTime": "..." }
     */
    public function eventClip(int $deviceId): JsonResponse
    {
        $device = $this->findDevice($deviceId);

        $v = Validator::make($this->data, [
            'event_time'     => 'required|integer|min:1',
            'channels'       => 'nullable|string',
            'seconds_before' => 'nullable|integer|min:0|max:300',
            'seconds_after'  => 'nullable|integer|min:0|max:300',
            'file_type'      => 'nullable|string',
            'storage'        => 'nullable|string',
            'stream'         => 'nullable|string|in:0,1',
        ]);

        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }

        $extra = array_filter([
            'storage' => $this->data['storage'] ?? null,
            'stream'  => $this->data['stream']  ?? null,
        ]);

        $result = $this->videoService->eventClip(
            $device,
            (int) $this->data['event_time'],
            (string) ($this->data['channels']       ?? '1'),
            (int)    ($this->data['seconds_before'] ?? 30),
            (int)    ($this->data['seconds_after']  ?? 30),
            (string) ($this->data['file_type']      ?? '2'),
            $extra
        );

        // Añadir el rango de tiempo calculado a la respuesta para que el
        // cliente sepa exactamente qué ventana se consultó
        $result['startTime'] = date('Y-m-d H:i:s', $this->data['event_time'] - ($this->data['seconds_before'] ?? 30));
        $result['endTime']   = date('Y-m-d H:i:s', $this->data['event_time'] + ($this->data['seconds_after']  ?? 30));

        return $this->json($result);
    }

    // =========================================================================
    // Grabación manual (Howen 0x4040)
    // =========================================================================

    /**
     * POST /device_video/{device_id}/recording/start
     *
     * Activa grabación manual en la SD del dispositivo.
     * El dispositivo comienza a escribir un archivo nuevo de inmediato.
     * Para detener usa recording/stop y luego busca el archivo con files/search.
     *
     * Body: channel (int, 1-8, default 1)
     *
     * Response: { "session": "rec_<id>" }
     */
    public function recordingStart(int $deviceId): JsonResponse
    {
        $device  = $this->findDevice($deviceId);
        $channel = (int) ($this->data['channel'] ?? 1);

        return $this->json($this->videoService->startRecording($device, $channel));
    }

    /**
     * POST /device_video/{device_id}/recording/stop
     *
     * Detiene la grabación manual en curso.
     * El archivo queda cerrado en la SD y puede recuperarse con files/search.
     *
     * Body: channel (int, 1-8, default 1)
     */
    public function recordingStop(int $deviceId): JsonResponse
    {
        $device  = $this->findDevice($deviceId);
        $channel = (int) ($this->data['channel'] ?? 1);

        return $this->json($this->videoService->stopRecording($device, $channel));
    }

    // =========================================================================
    // Búsqueda y reproducción de grabaciones — página reproductora
    // =========================================================================

    /**
     * GET /device_video/playback
     *
     * Página de búsqueda y reproducción de grabaciones (filesSearch + playbackStart).
     * Muestra todos los dispositivos del usuario con protocolo de video soportado.
     */
    public function playbackPage()
    {
        $devices = DeviceRepo::whereUserId($this->user->id)
            ->filter(function ($d) {
                return in_array(strtolower($d->protocol ?? ''), ['howen', 'jt808', 'jt1078'])
                    && $d->traccar_device_id;
            })
            ->sortBy('name')
            ->values();

        return view('front::DeviceVideo.playback', compact('devices'));
    }

    // =========================================================================
    // Video en vivo HLS — modal reproductor + proxy de stream
    // =========================================================================


    /**
     * GET /device_video/{device_id}/live/modal
     *
     * Contenido AJAX del modal del reproductor de video en vivo.
     */
    public function liveModal(int $deviceId)
    {
        $device = $this->findDevice($deviceId);

        if (! $this->videoService->isSupported($device)) {
            abort(404, 'El protocolo del dispositivo no soporta video en vivo.');
        }

        return view('front::DeviceVideo.live_modal', compact('device'));
    }

    /**
     * GET /device_video/{device_id}/stream/{file}
     *
     * Proxy del stream HLS (.m3u8 / .ts) desde Traccar al navegador.
     * Reescribe las URLs de segmentos en la playlist para que sigan
     * pasando a través de este proxy (no expone Traccar directamente).
     *
     * Ejemplos:
     *   GET /device_video/42/stream/live.m3u8
     *   GET /device_video/42/stream/segment0.ts
     */
    public function streamProxy(int $deviceId, string $file): Response
    {
        $device = $this->findDevice($deviceId);

        $config     = config('tracker');
        $traccarUrl = rtrim($this->videoService->getUrl(), '/') . ':' . $config['web.port'];

        // Determinar si es .m3u8 o .ts y construir la URL correcta en Traccar
        if (str_ends_with($file, '.m3u8')) {
            $channel    = (int) (request()->query('channel', 1));
            $streamUrl  = "{$traccarUrl}/api/stream/0/live.m3u8?uniqueId={$device->imei}&channel={$channel}";
        } elseif (preg_match('/^(\d+)\.ts$/', $file, $m)) {
            $channel    = (int) (request()->query('channel', 1));
            $streamUrl  = "{$traccarUrl}/api/stream/0/{$m[1]}.ts?uniqueId={$device->imei}&channel={$channel}";
        } else {
            abort(400, 'Archivo no válido');
        }

        $ch = curl_init($streamUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . base64_encode('admin:' . config('app.admin_user')),
            ],
        ]);

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $mimeType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/octet-stream';
        curl_close($ch);

        if ($httpCode !== 200) {
            abort($httpCode ?: 502, 'Error al obtener stream de Traccar');
        }

        // Reescribir URLs de segmentos .ts para que sigan pasando por el proxy
        if (str_ends_with($file, '.m3u8')) {
            $proxyBase = url("device_video/{$deviceId}/stream/");
            $ch2       = (int) (request()->query('channel', 1));
            $body      = preg_replace('/^(\d+\.ts)$/m', $proxyBase . '$1?channel=' . $ch2, $body);
            $mimeType  = 'application/vnd.apple.mpegurl';
        }

        return response($body, 200, [
            'Content-Type'                => $mimeType,
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control'               => 'no-cache, no-store, must-revalidate',
        ]);
    }
}
