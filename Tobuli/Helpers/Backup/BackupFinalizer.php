<?php

namespace Tobuli\Helpers\Backup;

use Tobuli\Entities\Backup;
use Tobuli\Helpers\Backup\Destination\BackupDestination;
use Tobuli\Helpers\Backup\Destination\DriveDestination;
use Tobuli\Helpers\Backup\Process\DevicesPositionsBackuper;
use Tobuli\Helpers\Backup\Uploader\MultiDestinationUploader;

class BackupFinalizer
{
    private const LOW_SPACE_RATIO = 0.10;
    private const BYTES_IN_GB = 1073741824;

    private MultiDestinationUploader $uploader;
    private BackupIndex $index;
    private BackupNotifier $notifier;

    public function __construct(MultiDestinationUploader $uploader)
    {
        $this->uploader = $uploader;
        $this->index = new BackupIndex();
        $this->notifier = new BackupNotifier();
    }

    public function finalize(Backup $backup): void
    {
        $problems = [];
        $healthy = [];

        $failedDevices = [];
        foreach ($backup->processes as $process) {
            foreach ($process->options['failed_items'] ?? [] as $id) {
                $failedDevices[] = $id;
            }
        }
        $failedDevices = array_values(array_unique($failedDevices));

        if ($failedDevices) {
            $problems[] = 'devices: ' . implode(', ', $failedDevices);
        }

        foreach ($this->uploader->destinations() as $name => $destination) {
            $status = $backup->destinationStatus($name);

            if ($status !== Backup::DESTINATION_OK) {
                $problems[] = $name . ': ' . trans('backups.status_' . ($status ?: Backup::DESTINATION_FAILED))
                    . ' (' . ($backup->destinations[$name]['failed'] ?? 0) . ') '
                    . ($backup->destinations[$name]['last_error'] ?? '');
                continue;
            }

            try {
                $this->index->publish($backup, $destination, $failedDevices);
                $healthy[] = $name;
            } catch (\Throwable $e) {
                $problems[] = $name . ': ' . BackupSecrets::scrub($e->getMessage());
            }
        }

        $this->index->forgetRanges($backup->id);

        $backup->update([
            'message' => $problems
                ? trans('backups.completed_with_errors', ['list' => implode('; ', array_map(fn ($p) => strtok($p, ':'), $problems))])
                : trans('front.successfully_uploaded'),
            'details' => $problems ? BackupSecrets::scrub(implode("\n", $problems)) : null,
        ]);

        if ($problems) {
            $this->notifier->notifyOnce(
                $backup,
                'problems',
                trans('backups.mail_problems_subject', ['name' => $backup->name]),
                trans('backups.mail_problems_body', ['name' => $backup->name, 'list' => implode("\n", $problems)])
            );
        }

        if (in_array(BackupDestination::DRIVE, $healthy, true)) {
            $this->afterDriveSuccess($backup);
        }
    }

    /**
     * Cierra un backup que terminó con algún proceso fallido: no publica índice ni aplica retención.
     */
    public function closeFailed(Backup $backup): void
    {
        $lines = [];

        foreach ($this->uploader->destinations() as $name => $destination) {
            $status = $backup->destinationStatus($name);

            $lines[] = $name . ': ' . trans('backups.status_' . ($status ?: Backup::DESTINATION_FAILED))
                . ' (' . ($backup->destinations[$name]['failed'] ?? 0) . ') '
                . ($backup->destinations[$name]['last_error'] ?? '');
        }

        foreach ($backup->processes as $process) {
            if ($process->isCompleted()) {
                continue;
            }

            $lines[] = class_basename($process->type) . ': '
                . ($process->isFailed() ? trans('global.failed') : trans('front.expired'))
                . ($process->source ? " ({$process->source})" : '');
        }

        if (empty($backup->message)) {
            $backup->update([
                'message' => trans('backups.completed_with_errors', [
                    'list' => implode('; ', array_map(fn ($line) => strtok($line, ':'), $lines)),
                ]),
            ]);
        }

        $this->index->forgetRanges($backup->id);

        $this->notifier->notifyOnce(
            $backup,
            'problems',
            trans('backups.mail_problems_subject', ['name' => $backup->name]),
            BackupSecrets::scrub(
                trans('backups.mail_problems_body', ['name' => $backup->name, 'list' => implode("\n", $lines)])
            )
        );
    }

    private function afterDriveSuccess(Backup $backup): void
    {
        /** @var DriveDestination $drive */
        $drive = $this->uploader->destination(BackupDestination::DRIVE);

        try {
            // Sin posiciones copiadas no hay evidencia de que el backup sea bueno: no se borra nada.
            if ($this->positionsProcessed($backup) > 0) {
                $retention = new BackupRetention($drive, settings('backups') ?: []);
                $retention->apply($retention->plan());
            }
        } catch (\Throwable $e) {
            $this->notifier->notifyOnce(
                $backup,
                'prune',
                trans('backups.mail_prune_subject'),
                $e->getMessage()
            );
        }

        try {
            $this->checkSpace($backup, $drive);
        } catch (\Throwable $e) {
            $this->notifier->notifyOnce(
                $backup,
                'space_check',
                trans('backups.mail_prune_subject'),
                $e->getMessage()
            );
        }
    }

    private function positionsProcessed(Backup $backup): int
    {
        return (int)$backup->processes()
            ->where('type', DevicesPositionsBackuper::class)
            ->sum('processed');
    }

    private function checkSpace(Backup $backup, DriveDestination $drive): void
    {
        $about = $drive->about();

        if ($about['total'] > 0 && $about['free'] / $about['total'] < self::LOW_SPACE_RATIO) {
            $this->notifier->notifyOnce(
                $backup,
                'space',
                trans('backups.mail_space_subject'),
                trans('backups.mail_space_body', [
                    'free'  => round($about['free'] / self::BYTES_IN_GB, 1),
                    'total' => round($about['total'] / self::BYTES_IN_GB, 1),
                ])
            );
        }
    }
}
