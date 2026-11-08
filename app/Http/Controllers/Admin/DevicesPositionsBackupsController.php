<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\PermissionException;
use App\Jobs\DevicePositionsRestoreJob;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Tobuli\Entities\Device;
use Tobuli\Exceptions\ValidationException;
use Tobuli\Helpers\Backup\BackupCatalog;
use Tobuli\Helpers\Backup\BackupService;

class DevicesPositionsBackupsController extends BaseController
{
    private const PAGE_SIZE = 20;
    private const FOLDER_RULE = 'required|regex:/^backup_[0-9-]+$/';

    private BackupService $service;

    public function __construct()
    {
        parent::__construct();

        $this->service = new BackupService(settings('backups'));

        $canRestore = Cache::remember(
            'backup_can_restore_positions',
            60,
            fn () => $this->service->canRestorePositions()
        );

        if ($canRestore === false) {
            throw new PermissionException();
        }

        if (empty($this->service->destinations())) {
            throw new ValidationException(['message' => trans('front.invalid_ftp_settings')]);
        }
    }

    public function index(int $id)
    {
        return $this->getList($id, 1);
    }

    public function table(int $id)
    {
        return $this->getList($id, request()->input('page', 1), '_table');
    }

    private function getList(int $id, int $page, string $viewPostfix = '')
    {
        $device = $this->getDevice($id);
        $date = $this->filterDate();

        $entries = (new BackupCatalog($this->service))->entries($device, $date);
        $total = count($entries);
        $slice = array_slice($entries, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);

        $folders = (new LengthAwarePaginator($slice, $total, self::PAGE_SIZE, $page))
            ->setPath(route('admin.objects.positions_backups.table', $id));

        $cutoff = settings('db_clear.status')
            ? Carbon::now()->subDays(BackupCatalog::historyDays())->toDateTimeString()
            : null;

        return view('Frontend.Devices.positions_backups' . $viewPostfix)
            ->with(compact('id', 'folders', 'cutoff', 'date'));
    }

    public function upload(int $id)
    {
        $device = $this->getDevice($id);

        $this->validate(request(), ['folder' => self::FOLDER_RULE]);

        dispatch(new DevicePositionsRestoreJob($device, request('folder'), $this->user));

        return ['status' => 1];
    }

    public function download(int $id)
    {
        $device = $this->getDevice($id);

        $this->validate(request(), ['folder' => self::FOLDER_RULE]);

        set_time_limit(0);

        $path = (new BackupCatalog($this->service))->fetch($device, request('folder'));

        if ($path === null) {
            throw new ValidationException(['folder' => trans('global.not_found')]);
        }

        $name = substr(basename($path), strpos(basename($path), '-') + 1);

        return response()->download($path, $name)->deleteFileAfterSend();
    }

    private function filterDate(): ?string
    {
        $date = request()->input('date');

        return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
    }

    private function getDevice(int $id): Device
    {
        $item = Device::findOrFail($id);

        $this->checkException('devices', 'view', $item);

        return $item;
    }
}
