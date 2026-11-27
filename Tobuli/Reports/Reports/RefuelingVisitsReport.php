<?php

namespace Tobuli\Reports\Reports;

use Formatter;
use Tobuli\Entities\Geofence;
use Tobuli\Entities\User;
use Tobuli\History\Actions\Duration;
use Tobuli\History\Actions\GroupGeofenceIn;
use Tobuli\History\DeviceHistory;
use Tobuli\Reports\DeviceReport;

/**
 * Abastecimiento (modulo de Operaciones, fase 3).
 *
 * Visitas a las geocercas de categoria "grifo" del usuario: entrada, salida y
 * duracion. Solo con la visita, sin medir galones (plan §8). Se calcula con
 * el pipeline de historial (GroupGeofenceIn) sobre el rango pedido.
 */
class RefuelingVisitsReport extends DeviceReport
{
    const TYPE_ID = 913;

    protected $disableFields = ['geofences', 'speed_limit', 'stops', 'zones_instead'];

    /** @var \Illuminate\Support\Collection<Geofence> */
    protected $fuelGeofences;

    public function __construct()
    {
        parent::__construct();

        $this->formats[] = 'csv';
    }

    public function typeID()
    {
        return self::TYPE_ID;
    }

    public function title()
    {
        return trans('front.report_refueling_visits');
    }

    public static function isUserEnabled(User $user): bool
    {
        return static::isEnabled() && $user->perm('operations', 'view');
    }

    protected function beforeGenerate()
    {
        parent::beforeGenerate();

        $this->fuelGeofences = Geofence::userOwned($this->user)
            ->category(Geofence::CATEGORY_FUEL)
            ->get();
    }

    protected function generateDevice($device)
    {
        if ($this->fuelGeofences->isEmpty()) {
            return null;
        }

        $history = new DeviceHistory($device);
        $history->setGeofences($this->fuelGeofences);
        $history->registerActions([Duration::class, GroupGeofenceIn::class]);
        $history->setRange($this->date_from, $this->date_to);

        try {
            $data = $history->get();
        } catch (\PDOException $e) {
            return null;
        }

        $byId = $this->fuelGeofences->keyBy('id');
        $rows = [];
        $total = 0;

        foreach ($data['groups']->all() as $group) {
            if (strpos($group->getKey(), 'geofence_in_') !== 0) {
                continue;
            }

            $start = $group->getStartPosition();
            $end = $group->getEndPosition();

            if (! $start) {
                continue;
            }

            $duration = (int) ($group->hasStat('duration') ? $group->getStat('duration')->get() : 0);
            $total += $duration;

            $rows[] = [
                'geofence'   => $byId->get($group->geofence_id)->name ?? ('#' . $group->geofence_id),
                'entered_at' => Formatter::time()->human($start->time),
                'exited_at'  => $end ? Formatter::time()->human($end->time) : '',
                'duration'   => Formatter::duration()->human($duration),
                'seconds'    => $duration,
            ];
        }

        if (! $rows) {
            return null;
        }

        $this->totals[trans('front.operation_refueling_visits')] = ($this->totals[trans('front.operation_refueling_visits')] ?? 0) + count($rows);

        return [
            'meta'    => $this->getDeviceMeta($device),
            'table'   => ['rows' => $rows],
            'summary' => ['visits' => count($rows), 'duration' => Formatter::duration()->human($total)],
        ];
    }

    protected function toCSVData($file)
    {
        fputcsv($file, [
            trans('front.object'),
            trans('front.operation_stop_geofence'),
            trans('front.operation_refueling_enter'),
            trans('front.operation_refueling_exit'),
            trans('front.operation_stop_duration'),
        ]);

        foreach ($this->getItems() as $item) {
            $device = $item['meta']['device.name'] ?? '';

            foreach ($item['table']['rows'] ?? [] as $r) {
                fputcsv($file, [$device, $r['geofence'], $r['entered_at'], $r['exited_at'], $r['duration']]);
            }
        }
    }
}
