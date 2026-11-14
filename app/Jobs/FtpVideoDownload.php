<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Tobuli\Entities\DeviceVideoDownload;
use Tobuli\Services\DeviceVideoService;
use Tobuli\Services\VideoFileService;

/**
 * Descarga por subida nativa del equipo (JT1078 0x9206): el equipo sube la grabación original al FTP en una carpeta
 * propia de la descarga y avisa con 0x1206. El job no ocupa el worker mientras tanto: se vuelve a encolar cada
 * POLL_SECONDS hasta que llega el resultado o vence el plazo.
 */
class FtpVideoDownload extends Job implements ShouldQueue
{
    use InteractsWithQueue, SerializesModels;

    const POLL_SECONDS = 15;

    /** Tiempo para que el archivo termine de escribirse tras el aviso de éxito (s). */
    const FILE_GRACE = 120;

    public $timeout = 900;

    /** @var DeviceVideoDownload */
    public $download;

    public function __construct(DeviceVideoDownload $download)
    {
        $this->download = $download;
        $this->onQueue(config('video.downloads_queue', 'video'));
    }

    /**
     * Reintentos por tiempo y no por cantidad: cada consulta es un release().
     */
    public function retryUntil()
    {
        return $this->download->created_at->copy()->addMinutes(config('video.ftp.timeout', 60) + 10);
    }

    public function handle(DeviceVideoService $videoService, VideoFileService $files)
    {
        $download = $this->download->fresh();

        if ( ! $download || ! $download->isActive())
            return;

        $device = $download->device;

        if ($download->status === DeviceVideoDownload::STATUS_PENDING) {
            $path = '/' . trim(config('video.downloads_path'), '/') . "/{$download->id}/";

            $result = $videoService->ftpUploadStart(
                $device,
                (int)$download->channel,
                $download->start_at->toIso8601ZuluString(),
                $download->end_at->toIso8601ZuluString(),
                $path
            );

            if ( ! $result['ok'])
                return $files->fail($download, $result['error']);

            $download->update(['status' => DeviceVideoDownload::STATUS_RECORDING, 'upload_path' => $path]);

            return $this->release(self::POLL_SECONDS);
        }

        $upload = $videoService->ftpUpload($device, $download->upload_path);

        if ($upload && $upload['result'] === 1)
            return $files->fail($download, trans('front.video_download_rejected'));

        if ($upload && $upload['result'] === 0) {
            $collected = $this->collect($download, $files);

            if ($collected || time() - (int)($upload['resultTime'] / 1000) > self::FILE_GRACE) {
                if ( ! $collected)
                    $files->fail($download, trans('front.video_download_no_file'));

                return;
            }

            return $this->release(self::POLL_SECONDS);
        }

        if ($download->created_at->copy()->addMinutes(config('video.ftp.timeout', 60))->isPast()) {
            if ($upload)
                $videoService->ftpUploadControl($device, (int)$upload['serial'], DeviceVideoService::UPLOAD_CANCEL);

            return $files->fail($download, trans('front.video_download_timeout'));
        }

        $this->release(self::POLL_SECONDS);
    }

    public function failed(\Throwable $exception)
    {
        (new VideoFileService())->fail($this->download->fresh(), $exception->getMessage());
    }

    /**
     * Toma los archivos que subió el equipo: uno se convierte a MP4 si hace falta; varios se entregan en un ZIP.
     */
    private function collect(DeviceVideoDownload $download, VideoFileService $files): bool
    {
        $directory = rtrim(config('video.ftp.root'), '/\\') . $download->upload_path;

        if ( ! File::isDirectory($directory))
            return false;

        $uploaded = collect(File::allFiles($directory))
            ->filter(fn ($file) => $file->getSize() > 0)
            ->sortBy(fn ($file) => $file->getFilename())
            ->values();

        if ($uploaded->isEmpty())
            return false;

        $duration = $download->start_at->diffInSeconds($download->end_at);

        if ($uploaded->count() === 1) {
            $file = $uploaded->first();
            [$source, $extension] = $files->remux($file->getPathname(), $file->getExtension() ?: 'bin');
            $files->store($download, $source, $extension, $duration);

            if ($source !== $file->getPathname())
                @unlink($source);
        } else {
            $zipPath = tempnam(sys_get_temp_dir(), 'vzip');
            $zip = new \ZipArchive();
            $zip->open($zipPath, \ZipArchive::OVERWRITE);
            foreach ($uploaded as $file) {
                $zip->addFile($file->getPathname(), $file->getFilename());
            }
            $zip->close();

            $files->store($download, $zipPath, 'zip', $duration);
            @unlink($zipPath);
        }

        File::deleteDirectory($directory);

        return true;
    }
}
