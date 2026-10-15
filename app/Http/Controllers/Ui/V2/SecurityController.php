<?php

namespace App\Http\Controllers\Ui\V2;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Tobuli\Entities\SecurityDailyStat;
use Tobuli\Entities\SecuritySetting;
use Tobuli\Entities\VehicleType;
use Tobuli\Exports\Security\SecurityWorkbookExport;
use Tobuli\Services\Security\SecurityDailyCalculator;
use Tobuli\Services\Security\SecurityLiveStatus;
use Tobuli\Services\Security\SecuritySummary;

/**
 * Seguridad · Control de flota (UI v2).
 *
 * Pantalla + API JSON del dashboard de seguridad: tarjetas, resumen diario,
 * detalle por unidad, estado en vivo, ajustes del usuario y recalculo corto.
 */
class SecurityController extends BaseController
{
    /** Mismo gate que Operaciones: el modulo no esta en el modelMap del exception Manager, asi que checkException no aplica. */
    private function gate(string $action): void
    {
        if (! $this->user->perm('security', $action)) {
            abort(403);
        }
    }

    public function page()
    {
        $this->gate('view');
        SecuritySetting::forUser($this->user); // alta implicita: el comando nocturno solo agrega usuarios con ajustes

        return View::make('ui.v2.pages.security');
    }

    /** Filtros disponibles y ajustes del usuario. */
    public function meta(): JsonResponse
    {
        $this->gate('view');
        $settings = SecuritySetting::forUser($this->user);

        return response()->json([
            'ok'       => true,
            'can_edit' => $this->user->perm('security', 'edit'),
            'settings' => $settings->only(['day_start', 'day_end', 'speed_limit', 'validity_minutes', 'stop_minutes', 'gap_minutes']),
            'shifts'   => [
                'all'   => trans('ui.security_shift_both'),
                'day'   => trans('ui.security_shift_day'),
                'night' => trans('ui.security_shift_night'),
            ],
            'types'    => VehicleType::where('user_id', $this->user->id)->orderBy('name')->get(['id', 'name']),
            'devices'  => $this->user->devices()->clearOrdersBy()->orderBy('devices.name')->get(['devices.id', 'devices.name', 'devices.vehicle_type_id']),
            'last_calculated' => optional(SecurityDailyStat::where('user_id', $this->user->id)->max('calculated_at')),
        ]);
    }

    /** Tarjetas, resumen diario, detalle por unidad y estado en vivo, con los filtros del Excel. */
    public function data(Request $request): JsonResponse
    {
        $this->gate('view');
        $stats = $this->filtered($request)->with('device.vehicleType')->get();
        $summary = new SecuritySummary();
        $devices = $this->filteredDevices($request);

        return response()->json([
            'ok'       => true,
            'cards'    => $summary->cards($stats),
            'daily'    => $summary->daily($stats),
            'per_unit_daily' => $summary->perUnitDaily($stats),
            'per_unit' => $summary->perUnit($stats),
            'live'     => (new SecurityLiveStatus(SecuritySetting::forUser($this->user)))->rows($devices),
        ]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $this->gate('edit');

        $input = $request->only(['day_start', 'day_end', 'speed_limit', 'validity_minutes', 'stop_minutes', 'gap_minutes']);
        // El formulario manda '' cuando el limite manual esta vacio: equivale a "sin limite".
        if (array_key_exists('speed_limit', $input) && $input['speed_limit'] === '') {
            $input['speed_limit'] = null;
        }

        $validator = Validator::make($input, [
            'day_start'        => 'required|date_format:H:i',
            'day_end'          => [
                'required',
                'date_format:H:i',
                function ($attribute, $value, $fail) use ($input) {
                    // Ambos ya son 'HH:MM', asi que la comparacion de cadenas es cronologica.
                    if (isset($input['day_start']) && strcmp((string) $value, (string) $input['day_start']) <= 0) {
                        $fail(trans('ui.security_day_end_after_start'));
                    }
                },
            ],
            'speed_limit'      => 'nullable|integer|min:1|max:300',
            'validity_minutes' => 'required|integer|min:1|max:1440',
            'stop_minutes'     => 'required|integer|min:1|max:120',
            'gap_minutes'      => 'required|integer|min:1|max:240',
        ]);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'errors' => $validator->errors()->toArray()], 422);
        }

        $data = $validator->validated();
        $data['speed_limit'] = $data['speed_limit'] ?? null;

        SecuritySetting::forUser($this->user)->update($data);

        return response()->json(['ok' => true]);
    }

    /** Recalculo sincrono de un rango corto (<= 31 dias); el nocturno cubre el resto. */
    public function recalculate(Request $request): JsonResponse
    {
        $this->gate('edit');

        $validator = Validator::make($request->all(), [
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'errors' => $validator->errors()->toArray()], 422);
        }

        $data = $validator->validated();
        if (Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) > 31) {
            return response()->json(['ok' => false, 'errors' => ['to' => [trans('ui.security_range_too_long')]]], 422);
        }

        $devices = $request->get('devices');
        $devices = is_array($devices) && count($devices) ? array_map('intval', $devices) : null;

        $n = (new SecurityDailyCalculator($this->user))->calculateRange($data['from'], $data['to'], $devices);

        return response()->json(['ok' => true, 'rows' => $n]);
    }

    public function export(Request $request)
    {
        $this->gate('view');
        $stats = $this->filtered($request)->with('device.vehicleType')->get();

        return (new SecurityWorkbookExport($this->user, $stats, $this->filteredDevices($request), $request->all()))
            ->download('Dashboard_Seguridad_' . now()->format('Ymd_Hi') . '.xlsx');
    }

    private function filtered(Request $request)
    {
        return SecurityDailyStat::where('user_id', $this->user->id)
            ->whereBetween('work_date', [$request->get('from', now()->subDays(15)->toDateString()), $request->get('to', now()->toDateString())])
            ->when($request->get('shift') && $request->get('shift') !== 'all', fn($q) => $q->where('shift', $request->get('shift')))
            ->when($request->get('device_id'), fn($q, $id) => $q->where('device_id', $id))
            ->when($request->get('type_id'), fn($q, $t) => $q->whereIn('device_id', $this->user->devices()->clearOrdersBy()->where('vehicle_type_id', $t)->pluck('devices.id')))
            ->orderBy('work_date')
            ->orderBy('device_id');
    }

    private function filteredDevices(Request $request)
    {
        return $this->user->devices()->clearOrdersBy()->with(['traccar', 'vehicleType'])
            ->when($request->get('device_id'), fn($q, $id) => $q->where('devices.id', $id))
            ->when($request->get('type_id'), fn($q, $t) => $q->where('vehicle_type_id', $t))
            ->orderBy('devices.name')->get();
    }
}
