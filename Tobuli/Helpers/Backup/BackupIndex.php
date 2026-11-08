<?php

namespace Tobuli\Helpers\Backup;

use Tobuli\Entities\Backup;
use Tobuli\Helpers\Backup\Destination\BackupDestination;

class BackupIndex
{
    public const FILE = '_completo.json';

    /**
     * Guarda el rango de un device durante el backup. Es un archivo local por backup, que
     * sobrevive a una reanudación.
     */
    public function record(int $backupId, int $traccarId, ?string $from, ?string $to): void
    {
        $line = json_encode(['id' => $traccarId, 'from' => $from, 'to' => $to]) . "\n";

        // Un fallo del indice no debe abortar el backup: solo quedara un rango aproximado.
        if (@file_put_contents($this->rangesPath($backupId), $line, FILE_APPEND | LOCK_EX) === false) {
            logger()->error("Backup index: cannot write ranges for backup $backupId (device $traccarId)");
        }
    }

    /**
     * Sube el índice a un destino y lo deja en la caché local.
     */
    public function publish(Backup $backup, BackupDestination $destination, array $failedDevices = []): void
    {
        $content = json_encode([
            'backup_id'  => $backup->id,
            'created_at' => (string)$backup->created_at,
            'devices'    => $this->ranges($backup->id),
            'failed_devices' => array_values($failedDevices),
        ]);

        $folder = BackupPathResolver::backupFolder($backup);
        $local = BackupTempFile::path(self::FILE);
        file_put_contents($local, $content);

        try {
            $destination->upload($local, $folder . '/' . self::FILE);
        } finally {
            @unlink($local);
        }

        $this->writeCache($destination, $folder, $content);
    }

    public function forgetRanges(int $backupId): void
    {
        @unlink($this->rangesPath($backupId));
    }

    /**
     * @return array|null el índice de una carpeta, o null si esa copia no tiene
     */
    public function read(BackupDestination $destination, string $folder): ?array
    {
        $cache = $this->cachePath($destination, $folder);

        if (is_file($cache)) {
            $cached = json_decode((string)@file_get_contents($cache), true);

            if (is_array($cached) && $cached) {
                return $cached;
            }
        }

        $content = $destination->read($folder . '/' . self::FILE);

        if ($content === null) {
            return null;
        }

        $this->writeCache($destination, $folder, $content);

        return json_decode($content, true) ?: null;
    }

    private function ranges(int $backupId): array
    {
        $ranges = [];

        foreach (@file($this->rangesPath($backupId), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $row = json_decode($line, true);

            if ($row) {
                $ranges[$row['id']] = ['from' => $row['from'], 'to' => $row['to']];
            }
        }

        return $ranges;
    }

    private function rangesPath(int $backupId): string
    {
        return $this->directory() . "/ranges-$backupId.jsonl";
    }

    private function writeCache(BackupDestination $destination, string $folder, string $content): void
    {
        @file_put_contents($this->cachePath($destination, $folder), $content);
    }

    private function cachePath(BackupDestination $destination, string $folder): string
    {
        $dir = $this->directory() . '/' . $destination->name();

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
            @chmod($dir, 0775);
        }

        return $dir . '/' . basename($folder) . '.json';
    }

    private function directory(): string
    {
        $dir = storage_path('app/backup-index');

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
            @chmod($dir, 0775);
        }

        return $dir;
    }
}
