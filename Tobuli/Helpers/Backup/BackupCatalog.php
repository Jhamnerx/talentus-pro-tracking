<?php

namespace Tobuli\Helpers\Backup;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Tobuli\Entities\Device;
use Tobuli\Helpers\Backup\Destination\BackupDestination;

class BackupCatalog
{
    private const DEFAULT_HISTORY_DAYS = 160;
    private const FOLDERS_CACHE_SECONDS = 300;
    private const FOLDER_PREFIX = 'backup_';

    private BackupService $service;
    private BackupIndex $index;

    public function __construct(BackupService $service)
    {
        $this->service = $service;
        $this->index = new BackupIndex();
    }

    /**
     * Copias que tienen posiciones de este device, de la más reciente a la más antigua.
     *
     * @return array<int, array{name: string, from: ?string, to: ?string, approximate: bool, origins: string[]}>
     */
    public function entries(Device $device, ?string $date = null): array
    {
        $traccar = $device->traccar;
        $file = BackupPathResolver::devicePositionsFile($traccar);
        $entries = [];

        foreach ($this->service->destinations() as $name => $destination) {
            try {
                $folders = Cache::remember(
                    "backup_folders.$name",
                    self::FOLDERS_CACHE_SECONDS,
                    fn () => $destination->listFolders(self::FOLDER_PREFIX)
                );

                foreach ($folders as $folder) {
                    $range = $this->range($destination, $folder, $traccar->id, $file);

                    if ($range === null) {
                        continue;
                    }

                    if (!isset($entries[$folder]) || ($entries[$folder]['approximate'] && !$range['approximate'])) {
                        $entries[$folder] = ['name' => $folder, 'origins' => $entries[$folder]['origins'] ?? []] + $range;
                    }

                    $entries[$folder]['origins'][] = $name;
                }
            } catch (\Throwable $e) {
                // Si un destino no responde, se muestran las copias del otro.
                logger()->warning("Backup catalog: $name unavailable: " . BackupSecrets::scrub($e->getMessage()));
            }
        }

        if ($date) {
            $entries = array_filter($entries, fn ($entry) => $this->contains($entry, $date));
        }

        krsort($entries);

        return array_values($entries);
    }

    /**
     * Descarga el archivo de un device. El FTP tiene prioridad; si falla, se usa el otro destino.
     *
     * @return string|null ruta local, o null si no está en ningún destino
     */
    public function fetch(Device $device, string $folder): ?string
    {
        $relative = BackupPathResolver::devicePositionsFile($device->traccar);
        $remote = $folder . '/' . $relative;
        $lastError = null;

        foreach ($this->service->destinations() as $name => $destination) {
            $local = BackupTempFile::path(basename($relative));

            try {
                if (!$destination->exists($remote)) {
                    continue;
                }

                $destination->download($remote, $local);
                BackupTempFile::assertValid($local);

                return $local;
            } catch (\Throwable $e) {
                @unlink($local);
                $lastError = $e;
                logger()->warning("Backup restore: $name failed: " . BackupSecrets::scrub($e->getMessage()));
            }
        }

        if ($lastError) {
            throw $lastError;
        }

        return null;
    }

    public static function historyDays(): int
    {
        return (int)settings('db_clear.days') ?: self::DEFAULT_HISTORY_DAYS;
    }

    private function range(BackupDestination $destination, string $folder, int $traccarId, string $file): ?array
    {
        $index = $this->index->read($destination, $folder);

        if ($index !== null) {
            $device = $index['devices'][$traccarId] ?? null;

            if ($device) {
                return ['from' => $device['from'], 'to' => $device['to'], 'approximate' => false];
            }

            // Si el indice quedo incompleto el archivo puede existir: se comprueba abajo (rango aproximado).
        }

        if (!$destination->exists($folder . '/' . $file)) {
            return null;
        }

        $date = BackupRetention::folderDate($folder);

        return [
            'from'        => $date ? $date->copy()->subDays(self::historyDays())->toDateTimeString() : null,
            'to'          => $date ? $date->toDateTimeString() : null,
            'approximate' => true,
        ];
    }

    private function contains(array $entry, string $date): bool
    {
        if (!$entry['from'] || !$entry['to']) {
            return false;
        }

        $day = Carbon::parse($date)->format('Y-m-d');

        return substr($entry['from'], 0, 10) <= $day && $day <= substr($entry['to'], 0, 10);
    }
}
