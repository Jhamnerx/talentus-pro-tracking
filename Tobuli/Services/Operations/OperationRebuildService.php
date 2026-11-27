<?php

namespace Tobuli\Services\Operations;

use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Tobuli\Entities\Device;
use Tobuli\Entities\Operation;
use Tobuli\Entities\OperationCycle;
use Tobuli\Entities\OperationDeviceState;
use Tobuli\Entities\OperationLeg;
use Tobuli\Entities\OperationPoint;
use Tobuli\Entities\OperationStop;
use Tobuli\Entities\OperationVisit;
use Tobuli\Helpers\Formatter\Formatter;

/**
 * Rehace las vueltas de un vehiculo desde una fecha a partir de sus
 * posiciones guardadas. Usa el mismo OperationCycleWriter que el conteo en
 * vivo, asi que el resultado es el mismo que si las tramas hubieran llegado
 * en orden.
 *
 * Borra visitas, vueltas, tramos y paradas del vehiculo desde `from`, deja la
 * fila de estado como estaba justo antes de esa hora y vuelve a recorrer las
 * posiciones. Los tramos cerrados quedan con `calculated_at` nulo para que
 * operations:process los calcule. Al final rehace los resumenes de turno
 * afectados (OperationShiftSummaryService::rebuildFrom).
 */
class OperationRebuildService
{
    const CHUNK = 2000;

    /**
     * @param bool $useCurrentPoints true = evalua contra la ubicacion actual
     *             de los puntos; false = contra la vigente en cada momento
     */
    public function rebuild(Operation $operation, Device $device, string $fromUtc, bool $useCurrentPoints = false): OperationDeviceState
    {
        $formatter = new Formatter();
        $formatter->byUser($operation->user);

        $state = $this->resetState($operation, $device, $fromUtc);

        $allPoints = $operation->points()->orderBy('active_from')->get();
        $this->markFirstVersions($allPoints);
        $points = $useCurrentPoints
            ? $allPoints->whereNull('active_to')->values()
            : $this->pointsAt($allPoints, $fromUtc);

        $writer = new OperationCycleWriter($operation, $state, $points, $formatter);

        // Proximo cambio de version de puntos, para no re-filtrar por posicion.
        $nextChange = $useCurrentPoints ? null : $this->nextVersionChange($allPoints, $fromUtc);

        $previous = null;

        try {
            $device->positions()
                ->select(['id', 'time', 'latitude', 'longitude', 'valid', 'other'])
                ->orderliness('asc')
                ->where('time', '>=', $fromUtc)
                ->chunk(self::CHUNK, function ($positions) use (&$previous, &$nextChange, &$points, $allPoints, $writer, $useCurrentPoints) {
                    foreach ($positions as $position) {
                        if (! $position->isValid()) {
                            continue;
                        }

                        if ((int) $position->getParameter('sat', 99) < 4) {
                            continue;
                        }

                        if (! $useCurrentPoints && $nextChange && $position->time >= $nextChange) {
                            $points = $this->pointsAt($allPoints, $position->time);
                            $writer->setPoints($points);
                            $nextChange = $this->nextVersionChange($allPoints, $position->time);
                        }

                        $writer->process(
                            (float) $position->latitude,
                            (float) $position->longitude,
                            $position->time,
                            $previous
                        );

                        $previous = [
                            'lat'  => (float) $position->latitude,
                            'lng'  => (float) $position->longitude,
                            'time' => $position->time,
                        ];
                    }
                });
        } catch (QueryException $e) {
            // Sin tabla de posiciones (vehiculo nuevo): no hay nada que rehacer.
            // Cualquier otro error de SQL si debe verse.
            if (($e->errorInfo[0] ?? null) !== '42S02') {
                throw $e;
            }
        }

        $state = $writer->getState();
        $state->save();

        // Reporte de turno: los resumenes de los turnos que cruzan [from, ahora]
        // se rehacen con las vueltas y tramos nuevos. Las paradas y el ralenti
        // de los tramos recien cerrados aun no estan (calculated_at nulo), asi
        // que lo que depende de paradas (MAINT por motivo, parada del
        // abastecimiento) se completa en el siguiente cierre o con
        // operations:summaries.
        try {
            (new OperationShiftSummaryService())->rebuildFrom($operation, $device, $fromUtc);
        } catch (\Throwable $e) {
            \Log::error("Recalculo de operaciones: resumen de turno del vehiculo {$device->id}: " . $e->getMessage());
        }

        return $state;
    }

