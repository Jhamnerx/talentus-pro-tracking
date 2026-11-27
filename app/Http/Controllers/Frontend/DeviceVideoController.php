<?php

namespace App\Http\Controllers\Frontend;

use App\Exceptions\PermissionException;
use App\Exceptions\ResourseNotFoundException;
use App\Http\Controllers\Controller;
use App\Jobs\FtpVideoDownload;
use App\Jobs\RecordVideoDownload;
use Carbon\Carbon;
use Formatter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToRetrieveMetadata;
use Tobuli\Entities\Device;
use Tobuli\Entities\DeviceVideoDownload;
use Tobuli\Services\DeviceVideoService;

class DeviceVideoController extends Controller
{
    /** @var DeviceVideoService */
    private $videoService;

    public function __construct(DeviceVideoService $videoService)
    {
        parent::__construct();

        $this->videoService = $videoService;
    }

    public function player(Request $request, $id)
    {
        $device = $this->device($id);
        $channels = $this->videoService->channels($device);

        if (empty($channels))
            throw new ResourseNotFoundException(trans('front.video'));

        $channel = (int)$request->get('channel');

        if ( ! in_array($channel, $channels))
            $channel = $channels[0];

        $playback = $request->get('mode') === DeviceVideoService::TYPE_HISTORY;
        [$from, $to] = $playback ? $this->playbackRange($request) : [null, null];

        return view('front::DeviceVideo.player', [
            'device'   => $device,
            'channel'  => $channel,
            'channels' => $playback ? [$channel] : $channels,
            'roles'    => (array)$device->camera_channel_roles,
            'viewer'   => preg_replace('/[^\w-]/', '', (string)$request->get('viewer')) ?: uniqid('w'),
            'playback' => $playback ? [
                'from'  => $from,
                'to'    => $to,
                'title' => Formatter::time()->human(Carbon::parse($from)->format('Y-m-d H:i:s')),
            ] : null,
            'audio'    => [
                'codec'        => $device->camera_attributes['audioEncoding'] ?? null,
                'frameSamples' => $device->camera_attributes['audioFrameLength'] ?? null,
                'g726Packing'  => config('video.g726_packing', 'rfc3551'),
            ],
            'talk'     => $playback ? null : $this->talkConfig($device),
            'realtime' => $playback ? null : (collect($channels)
                ->mapWithKeys(fn ($ch) => [$ch => $this->videoService->realtimeUrls($device, $ch)])
                ->filter()
                ->all() ?: null),
        ]);
    }

    /**
     * Configuración del reproductor para la app móvil (equivalente JSON de player()).
     */
    public function config($id)
    {
        $device = $this->device($id);
        $channels = $this->videoService->channels($device);

        if (empty($channels))
            throw new ResourseNotFoundException(trans('front.video'));

        $roles = (array)$device->camera_channel_roles;
        $talk = $this->talkConfig($device);

        return response()->json([
            'ok'       => true,
            'device'   => [
                'id'   => $device->id,
                'name' => $device->name,
                'lat'  => (float)$device->lat,
                'lng'  => (float)$device->lng,
            ],
            'channels' => array_map(fn ($channel) => [
                'channel' => $channel,
                'role'    => $roles[$channel] ?? null,
                'title'   => trim(trans('front.channel') . ' ' . $channel . (empty($roles[$channel])
                    ? ''
                    : ' · ' . trans('front.video_role_' . $roles[$channel]))),
                'hls'         => $this->routeUrl('devices.video.stream', [$device->id, $channel, 'live.m3u8']),
                'history_hls' => $this->routeUrl('devices.video.stream', [$device->id, $channel, 'history.m3u8']),
                'realtime'    => $this->videoService->realtimeUrls($device, $channel),
            ], $channels),
            'attributes'  =>$this->videoService->describeAttributes($device->camera_attributes),
            'audio'       => [
                'codec'        => $device->camera_attributes['audioEncoding'] ?? null,
                'frameSamples' => $device->camera_attributes['audioFrameLength'] ?? null,
                'g726Packing'  => config('video.g726_packing', 'rfc3551'),
            ],
            'talk'        => $talk,
            'download_max_seconds' => DeviceVideoDownload::MAX_DURATION,
            'viewer_ttl'  => DeviceVideoService::VIEWER_TTL,
            // Para la busqueda de grabaciones (UI v2): hoy desde las 00:00 hasta ahora, en la hora del usuario.
            'recordings_from' => Formatter::time()->convert(Carbon::now('UTC')->format('Y-m-d H:i:s'), 'Y-m-d') . ' 00:00',
            'recordings_to'   => Formatter::time()->convert(Carbon::now('UTC')->format('Y-m-d H:i:s'), 'Y-m-d H:i'),
            'ftp'         => DeviceVideoService::ftpEnabled(),
        ]);
    }

