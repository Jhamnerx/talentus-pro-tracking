<?php

namespace Tobuli\Helpers\Backup;

use Illuminate\Database\Eloquent\Builder;
use Tobuli\Entities\Backup;
use Tobuli\Entities\BackupProcess;
use Tobuli\Helpers\Backup\Process\AbstractBackuper;
use Tobuli\Helpers\Backup\Uploader\BackupUploaderInterface;
use Tobuli\Helpers\Backup\Uploader\MultiDestinationUploader;

class ProcessManager
{
    private MultiDestinationUploader $uploader;
    private Backup $backup;

    public function __construct(MultiDestinationUploader $uploader)
    {
        $this->uploader = $uploader;
    }

    public function handle(): void
    {
        $processes = $this->backup->processes()->get();

        foreach ($processes as $process) {
            if ($this->isProcessRunnable($process) === false) {
                continue;
            }

            try {
                $this->resolveBackuper($process, $this->uploader)->run();
            } catch (\Throwable $e) {
                $error = BackupSecrets::scrub($e->getCode() . ': ' . $e->getMessage());

                $this->backup->update([
                    'message' => (new ErrorTranslator())->translate($e),
                    'details' => $error,
                ]);

                (new BackupNotifier())->notifyOnce(
                    $this->backup,
                    'failed-' . $process->id,
                    trans('backups.mail_failed_subject', ['name' => $this->backup->name]),
                    trans('backups.mail_failed_body', ['name' => $this->backup->name, 'error' => $error])
                );

                throw $e;
            }
        }

        if ($this->backup->isCompleted()) {
            (new BackupFinalizer($this->uploader))->finalize($this->backup->refresh());
        }
    }

    private function isProcessRunnable(BackupProcess $process): bool
    {
        if ($process->isRunnable() === false) {
            return false;
        }

        $now = date('Y-m-d H:i:s');

        return (bool)BackupProcess::where('id', $process->id)
            ->where(fn (Builder $query) => $query
                ->where('reserved_at', $process->reserved_at)
                ->orWhereNull('reserved_at')
            )
            ->toBase()
            ->update(['reserved_at' => $now]);
    }

    public static function resolveBackuper(BackupProcess $process, BackupUploaderInterface $uploader): AbstractBackuper
    {
        return new $process->type($process, $uploader);
    }

    public function setBackup(Backup $backup): self
    {
        $this->backup = $backup;

        return $this;
    }
}
