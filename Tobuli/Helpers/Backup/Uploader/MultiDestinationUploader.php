<?php

namespace Tobuli\Helpers\Backup\Uploader;

use Tobuli\Entities\Backup;
use Tobuli\Entities\BackupProcess;
use Tobuli\Helpers\Backup\BackupPathResolver;
use Tobuli\Helpers\Backup\BackupSecrets;
use Tobuli\Helpers\Backup\BackupTempFile;
use Tobuli\Helpers\Backup\Destination\BackupDestination;
use Tobuli\Helpers\Backup\Process\DatabaseBackuper;

class MultiDestinationUploader implements BackupUploaderInterface
{
    /** Código de excepción: ningún destino aceptó el archivo. No es un fallo del device. */
    public const ALL_DESTINATIONS_FAILED = 9001;

    private const MAX_CONSECUTIVE_FAILURES = 5;
    private const MAX_ERROR_LENGTH = 500;

    /** @var array<string, BackupDestination> */
    private array $destinations;

    private array $prepared = [];

    /**
     * @param array<string, BackupDestination> $destinations
     */
    public function __construct(array $destinations)
    {
        $this->destinations = $destinations;
    }

    /**
     * @return array<string, BackupDestination>
     */
    public function destinations(): array
    {
        return $this->destinations;
    }

    public function destination(string $name): ?BackupDestination
    {
        return $this->destinations[$name] ?? null;
    }

    public function check(): bool
    {
        foreach ($this->destinations as $destination) {
            if ($destination->check()) {
                return true;
            }
        }

        return false;
    }

    public function process($commands, BackupProcess $process, $item)
    {
        $backup = $process->backup;
        $state = $this->loadState($backup);
        $relative = BackupPathResolver::itemPath($process, $item);
        $remote = BackupPathResolver::backupFolder($backup) . '/' . $relative;
        $isDatabase = $process->type === DatabaseBackuper::class;

        $command = is_array($commands) ? implode(' | ', $commands) : $commands;
        $local = BackupTempFile::dump($command, basename($relative), $process);

        try {
            BackupTempFile::assertValid($local, $isDatabase);

            if ($isDatabase) {
                $backup->database_bytes = filesize($local);
            }

            $accepted = 0;
            $notStored = [];

            foreach ($this->destinations as $name => $destination) {
                if (!empty($state[$name]['disabled'])) {
                    $notStored[] = $name;
                    continue;
                }

                try {
                    $destination->upload($local, $remote, $process);

                    $state[$name]['uploaded']++;
                    $state[$name]['consecutive'] = 0;
                    $accepted++;
                } catch (\Throwable $e) {
                    $notStored[] = $name;
                    $state[$name]['consecutive']++;
                    $state[$name]['last_error'] = mb_substr(
                        BackupSecrets::scrub($e->getMessage()), 0, self::MAX_ERROR_LENGTH
                    );

                    if ($state[$name]['consecutive'] >= self::MAX_CONSECUTIVE_FAILURES) {
                        $state[$name]['disabled'] = true;
                    }
                }
            }

            // Si ningún destino aceptó, el elemento se reintentará: todavía no cuenta como fallo.
            if ($accepted > 0) {
                foreach ($notStored as $name) {
                    $state[$name]['failed']++;
                }
            }
        } finally {
            @unlink($local);
        }

        $backup->destinations = $state;
        $backup->save();

        if ($accepted === 0) {
            throw new \RuntimeException('No destination accepted the file: ' . $relative, self::ALL_DESTINATIONS_FAILED);
        }
    }

    private function loadState(Backup $backup): array
    {
        $state = $backup->destinations ?: [];

        $midBackup = null;

        foreach ($this->destinations as $name => $destination) {
            if (!isset($state[$name])) {
                $midBackup ??= $backup->processes()->sum('processed') > 0;

                if ($midBackup) {
                    $state[$name] = [
                        'failed'     => 1,
                        'disabled'   => true,
                        'last_error' => 'Activado a mitad del backup',
                    ];
                }
            }

            $state[$name] = ($state[$name] ?? []) + [
                'uploaded'    => 0,
                'failed'      => 0,
                'consecutive' => 0,
                'disabled'    => false,
                'last_error'  => '',
            ];
        }

        // Al reanudar: si todos los destinos estaban fuera de servicio, se vuelven a intentar.
        if (empty($this->prepared[$backup->id])) {
            $this->prepared[$backup->id] = true;

            $active = array_intersect_key($state, $this->destinations);

            if (!array_filter($active, fn ($row) => empty($row['disabled']))) {
                foreach (array_keys($active) as $name) {
                    $state[$name]['disabled'] = false;
                    $state[$name]['consecutive'] = 0;
                }
            }
        }

        return $state;
    }
}