    /**
     * Elimina lo registrado desde `from` y deja la fila de estado tal como
     * estaba justo antes: dentro del punto donde estuviera, o en el tramo
     * abierto que cruzaba esa hora.
     */
    protected function resetState(Operation $operation, Device $device, string $fromUtc): OperationDeviceState
    {
        $state = OperationDeviceState::firstOrNew(['device_id' => $device->id]);
        $state->operation_id = $operation->id;

        // Vueltas que empiezan desde `from` se borran enteras (tramos y
        // paradas van en cascade). Las que cruzan `from` se recortan.
        OperationCycle::where('operation_id', $operation->id)
            ->where('device_id', $device->id)
            ->where('started_at', '>=', $fromUtc)
            ->delete();

        $crossing = OperationCycle::where('operation_id', $operation->id)
            ->where('device_id', $device->id)
            ->where('started_at', '<', $fromUtc)
            ->where(function ($q) use ($fromUtc) {
                $q->whereNull('ended_at')->orWhere('ended_at', '>=', $fromUtc);
            })
            ->orderBy('started_at', 'desc')
            ->first();

        OperationVisit::where('operation_id', $operation->id)
            ->where('device_id', $device->id)
            ->where('entered_at', '>=', $fromUtc)
            ->delete();

        $openVisit = OperationVisit::where('operation_id', $operation->id)
            ->where('device_id', $device->id)
            ->where('entered_at', '<', $fromUtc)
            ->where(function ($q) use ($fromUtc) {
                $q->whereNull('exited_at')->orWhere('exited_at', '>=', $fromUtc);
            })
            ->orderBy('entered_at', 'desc')
            ->first();

        $openLeg = null;

        if ($crossing) {
            OperationLeg::where('cycle_id', $crossing->id)->where('started_at', '>=', $fromUtc)->delete();

            $openLeg = OperationLeg::where('cycle_id', $crossing->id)
                ->where('started_at', '<', $fromUtc)
                ->orderBy('started_at', 'desc')
                ->first();

            if ($openLeg) {
                OperationStop::where('leg_id', $openLeg->id)->delete();
                $openLeg->fill([
                    'ended_at' => null, 'to_point_id' => null, 'status' => OperationLeg::STATUS_OPEN,
                    'calculated_at' => null, 'duration_seconds' => null, 'distance_meters' => null,
                    'stop_seconds' => null, 'idle_seconds' => null, 'engine_off_seconds' => null,
                    'gap_seconds' => null, 'stops_count' => null, 'unauthorized_stops_count' => null,
                ])->save();
            }

            $crossing->fill([
                'ended_at' => null, 'duration_seconds' => null, 'status' => OperationCycle::STATUS_OPEN,
            ])->save();
        }

        if ($openVisit) {
            $openVisit->exited_at = null;
            $openVisit->save();
        }

        $state->current_point_id = $openVisit ? $openVisit->operation_point_id : null;
        $state->open_visit_id = $openVisit ? $openVisit->id : null;
        $state->open_leg_id = ($openLeg && ! $openVisit) ? $openLeg->id : null;
        $state->open_cycle_id = $crossing ? $crossing->id : null;
        $state->candidate_point_id = null;
        $state->pending_since = null;
        $state->last_time = null;
        $state->no_data_since = null;

        if ($openVisit) {
            $state->state = $openVisit->role === OperationPoint::ROLE_LOAD
                ? OperationDeviceState::STATE_AT_LOAD
                : OperationDeviceState::STATE_AT_UNLOAD;
        } elseif ($openLeg) {
            $state->state = $openLeg->isOutbound()
                ? OperationDeviceState::STATE_OUTBOUND
                : OperationDeviceState::STATE_INBOUND;
        } else {
            $state->state = OperationDeviceState::STATE_UNKNOWN;
        }

        $state->save();
        $state->load(['currentPoint', 'openVisit', 'openLeg', 'openCycle']);

        return $state;
    }

    /**
     * La PRIMERA version de un punto (la que no reemplaza a otra) vale para
     * todo el pasado: si no, un punto creado hoy nunca podria recalcular las
     * vueltas de la semana pasada, que es justo para lo que sirve el
     * recalculo. Solo las versiones que nacen al mover la geocerca empiezan
     * en su active_from.
     */
    protected function markFirstVersions(Collection $all): void
    {
        foreach ($all as $p) {
            $from = $p->active_from ? $p->active_from->format('Y-m-d H:i:s') : null;

            $hasPredecessor = $all->contains(function (OperationPoint $other) use ($p, $from) {
                return $other->id !== $p->id
                    && $other->geofence_id === $p->geofence_id
                    && $other->role === $p->role
                    && $other->active_to
                    && $other->active_to->format('Y-m-d H:i:s') === $from;
            });

            $p->effective_from = $hasPredecessor ? $from : null;
        }
    }

    /** @param Collection<OperationPoint> $all */
    protected function pointsAt(Collection $all, string $utc): Collection
    {
        return $all->filter(function (OperationPoint $p) use ($utc) {
            $from = $p->effective_from;
            $to = $p->active_to ? $p->active_to->format('Y-m-d H:i:s') : null;

            return ($from === null || $from <= $utc) && ($to === null || $to > $utc);
        })->values();
    }

    protected function nextVersionChange(Collection $all, string $utc): ?string
    {
        $next = null;

        foreach ($all as $p) {
            foreach ([$p->effective_from, $p->active_to] as $d) {
                if (! $d) {
                    continue;
                }

                $s = is_string($d) ? $d : $d->format('Y-m-d H:i:s');

                if ($s > $utc && ($next === null || $s < $next)) {
                    $next = $s;
                }
            }
        }

        return $next;
    }
}
