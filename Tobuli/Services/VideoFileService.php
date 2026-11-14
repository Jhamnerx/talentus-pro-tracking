<?php

namespace Tobuli\Services;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tobuli\Entities\DeviceVideoDownload;

/**
 * Archivos de las descargas de video: conversión a MP4 y guardado en el disco de descargas.
 */
class VideoFileService
{
    /** Video H.264 crudo (sin contenedor) que algunas cámaras suben por FTP. */
    const RAW_H264 = ['h264', '264'];

    /**
     * Pasa el archivo a MP4 sin recodificar, si hay ffmpeg. Si no se puede, se entrega tal cual.
     *
     * @return array [ruta, extensión]
     */
    public function remux(string $source, string $extension): array
    {
        $extension = strtolower($extension);

        if ($extension === 'mp4')
            return [$source, 'mp4'];

        $target = $source . '.mp4';
        $input = in_array($extension, self::RAW_H264) ? ['-f', 'h264', '-i', $source] : ['-i', $source];

        try {
            $process = new Process(array_merge(
                [config('video.ffmpeg', 'ffmpeg'), '-y', '-loglevel', 'error'],
                $input,
                ['-c', 'copy', '-movflags', '+faststart', $target]
            ));
            $process->setTimeout(600);
            $process->run();

            if ($process->isSuccessful() && @filesize($target))
                return [$target, 'mp4'];
        } catch (\Throwable $e) {
        }

        @unlink($target);

        return [$source, $extension];
    }

    /**
     * Guarda el archivo local en el disco de descargas y marca la descarga como lista.
     */
    public function store(DeviceVideoDownload $download, string $file, string $extension, int $duration): void
    {
        $path = config('video.downloads_path') . "/{$download->device_id}/{$download->id}_ch{$download->channel}_"
            . $download->start_at->format('Ymd_His') . ".{$extension}";

        // El worker de la cola suele correr como root (cron) y la web como apache: archivo y carpetas legibles.
        $stream = fopen($file, 'rb');
        Storage::disk(config('video.downloads_disk'))->put($path, $stream, [
            'visibility'           => 'public',
            'directory_visibility' => 'public',
        ]);
        fclose($stream);

        $download->update([
            'status'   => DeviceVideoDownload::STATUS_DONE,
            'path'     => $path,
            'size'     => filesize($file),
            'duration' => $duration,
        ]);
    }

    public function fail(?DeviceVideoDownload $download, ?string $error): void
    {
        if ( ! $download)
            return;

        $download->update([
            'status' => DeviceVideoDownload::STATUS_FAILED,
            'error'  => mb_substr((string)($error ?: trans('front.video_err_platform')), 0, 250),
        ]);
    }
}