    public function status(Request $request, $id)
    {
        [$device, $channel] = $this->deviceChannel($request, $id);

        $type = $request->get('type') === DeviceVideoService::TYPE_HISTORY
            ? DeviceVideoService::TYPE_HISTORY
            : DeviceVideoService::TYPE_LIVE;

        $status = $this->videoService->status($device, $channel, $type);

        return response()->json($status ? ['ok' => true] + $status : ['ok' => false]);
    }

    public function recordings($id)
    {
        $device = $this->device($id);
        $channels = $this->videoService->channels($device);

        if (empty($channels))
            throw new ResourseNotFoundException(trans('front.video'));

        return view('front::DeviceVideo.recordings', [
            'device'   => $device,
            'channels' => $channels,
            'roles'    => (array)$device->camera_channel_roles,
            // Hoy desde las 00:00 hasta ahora, en la hora del usuario.
            'from'     => Formatter::time()->convert(Carbon::now('UTC')->format('Y-m-d H:i:s'), 'Y-m-d') . ' 00:00',
            'to'       => Formatter::time()->convert(Carbon::now('UTC')->format('Y-m-d H:i:s'), 'Y-m-d H:i'),
        ]);
    }

    public function recordingsQuery(Request $request, $id)
    {
        $device = $this->device($id);

        $this->validate($request, [
            'channel' => 'required|integer|in:' . implode(',', array_merge([0], $this->videoService->channels($device))),
            'from'    => 'required|date',
            'to'      => 'required|date|after:from',
        ], [], [
            'channel' => trans('front.channel'),
            'from'    => trans('front.from'),
            'to'      => trans('front.to'),
        ]);

        $from = $this->userTimeToUtc($request->get('from'));
        $to = $this->userTimeToUtc($request->get('to'));

        if ($from->diffInDays($to) > 7)
            return response()->json(['ok' => false, 'error' => trans('front.video_recordings_range')]);

        return response()->json($this->videoService->queryRecordings(
            $device,
            (int)$request->get('channel'),
            $from->toIso8601ZuluString(),
            $to->toIso8601ZuluString()
        ));
    }

    public function recordingsList(Request $request, $id)
    {
        $device = $this->device($id);
        $channels = $this->videoService->channels($device);

        $result = $this->videoService->recordings($device, (int)$request->get('seq'));

        if ( ! empty($result['items'])) {
            $items = array_filter($result['items'], fn ($item) => in_array((int)($item['channel'] ?? 0), $channels));

            $result['items'] = array_values(array_map(function ($item) {
                $start = Carbon::parse($item['start']);
                $end = Carbon::parse($item['end']);

                return [
                    'channel'   => (int)$item['channel'],
                    'start'     => $start->toIso8601ZuluString(),
                    'end'       => $end->toIso8601ZuluString(),
                    'start_at'  => Formatter::time()->human($start->format('Y-m-d H:i:s')),
                    'end_at'    => Formatter::time()->human($end->format('Y-m-d H:i:s')),
                    'duration'  => $start->diffInSeconds($end),
                    'size'      => (int)($item['size'] ?? 0),
                    'alarm'     => ! empty($item['alarm']),
                ];
            }, $items));
        }

        return response()->json($result);
    }

