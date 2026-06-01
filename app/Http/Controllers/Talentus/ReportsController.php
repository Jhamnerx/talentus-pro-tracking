<?php

namespace App\Http\Controllers\Talentus;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Tobuli\Entities\Report;
use Tobuli\Entities\ReportLog;
use Tobuli\Reports\ReportManager;

/**
 * TalentusPro · ReportsController
 * Hub de reportes: catálogo + saved + logs + generación.
 */
class ReportsController extends BaseController
{
    public function index(Request $request)
    {
        if (! $this->user->perm('reports', 'view')) {
            abort(403, 'No tienes permiso para ver reportes.');
        }

        return View::make('talentus.pages.reports', [
            'pageTitle' => 'Reports',
            'canCreate' => (bool) $this->user->perm('reports', 'edit'),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        if (! $this->user->perm('reports', 'view')) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

        // ---- Templates (report types) ----
        $templates = [];
        try {
            $rm    = (new ReportManager())->setUser($this->user);
            $names = $rm->getUserEnabledNameList($this->user);
            foreach ($names as $id => $title) {
                $isDeviceless = false;
                try {
                    $isDeviceless = (bool) $rm->report($id)->isDeviceless();
                } catch (\Throwable $e) {
                }
                $templates[] = [
                    'id'         => (int) $id,
                    'title'      => (string) $title,
                    'deviceless' => $isDeviceless,
                ];
            }
        } catch (\Throwable $e) {
            $templates = [];
        }

        // ---- Formats ----
        $formats = [];
        try {
            foreach (ReportManager::getFormats() as $k => $v) {
                $formats[] = ['id' => $k, 'title' => $v];
            }
        } catch (\Throwable $e) {
            $formats = [
                ['id' => 'pdf',  'title' => 'PDF'],
                ['id' => 'xlsx', 'title' => 'XLSX'],
                ['id' => 'csv',  'title' => 'CSV'],
                ['id' => 'html', 'title' => 'HTML'],
            ];
        }

        // ---- Saved reports ----
        $saved = [];
        try {
            $items = Report::where('user_id', $this->user->id)
                ->withCount(['devices'])
                ->orderBy('id', 'desc')
                ->limit(200)
                ->get(['id', 'title', 'type', 'format', 'daily', 'weekly', 'monthly', 'email']);
            foreach ($items as $r) {
                $sched = [];
                if ($r->daily)   $sched[] = 'diario';
                if ($r->weekly)  $sched[] = 'semanal';
                if ($r->monthly) $sched[] = 'mensual';
                $saved[] = [
                    'id'            => (int) $r->id,
                    'title'         => (string) $r->title,
                    'type'          => (int) $r->type,
                    'type_label'    => $templates ? (collect($templates)->firstWhere('id', (int) $r->type)['title'] ?? ('Tipo ' . $r->type)) : ('Tipo ' . $r->type),
                    'format'        => (string) $r->format,
                    'devices_count' => (int) ($r->devices_count ?? 0),
                    'schedule'      => $sched,
                    'has_email'     => ! empty($r->email),
                ];
            }
        } catch (\Throwable $e) {
            $saved = [];
        }

        // ---- Run logs ----
        $logs = [];
        try {
            $rows = ReportLog::where('user_id', $this->user->id)
                ->orderBy('id', 'desc')
                ->limit(50)
                ->get(['id', 'title', 'type', 'format', 'size', 'is_send', 'is_upload', 'error', 'created_at']);
            foreach ($rows as $r) {
                $logs[] = [
                    'id'         => (int) $r->id,
                    'title'      => (string) $r->title,
                    'type'       => (int) $r->type,
                    'format'     => (string) $r->format,
                    'size'       => (int) ($r->size ?? 0),
                    'is_send'    => (int) ($r->is_send ?? 0),
                    'is_upload'  => (int) ($r->is_upload ?? 0),
                    'error'      => $r->error ? (string) $r->error : null,
                    'created_at' => (string) $r->created_at,
                    'download'   => route('reports.log_download', ['id' => $r->id]),
                ];
            }
        } catch (\Throwable $e) {
            $logs = [];
        }

        // ---- Devices (lite) ----
        $devices = [];
        try {
            $this->user->devices()->orderBy('name')->limit(2000)
                ->get(['devices.id', 'devices.name', 'devices.plate_number'])
                ->each(function ($d) use (&$devices) {
                    $devices[] = ['id' => (int) $d->id, 'name' => (string) $d->name, 'plate' => (string) ($d->plate_number ?? '')];
                });
        } catch (\Throwable $e) {
            $devices = [];
        }

        return response()->json([
            'ok'        => true,
            'templates' => $templates,
            'formats'   => $formats,
            'saved'     => $saved,
            'logs'      => $logs,
            'devices'   => $devices,
            'legacy'    => [
                'list'     => url('/reports'),
                'create'   => url('/reports'),
                'edit'     => url('/reports'),
                'generate' => url('/reports/update'),
                'logs'     => url('/reports/logs'),
            ],
        ]);
    }
}
