<?php

namespace App\Http\Controllers\Ui\V2;

use CustomFacades\ModalHelpers\ReportLogModalHelper;
use CustomFacades\ModalHelpers\ReportModalHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Tobuli\Entities\Report;
use Tobuli\Entities\ReportLog;
use Tobuli\Exceptions\ValidationException;
use Tobuli\Reports\ReportManager;

/**
 * TalentusPro · ReportsController (ui/v2)
 * -----------------------------------------------------------------
 * Módulo Reportes rediseñado: página con tabs "Reportes generados"
 * (tabla `reports` = definiciones guardadas/programadas) y "Logs de
 * programados" (`report_logs`), y modal Generar/Guardar.
 *
 * Toda la lógica delega en el legacy: ReportModalHelper (createData/
 * create/destroy — el guardado incluye los flags de schedule daily/
 * weekly/monthly que procesa el cron reports:daily), ReportManager
 * (catálogo de tipos, campos habilitados y parámetros dinámicos vía
 * reports.types.show) y ReportLogModalHelper (descarga/limpieza).
 * GENERAR usa la ruta legacy reports.update (ANY) con generate=1.
 * -----------------------------------------------------------------
 */
class ReportsController extends BaseController
{
    public function index(Request $request)
    {
        if (! $this->user->perm('reports', 'view')) {
            abort(403, 'No tienes permiso para ver reportes.');
        }

        return View::make('ui.v2.pages.reports', [
            'pageTitle' => trans('front.reports'),
            'canCreate' => (bool) $this->user->perm('reports', 'edit'),
            'canRemove' => (bool) $this->user->perm('reports', 'remove'),
            'openModal' => (bool) $request->get('create'),
        ]);
    }

    /**
     * Catálogos del modal: tipos habilitados, formatos, geocercas,
     * metas, stops y devices agrupados. Reutiliza createData() legacy.
     */
    public function meta(Request $request): JsonResponse
    {
        $this->checkException('reports', 'view');

        $manager = new ReportManager();

        $templates = [];
        foreach ($manager->getUserEnabledNameList($this->user) as $id => $title) {
            $templates[] = ['id' => (int) $id, 'title' => (string) $title];
        }

        $formats = [];
        foreach (ReportManager::getFormats() as $fid => $flabel) {
            $formats[] = ['id' => (string) $fid, 'label' => (string) $flabel];
        }

        $geofences = \Tobuli\Entities\Geofence::userAccessible($this->user)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function ($g) {
                return ['id' => (int) $g->id, 'name' => (string) $g->name];
            })
            ->all();

        // Stops (segundos) — mismo catálogo del form legacy
        $stops = [];
        foreach ((array) config('tobuli.stops_seconds', []) as $sec => $label) {
            $stops[] = ['value' => (string) $sec, 'label' => (string) $label];
        }

        // Metas del form legacy (createData) — opcionales según instalación
        $metas = [];
        try {
            ReportModalHelper::setUser($this->user);
            ReportModalHelper::setApi(false);
            $legacy = ReportModalHelper::createData();
            foreach ((array) ($legacy['metas'] ?? []) as $mid => $mlabel) {
                $metas[] = ['value' => (string) $mid, 'label' => (string) $mlabel];
            }
        } catch (\Throwable $e) {
        }

        $groups = $this->user->deviceGroups()
            ->orderBy('title')
            ->get(['id', 'title'])
            ->map(function ($g) {
                return ['id' => (int) $g->id, 'title' => (string) $g->title];
            })
            ->all();

        $devices = $this->user->devices()
            ->clearOrdersBy()
            ->orderBy('name')
            ->get()
            ->map(function ($d) {
                return [
                    'id'       => (int) $d->id,
                    'name'     => (string) $d->name,
                    'group_id' => (int) ($d->pivot->group_id ?? 0),
                ];
            })
            ->all();