    public function playbackStart(Request $request, $id)
    {
        [$device, $channel] = $this->deviceChannel($request, $id);
        [$from, $to] = $this->playbackRange($request);

        return response()->json($this->videoService->playbackStart($device, $channel, $from, $to));
    }

    public function playbackControl(Request $request, $id)
    {
        [$device, $channel] = $this->deviceChannel($request, $id);

        $action = (int)$request->get('action');

        if ( ! in_array($action, [
            DeviceVideoService::PLAYBACK_PLAY,
            DeviceVideoService::PLAYBACK_PAUSE,
            DeviceVideoService::PLAYBACK_STOP,
        ]))
            $action = DeviceVideoService::PLAYBACK_STOP;

        return response()->json($this->videoService->playbackControl($device, $channel, $action));
    }

    public function talkStart(Request $request, $id)
    {
        $device = $this->device($id);

        return response()->json($this->videoService->talkStart($device, $this->viewer($request)));
    }

    public function talkPing(Request $request, $id)
    {
        $device = $this->device($id);

        return response()->json(['ok' => $this->videoService->talkPing($device, $this->viewer($request))]);
    }

    public function talkStop(Request $request, $id)
    {
        $device = $this->device($id);

        return response()->json(['ok' => $this->videoService->talkStop($device, $this->viewer($request))]);
    }

    public function attributesQuery($id)
    {
        $device = $this->device($id);

        return response()->json($this->videoService->queryAttributes($device));
    }

    public function attributes(Request $request, $id)
    {
        $device = $this->device($id);

        if ( ! $request->has('seq'))
            return response()->json([
                'ok'         => true,
                'fresh'      => true,
                'attributes' => $this->videoService->describeAttributes($device->camera_attributes),
            ]);

        return response()->json($this->videoService->attributes($device, (int)$request->get('seq')));
    }

    public function downloadCreate(Request $request, $id)
    {
        [$device, $channel] = $this->deviceChannel($request, $id);
        [$from, $to] = $this->playbackRange($request);

        $start = Carbon::parse($from);
        $end = Carbon::parse($to);

        if ($end->lte($start) || $start->diffInSeconds($end) > DeviceVideoDownload::MAX_DURATION)
            return response()->json(['ok' => false, 'error' => trans('front.video_download_too_long')]);

        // El stream de historial es uno por canal: dos descargas a la vez se pisarían.
        $busy = DeviceVideoDownload::where('device_id', $device->id)
            ->whereIn('status', [DeviceVideoDownload::STATUS_PENDING, DeviceVideoDownload::STATUS_RECORDING])
            ->exists();

        if ($busy)
            return response()->json(['ok' => false, 'error' => trans('front.video_download_busy')]);

        // Con FTP el equipo sube el archivo original; si no, se graba la reproducción remota.
        $ftp = DeviceVideoService::ftpEnabled();

        $download = DeviceVideoDownload::create([
            'device_id' => $device->id,
            'user_id'   => $this->user->id,
            'channel'   => $channel,
            'start_at'  => $start,
            'end_at'    => $end,
            'status'    => DeviceVideoDownload::STATUS_PENDING,
            'method'    => $ftp ? DeviceVideoDownload::METHOD_FTP : DeviceVideoDownload::METHOD_PLAYBACK,
        ]);

        dispatch($ftp ? new FtpVideoDownload($download) : new RecordVideoDownload($download));

        return response()->json(['ok' => true, 'items' => $this->downloadItems($device)]);
    }

    public function downloads($id)
    {
        $device = $this->device($id);

        return response()->json(['ok' => true, 'items' => $this->downloadItems($device)]);
    }

