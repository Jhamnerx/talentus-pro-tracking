<?php

namespace Tobuli\Exports\Security;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Tobuli\Entities\SecuritySetting;
use Tobuli\Entities\User;
use Tobuli\Entities\VehicleType;
use Tobuli\Exports\Security\Sheets\DataSheet;
use Tobuli\Exports\Security\Sheets\OperationsSheet;
use Tobuli\Exports\Security\Sheets\SecuritySheet;
use Tobuli\Exports\Security\Sheets\TelemetrySheet;
use Tobuli\Services\Security\SecurityLiveStatus;
use Tobuli\Services\Security\SecurityShift;
use Tobuli\Services\Security\SecuritySummary;

/**
 * Libro "Dashboard_Seguridad": replica las cuatro hojas del Excel del cliente
 * (Seguridad, Operaciones, Datos, Telemetria) con valores ya calculados por
 * SecuritySummary / SecurityLiveStatus, los mismos que usa la pantalla.
 */
class SecurityWorkbookExport implements WithMultipleSheets
{
    use Exportable;

    private User $user;
    private Collection $stats;
    private Collection $devices;
    private array $filters;

    /**
     * @param Collection $stats   SecurityDailyStat filtradas, con device.vehicleType
     * @param Collection $devices Devices del usuario (filtrados), con traccar y vehicleType
     * @param array      $filters from, to, shift, device_id, type_id (ya validados)
     */
    public function __construct(User $user, Collection $stats, Collection $devices, array $filters)
    {
        $this->user = $user;
        $this->stats = $stats;
        $this->devices = $devices;
        $this->filters = $filters;
    }

    public function sheets(): array
    {
        $settings = SecuritySetting::forUser($this->user);
        $summary = new SecuritySummary();
        $live = (new SecurityLiveStatus($settings))->rows($this->devices);
        $cards = $summary->cards($this->stats);
        $perUnit = $summary->perUnit($this->stats);
        $labels = $this->filterLabels($settings);

        return [
            new SecuritySheet($labels, $cards, $live, $summary->daily($this->stats), $summary->perUnitDaily($this->stats), $perUnit),
            new OperationsSheet($labels, $cards, $perUnit),
            new DataSheet($this->stats),
            new TelemetrySheet($live, new SecurityShift($settings, $this->timezone())),
        ];
    }

    /** Filtros aplicados en el orden de la fila 4/5 del Excel: etiqueta => valor. */
    private function filterLabels(SecuritySetting $settings): array
    {
        $type = $this->filters['type_id'] ? VehicleType::where('user_id', $this->user->id)->find($this->filters['type_id']) : null;
        $device = $this->filters['device_id'] ? $this->devices->firstWhere('id', $this->filters['device_id']) : null;

        return [
            'Flota'          => $type ? $type->name : 'Todas',
            'Turno'          => DataSheet::SHIFTS[$this->filters['shift']] ?? 'Ambos',
            'Desde'          => $this->filters['from'],
            'Hasta'          => $this->filters['to'],
            'Unidad'         => $device ? $device->name : 'Todas',
            'Vigencia (min)' => $settings->validity_minutes,
            'Límite manual'  => $settings->speed_limit,
        ];
    }

    /** Misma regla que SecurityDailyCalculator: zona del usuario en formato "-05:00". */
    private function timezone(): string
    {
        $tz = $this->user->timezone;

        return $tz && $tz->hi_format ? $tz->hi_format : config('app.timezone');
    }
}