        return response()->json([
            'ok'        => true,
            'templates' => $templates,
            'formats'   => $formats,
            'geofences' => $geofences,
            'stops'     => $stops,
            'metas'     => $metas,
            'groups'    => $groups,
            'devices'   => $devices,
            // URLs legacy que consume el modal
            'typeUrl'     => url('/reports/types') . '/{type}',
            'generateUrl' => url('/reports/update'),
            'showFtp'     => (bool) config('addon.report_send_to_ftp'),
        ]);
    }

    /** Tab 1: definiciones guardadas/programadas (tabla reports). */
    public function saved(Request $request): JsonResponse
    {
        $this->checkException('reports', 'view');

        $perPage = min(100, max(10, (int) $request->get('per_page', 20)));

        $query = Report::where('user_id', $this->user->id)
            ->withCount(['devices', 'geofences']);

        if ($s = trim((string) $request->get('s'))) {
            $query->where('title', 'like', "%{$s}%");
        }

        if ($type = (int) $request->get('type')) {
            $query->where('type', $type);
        }

        $items = $query->orderBy('id', 'desc')->paginate($perPage);

        $manager = new ReportManager();

        $rows = [];
        foreach ($items as $report) {
            $schedule = [];
            if ($report->daily) {
                $schedule[] = 'Diario ' . $report->daily_time;
            }
            if ($report->weekly) {
                $schedule[] = 'Semanal ' . $report->weekly_time;
            }
            if ($report->monthly) {
                $schedule[] = 'Mensual ' . $report->monthly_time;
            }

            $typeLabel = '';
            try {
                $typeLabel = (string) $manager::getTitle($report->type);
            } catch (\Throwable $e) {
                $typeLabel = '#' . $report->type;
            }

            $rows[] = [
                'id'              => (int) $report->id,
                'title'           => (string) $report->title,
                'type'            => (int) $report->type,
                'type_label'      => $typeLabel,
                'format'          => strtoupper((string) $report->format),
                'devices_count'   => (int) $report->devices_count,
                'geofences_count' => (int) $report->geofences_count,
                'schedule'        => implode(' · ', $schedule),
            ];
        }

        return response()->json([
            'ok'    => true,
            'items' => $rows,
            'page'  => $items->currentPage(),
            'last'  => $items->lastPage(),
            'total' => $items->total(),
        ]);
    }

    /** Tab 2: logs de reportes programados enviados (report_logs). */
    public function logs(Request $request): JsonResponse
    {
        $this->checkException('reports', 'view');

        $perPage = min(100, max(10, (int) $request->get('per_page', 20)));

        $query = ReportLog::userAccessible($this->user);

        if ($s = trim((string) $request->get('s'))) {
            $query->where(function ($q) use ($s) {
                $q->where('title', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%");
            });
        }

        if ($type = (int) $request->get('type')) {
            $query->where('type', $type);
        }

        $items = $query->orderBy('id', 'desc')->paginate($perPage);

        $manager = new ReportManager();

        $rows = [];
        foreach ($items as $log) {
            $typeLabel = '';
            try {
                $typeLabel = (string) $manager::getTitle($log->type);
            } catch (\Throwable $e) {
                $typeLabel = '#' . $log->type;
            }

            $size = (int) $log->size;
            $sizeText = $size >= 1048576
                ? round($size / 1048576, 1) . ' MB'
                : ($size >= 1024 ? round($size / 1024) . ' KB' : $size . ' B');

            $rows[] = [
                'id'         => (int) $log->id,
                'title'      => (string) $log->title,
                'type_label' => $typeLabel,
                'format'     => strtoupper((string) $log->format),
                'size'       => $sizeText,
                'is_send'    => (bool) $log->is_send,
                'email'      => (string) ($log->email ?? ''),
                'created_at' => (string) $log->created_at,
                'download'   => route('reports.log_download', $log->id),
            ];
        }

        return response()->json([
            'ok'    => true,
            'items' => $rows,
            'page'  => $items->currentPage(),
            'last'  => $items->lastPage(),
            'total' => $items->total(),
        ]);
    }

    /** Datos de una definición guardada (para editar en el modal). */
    public function item(Request $request, $id): JsonResponse
    {
        $this->checkException('reports', 'view');

        $report = Report::where('user_id', $this->user->id)->find((int) $id);
        if (! $report) {
            abort(404);
        }

        $fromF = (string) ($report->from_formated ?? '');
        $toF   = (string) ($report->to_formated ?? '');

        return response()->json([
            'ok'   => true,
            'item' => [
                'id'                 => (int) $report->id,
                'title'              => (string) $report->title,
                'type'               => (int) $report->type,
                'format'             => (string) $report->format,
                // Periodo rapido guardado (Hoy/Ayer/Esta semana...). Sin esto
                // el modal reabria siempre en "Personalizado".
                'period'             => (string) ($report->period ?? ''),
                'date_from'          => substr($fromF, 0, 10),
                'from_time'          => substr($fromF, 11, 5) ?: '00:00',
                'date_to'            => substr($toF, 0, 10),
                'to_time'            => substr($toF, 11, 5) ?: '00:00',
                'speed_limit'        => $report->speed_limit,
                'stops'              => (string) ($report->stops ?? ''),
                'show_addresses'     => (bool) $report->show_addresses,
                'zones_instead'      => (bool) $report->zones_instead,
                'skip_blank_results' => (bool) $report->skip_blank_results,
                'metas'              => (array) ($report->metas ?? []),
                'parameters'         => (array) ($report->parameters ?? []),
                'email'              => (string) ($report->email ?? ''),
                // URL FTP tal cual (string) — castearlo a bool rompía el
                // round-trip: el modal reenviaba "1" y pisaba la URL guardada.
                'ftp'                => (string) ($report->ftp ?? ''),
                'daily'              => (bool) $report->daily,
                'daily_time'         => (string) ($report->daily_time ?? '00:00'),
                'weekly'             => (bool) $report->weekly,
                'weekly_time'        => (string) ($report->weekly_time ?? '00:00'),
                'weekly_day'         => $report->weekly_day ? (int) $report->weekly_day : null,
                'monthly'            => (bool) $report->monthly,
                'monthly_time'       => (string) ($report->monthly_time ?? '00:00'),
                'monthly_day'        => $report->monthly_day ? (int) $report->monthly_day : null,
                'period_weekdays'    => (array) ($report->period_weekdays ?? []),
                'period_time_from'   => (string) ($report->period_time_from ?? ''),
                'period_time_to'     => (string) ($report->period_time_to ?? ''),
                'device_ids'         => $report->devices()->pluck('devices.id')->map(function ($v) {
                    return (int) $v;
                })->all(),
                'geofence_ids'       => $report->geofences()->pluck('geofences.id')->map(function ($v) {
                    return (int) $v;
                })->all(),
            ],
        ]);
    }

    /** Guardar definición (crear o editar: mismo create() legacy con id). */
    public function store(Request $request): JsonResponse
    {
        try {
            $data = $request->all();

            // Día concreto del schedule (extensión): normalizar o limpiar.
            $wd = (int) ($data['weekly_day'] ?? 0);
            $data['weekly_day'] = ($wd >= 1 && $wd <= 7) ? $wd : null;
            $md = (int) ($data['monthly_day'] ?? 0);
            $data['monthly_day'] = ($md >= 1 && $md <= 31) ? $md : null;

            // Filtro de días/franja persistido (null = sin filtro)
            if (empty($data['period_weekdays']) || count((array) $data['period_weekdays']) >= 7) {
                $data['period_weekdays'] = null;
            }
            $data['period_time_from'] = ! empty($data['period_time_from']) ? $data['period_time_from'] : null;
            $data['period_time_to']   = ! empty($data['period_time_to']) ? $data['period_time_to'] : null;

            ReportModalHelper::setUser($this->user);
            ReportModalHelper::setData($data);
            ReportModalHelper::setApi(false);

            ReportModalHelper::create();

            return response()->json(['ok' => true]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }
    }

    /** Eliminar definición guardada. */
    public function destroy(Request $request, $id): JsonResponse
    {
        $report = Report::where('user_id', $this->user->id)->find((int) $id);
        if (! $report) {
            abort(404);
        }

        ReportModalHelper::setUser($this->user);
        ReportModalHelper::setData(['id' => (int) $id]);
        ReportModalHelper::destroy();

        return response()->json(['ok' => true]);
    }

    /** Eliminar un log de reporte enviado. */
    public function logDestroy(Request $request, $id): JsonResponse
    {
        ReportLogModalHelper::setUser($this->user);
        ReportLogModalHelper::setData(['id' => (int) $id]);
        ReportLogModalHelper::destroy();

        return response()->json(['ok' => true]);
    }
}