    public function downloadFile($id, $downloadId)
    {
        $download = $this->findDownload($id, $downloadId);

        if ($download->status !== DeviceVideoDownload::STATUS_DONE || ! $download->path)
            throw new ResourseNotFoundException(trans('front.video_download'));

        try {
            return Storage::disk(config('video.downloads_disk'))->download(
                $download->path,
                preg_replace('/[^\w-]+/', '_', $download->device->name) . '_' . basename($download->path)
            );
        } catch (UnableToRetrieveMetadata $e) {
            // Borrado por la limpieza o sin permiso de lectura para el servidor web.
            throw new ResourseNotFoundException(trans('front.video_download'));
        }
    }

    public function downloadDelete($id, $downloadId)
    {
        $download = $this->findDownload($id, $downloadId);
        $device = $download->device;

        // Una subida FTP en curso se cancela en el equipo (0x9207); una grabación de la reproducción no se interrumpe.
        if ($download->isActive()) {
            if ($download->method !== DeviceVideoDownload::METHOD_FTP)
                return response()->json(['ok' => false, 'error' => trans('front.video_download_busy')]);

            $upload = $download->upload_path ? $this->videoService->ftpUpload($device, $download->upload_path) : null;
            if ($upload)
                $this->videoService->ftpUploadControl($device, (int)$upload['serial'], DeviceVideoService::UPLOAD_CANCEL);
        }

        $download->delete();

        return response()->json(['ok' => true, 'items' => $this->downloadItems($device)]);
    }

    private function findDownload($id, $downloadId): DeviceVideoDownload
    {
        $device = $this->device($id);

        $download = DeviceVideoDownload::where('device_id', $device->id)->find($downloadId);

        if ( ! $download || ($download->user_id != $this->user->id && ! $this->user->isAdmin()))
            throw new ResourseNotFoundException(trans('front.video_download'));

        return $download;
    }

