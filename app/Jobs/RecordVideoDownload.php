<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Tobuli\Entities\DeviceVideoDownload;
use Tobuli\Services\DeviceVideoService;
use Tobuli\Services\VideoFileService;

/**
 * Descarga una grabación del equipo: la reproduce (videoPlayback) y va guardando los segmentos
 * del playlist de historial hasta cubrir su duración. El equipo transmite en tiempo real, así que
 * el job dura lo mismo que la grabación.
 */
class RecordVideoDownload extends Job implements ShouldQueue
{
    use InteractsWithQueue, SerializesModels;

    const POLL_SECONDS = 2;
    const FIRST_SEGMENT_TIMEOUT = 90;
    const SEGMENT_GAP_TIMEOUT = 45;

    public $tries = 1;

    public $timeout = 3900;

    /** @var DeviceVideoDownload */
    public $download;

    public function __construct(DeviceVideoDownload $download)
    {
        $this->download = $download;
        $this->onQueue(config('video.downloads_queue', 'video'));
    }

    public function handle(DeviceVideoService $videoService)
    {
        $download = $this->download->fresh();

        if ( ! $download || $download->status !== DeviceVideoDownload::STATUS_PENDING)
            return;

        $device = $download->device;
        $channel = (int)$download->channel;
        $expected = $download->start_at->diffInSeconds($download->end_at);

        $download->update(['status' => DeviceVideoDownload::STATUS_RECORDING]);

        $result = $videoService->playbackStart(
            $device,
            $channel,
            $download->start_at->toIso8601ZuluString(),
            $download->end_at->toIso8601ZuluString()
        );

        if ( ! $result['ok'])
            return $this->markFailed($download, $result['error']);

        $tmp = tempnam(sys_get_temp_dir(), 'vdl');
        $file = fopen($tmp, 'wb');
        $lastIndex = -1;
        $recorded = 0.0;
        $startedAt = time();
        $lastSegmentAt = null;

        try {
            while (true) {
                sleep(self::POLL_SECONDS);

                foreach ($this->newSegments($videoService, $device, $channel, $lastIndex) as [$index, $duration]) {
                    $response = $videoService->streamFile($device, $channel, "history-{$index}.ts");

                    if ($response->successful()) {
                        fwrite($file, $response->body());
                        $recorded += $duration;
                        $lastSegmentAt = time();
                    }

                    $lastIndex = $index;
                }

                if ($recorded >= $expected - self::POLL_SECONDS)
                    break;

                if ( ! $lastSegmentAt && time() - $startedAt > self::FIRST_SEGMENT_TIMEOUT)
                    break;

                if ($lastSegmentAt && time() - $lastSegmentAt > self::SEGMENT_GAP_TIMEOUT)
                    break;

                if (time() - $startedAt > $expected * 2 + 120)
                    break;
            }
        } finally {
            fclose($file);
            $videoService->playbackControl($device, $channel, DeviceVideoService::PLAYBACK_STOP);
        }

        if ( ! filesize($tmp)) {
            @unlink($tmp);

            return $this->markFailed($download, trans('front.video_timeout'));
        }

        $files = new VideoFileService();
        [$source, $extension] = $files->remux($tmp, 'ts');
        $files->store($download, $source, $extension, (int)round($recorded));

        @unlink($tmp);
        if ($source !== $tmp)
            @unlink($source);
    }

    public function failed(\Throwable $exception)
    {
        $this->markFailed($this->download->fresh(), $exception->getMessage());
    }

    /**
     * @return array [[index, duración], ...] de los segmentos posteriores a $lastIndex
     */
    private function newSegments(DeviceVideoService $videoService, $device, int $channel, int $lastIndex): array
    {
        $response = $videoService->streamFile($device, $channel, 'history.m3u8');

        if ( ! $response->successful())
            return [];

        $segments = [];
        $duration = 0.0;

        foreach (preg_split('/\r?\n/', $response->body()) as $line) {
            if (preg_match('/^#EXTINF:([\d.]+)/', $line, $match)) {
                $duration = (float)$match[1];
            } elseif (preg_match('/history-(\d+)\.ts/', $line, $match) && (int)$match[1] > $lastIndex) {
                $segments[] = [(int)$match[1], $duration];
            }
        }

        return $segments;
    }

    private function markFailed(?DeviceVideoDownload $download, ?string $error)
    {
        (new VideoFileService())->fail($download, $error);
    }
}
