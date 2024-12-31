<?php

namespace Tobuli\Services;

use App\Exceptions\DeviceLimitException;
use App\Jobs\DeleteDatabaseTable;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tobuli\Entities\Device;
use Tobuli\Entities\DeviceGroup;
use Tobuli\Entities\User;
use CustomFacades\Server;

class DeviceImageService
{
    const IMAGE_PATH = 'images/device_images/';

    /**
     * @var Device
     */
    protected $device;

    public function __construct(Device $device)
    {
        $this->device = $device;
    }

    public function get()
    {
        $path = Str::finish(self::IMAGE_PATH, '/') . "{$this->device->id}.*";

        return File::glob($path)[0] ?? null;
    }

    public function save($image)
    {
        $path = Str::finish(self::IMAGE_PATH, '/');

        if (!File::exists($path)) {
            File::makeDirectory($path, 0755, true);
        }

        $this->deleteExisting($path);

        $filename = $this->device->id.'.'.Str::random().'.'.$image->getClientOriginalExtension();

        if (! $image->move($path, $filename)) {
            throw new \Exception(trans('global.failed_file_save'));
        }
    }

    public function delete()
    {
        $path = Str::finish(self::IMAGE_PATH, '/');

        if (! File::exists($path)) {
            return;
        }

        $this->deleteExisting($path);
    }

    private function deleteExisting($path)
    {
        $existingFiles = File::glob("{$path}{$this->device->id}.*");

        if (! empty($existingFiles)) {
            File::delete($existingFiles);
        }
    }
}