    private function downloadItems(Device $device): array
    {
        return DeviceVideoDownload::where('device_id', $device->id)
            ->when( ! $this->user->isAdmin(), fn ($query) => $query->where('user_id', $this->user->id))
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (DeviceVideoDownload $download) => [
                'id'       => $download->id,
                'channel'  => $download->channel,
                'method'   => $download->method,
                'start_at' => Formatter::time()->human($download->start_at->format('Y-m-d H:i:s')),
                'seconds'  => $download->start_at->diffInSeconds($download->end_at),
                'status'   => $download->status,
                'size'     => (int)$download->size,
                'error'    => $download->error,
                'start'    => $download->start_at->toIso8601ZuluString(),
                'url'      => $download->status === DeviceVideoDownload::STATUS_DONE
                    ? $this->routeUrl('devices.video.downloads.file', [$device->id, $download->id])
                    : null,
            ])
            ->all();
    }

    public function start(Request $request, $id)
    {
        [$device, $channel] = $this->deviceChannel($request, $id);

        return response()->json(
            $this->videoService->start(
                $device,
                $channel,
                $this->viewer($request),
                (bool)$request->get('audio'),
                $request->get('quality') === 'sd'
            )
        );
    }

    public function ping(Request $request, $id)
    {
        [$device, $channel] = $this->deviceChannel($request, $id);

        $this->videoService->touchViewer($device, $channel, $this->viewer($request));

        return response()->json(['ok' => true]);
    }

    public function stop(Request $request, $id)
    {
        [$device, $channel] = $this->deviceChannel($request, $id);

        $stopped = $this->videoService->stop($device, $channel, $this->viewer($request));

        return response()->json(['ok' => true, 'stopped' => $stopped]);
    }

    public function stream($id, $channel, $file)
    {
        $device = $this->device($id);
        $channel = (int)$channel;

        if ( ! $this->videoService->hasChannel($device, $channel))
            throw new ResourseNotFoundException(trans('front.channel'));

        $response = $this->videoService->streamFile($device, $channel, $file);

        if ( ! $response->successful())
            abort($response->status() ?: 502);

        $isPlaylist = substr($file, -5) === '.m3u8';
        $body = $response->body();

        // La app autentica con user_api_hash en la URL; los segmentos relativos de la lista no lo heredan.
        if ($isPlaylist && $this->isApi())
            $body = preg_replace_callback('/^(?!#)(\S+)$/m', fn ($match) => $match[1]
                . (strpos($match[1], '?') === false ? '?' : '&')
                . 'user_api_hash=' . urlencode((string)request('user_api_hash')), $body);

        return response($body, 200, [
            'Content-Type'  => $isPlaylist ? 'application/vnd.apple.mpegurl' : 'video/mp2t',
            'Cache-Control' => $isPlaylist ? 'no-cache, no-store' : 'private, max-age=60',
        ]);
    }

    private function device($id): Device
    {
        $device = Device::find($id);

        $this->checkException('devices', 'show', $device);

        if ( ! $this->user->perm('device_video', 'view'))
            throw new PermissionException();

        return $device;
    }

    private function deviceChannel(Request $request, $id): array
    {
        $device = $this->device($id);
        $channel = (int)$request->get('channel');

        if ( ! $this->videoService->hasChannel($device, $channel))
            throw new ResourseNotFoundException(trans('front.channel'));

        return [$device, $channel];
    }

    /**
     * Rango de una grabación: instantes UTC ISO-8601 tal como los devolvió la lista del equipo.
     */
    private function playbackRange(Request $request): array
    {
        try {
            $from = Carbon::parse((string)$request->get('from'))->utc();
            $to = Carbon::parse((string)$request->get('to'))->utc();
        } catch (\Exception $e) {
            throw new ResourseNotFoundException(trans('front.video_recordings'));
        }

        return [$from->toIso8601ZuluString(), $to->toIso8601ZuluString()];
    }

    /**
     * Conversación: requiere el modo tiempo real (audio directo al tracker) y que el equipo no haya informado
     * que no tiene salida de audio (0x1003).
     */
    private function talkConfig(Device $device): ?array
    {
        $attributes = (array)$device->camera_attributes;

        if (array_key_exists('audioOutput', $attributes) && ! $attributes['audioOutput'])
            return null;

        $channel = $this->videoService->talkChannel();
        $urls = $this->videoService->realtimeUrls($device, $channel);

        if ( ! $urls)
            return null;

        $codec = (int)($attributes['audioEncoding'] ?? 6);

        return [
            'channel'      => $channel,
            'urls'         => $urls,
            // Se habla en el códec del equipo; si no es G.711/G.726 se usa G.711A.
            'codec'        => in_array($codec, [6, 7, 8]) ? $codec : 6,
            'frameSamples' => (int)($attributes['audioFrameLength'] ?? 0) ?: 320,
            'hisilicon'    => (bool)config('video.talk_hisilicon_header', true),
            'g726Bits'     => (int)config('video.talk_g726_bits', 5),
            'start'        => $this->routeUrl('devices.video.talk.start', $device->id),
            'ping'         => $this->routeUrl('devices.video.talk.ping', $device->id),
            'stop'         => $this->routeUrl('devices.video.talk.stop', $device->id),
        ];
    }

    private function isApi(): bool
    {
        return request()->is('api/*');
    }

    /**
     * URL de la ruta web o, desde la app, de su par del API con el user_api_hash para clientes que no
     * pueden enviar cabeceras (reproductor de video, descargas).
     */
    private function routeUrl(string $name, $parameters): string
    {
        if ( ! $this->isApi())
            return route($name, $parameters);

        $url = route('api.' . $name, $parameters);

        return $url . (strpos($url, '?') === false ? '?' : '&')
            . 'user_api_hash=' . urlencode((string)request('user_api_hash'));
    }

    private function userTimeToUtc(string $value): Carbon
    {
        return Carbon::parse(Formatter::time()->reverse($value, 'Y-m-d H:i:s'), 'UTC');
    }

    private function viewer(Request $request): string
    {
        return substr(preg_replace('/[^\w-]/', '', (string)$request->get('viewer')), 0, 40) ?: 'default';
    }
}
