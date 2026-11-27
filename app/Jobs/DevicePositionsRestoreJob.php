<?php

namespace App\Jobs;

use App\Events\NoticeEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Tobuli\Entities\Device;
use Tobuli\Entities\User;
use Tobuli\Helpers\Backup\BackupCatalog;
use Tobuli\Helpers\Backup\BackupService;

class DevicePositionsRestoreJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $timeout = 7200;
    public int $tries = 1;

    public Device $device;
    public string $folder;
    public ?User $actor;

    public function __construct(Device $device, string $folder, ?User $actor)
    {
        $this->device = $device;
        $this->folder = $folder;
        $this->actor = $actor;

        $this->onConnection('redis_backups');
        $this->onQueue('backups');
    }

    public function handle(): void
    {
        $catalog = new BackupCatalog(new BackupService(settings('backups')));

        try {
            $file = $catalog->fetch($this->device, $this->folder);

            if ($file === null) {
                throw new \RuntimeException("Backup file not found in {$this->folder}");
            }
        } catch (\Throwable $e) {
            if ($this->actor) {
                event(new NoticeEvent($this->actor, NoticeEvent::TYPE_ERROR, trans('front.upload_failed')));
            }

            throw $e;
        }

        dispatch_sync(new DevicePositionsImportJob($this->device, $file, $this->actor));
    }
}
