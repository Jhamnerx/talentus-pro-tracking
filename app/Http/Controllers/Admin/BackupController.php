<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Support\Facades\View;
use Tobuli\Entities\Backup;
use Tobuli\Helpers\Backup\BackupPathResolver;
use Tobuli\Helpers\Backup\BackupRetention;
use Tobuli\Helpers\Backup\BackupService;
use Tobuli\Helpers\Backup\Destination\BackupDestination;

class BackupController extends BaseController
{
    public function index()
    {
        return $this->getList('index');
    }

    public function table()
    {
        return $this->getList('table');
    }

    private function getList(string $view)
    {
        $items = Backup::toPaginator(
            10,
            request()->input('sorting.sort_by', 'name'),
            request()->input('sorting.sort', 'DESC')
        )->setPath(route('admin.backup.table'));

        return View::make("Admin.Backup.$view")->with(compact('items'));
    }

    public function processes(int $id)
    {
        $items = Backup::findOrFail($id)->processes;

        return View::make('Admin.Backup.partials.processes')->with(compact('items'));
    }

    public function protect(int $id)
    {
        $backup = Backup::findOrFail($id);
        $drive = (new BackupService(settings('backups')))->destination(BackupDestination::DRIVE);

        // Las copias sin posiciones cambian de prefijo al aplicar la retención.
        $folder = BackupPathResolver::backupFolder($backup);

        if (!$drive->exists($folder)) {
            $folder = 'soloweb_' . substr($folder, strlen('backup_'));
        }

        if ($backup->protected) {
            $drive->deleteFile($folder . '/' . BackupRetention::PROTECT_FILE);
        } else {
            $drive->put($folder . '/' . BackupRetention::PROTECT_FILE, date('c'));
        }

        $backup->update(['protected' => !$backup->protected]);

        return redirect()->route('admin.backup.index');
    }
}
