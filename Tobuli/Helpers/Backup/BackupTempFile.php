<?php

namespace Tobuli\Helpers\Backup;

use Tobuli\Entities\BackupProcess;

class BackupTempFile
{
    private const STALE_SECONDS = 86400;

    public static function path(string $name): string
    {
        $dir = storage_path('app/backup-tmp');

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
            @chmod($dir, 0775);
        }

        return $dir . '/' . uniqid('', true) . '-' . basename($name);
    }

    /**
     * Ejecuta "comando | gzip -9 > temporal" y devuelve la ruta del temporal.
     */
    public static function dump(string $command, string $name, ?BackupProcess $keepAlive = null): string
    {
        $path = self::path($name);

        try {
            BackupCommandRunner::shell($command . ' | gzip -9 > ' . escapeshellarg($path), $keepAlive);
        } catch (\Throwable $e) {
            @unlink($path);

            throw $e;
        }

        return $path;
    }

    /**
     * @throws \RuntimeException si el archivo está vacío, dañado o el volcado quedó a medias
     */
    public static function assertValid(string $path, bool $requireDumpEnd = false): void
    {
        clearstatcache(true, $path);

        if (!is_file($path) || filesize($path) === 0) {
            throw new \RuntimeException('Backup file is empty: ' . basename($path));
        }

        try {
            BackupCommandRunner::shell('gzip -t ' . escapeshellarg($path));

            if ($requireDumpEnd) {
                BackupCommandRunner::shell(
                    'zcat ' . escapeshellarg($path) . " | tail -n 3 | grep -q 'Dump completed'"
                );
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('Backup file failed validation: ' . basename($path), 0, $e);
        }
    }

    /**
     * Borra restos de ejecuciones interrumpidas.
     */
    public static function cleanStale(): void
    {
        foreach (glob(storage_path('app/backup-tmp/*')) ?: [] as $file) {
            if (is_file($file) && filemtime($file) < time() - self::STALE_SECONDS) {
                @unlink($file);
            }
        }
    }
}
