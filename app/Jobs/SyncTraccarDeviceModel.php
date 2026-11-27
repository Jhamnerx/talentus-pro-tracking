<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Tobuli\Entities\Device;
use Tobuli\Services\TraccarDeviceModelService;

class SyncTraccarDeviceModel extends Job implements ShouldQueue
{
    use InteractsWithQueue, SerializesModels;

    public $tries = 3;

    public $backoff = 60;

    private $device;

    public function __construct(Device $device)
    {
        $this->device = $device;
    }

    public function handle(TraccarDeviceModelService $service)
    {
        $service->sync($this->device);
    }
}
