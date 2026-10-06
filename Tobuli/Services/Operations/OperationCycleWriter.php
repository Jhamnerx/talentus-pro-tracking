<?php

namespace Tobuli\Services\Operations;

use Illuminate\Support\Collection;
use Tobuli\Entities\Operation;
use Tobuli\Entities\OperationCycle;
use Tobuli\Entities\OperationDeviceState;
use Tobuli\Entities\OperationLeg;
use Tobuli\Entities\OperationPoint;
use Tobuli\Entities\OperationVisit;
use Tobuli\Helpers\Formatter\Formatter;

/**
 * Maquina de estados de una vuelta para UN vehiculo:
 *
 *   En carga --sale--> Ida --llega--> En descarga --sale--> Vuelta --llega--> En carga (vuelta +1)
 *
 * Recibe posiciones en orden y escribe visitas, tramos y vueltas SOLO en las
 * transiciones confirmadas (permanencia minima dentro o fuera del punto). El
 * estado va en la fila `operation_device_state`, nunca en memoria entre
 * lotes: la usan por igual OperationChecker (en vivo, dentro de
 * PositionsWriter) y OperationRebuildService (recalculo desde posiciones
 * guardadas), para que ambos den el mismo resultado.
 *
 * No consulta nada por posicion: los puntos vigentes llegan ya cargados y
 * las escrituras son los `save()` de las transiciones.
 */
class OperationCycleWriter
{
    protected Operation $operation;
    protected OperationDeviceState $state;

    /** @var Collection<OperationPoint> versiones vigentes para el instante que se evalua */
    protected Collection $points;

    protected Formatter $formatter;

    protected int $dwellSeconds;

    /** Muestreo del segmento entre dos tramas, en metros. */
    const SEGMENT_STEP_METERS = 20;
    const SEGMENT_MAX_SAMPLES = 60;

    public function __construct(Operation $operation, OperationDeviceState $state, Collection $points, Formatter $formatter)
    {
        $this->operation = $operation;
        $this->state = $state;
        $this->points = $points->values();
        $this->formatter = $formatter;
        $this->dwellSeconds = max(0, (int) $operation->min_dwell_seconds);
    }

    public function getState(): OperationDeviceState
    {
        return $this->state;
    }

    /** Cambia el juego de puntos vigentes (el recalculo lo hace al cruzar una version). */
    public function setPoints(Collection $points): void
    {
        $this->points = $points->values();
    }

    /**
     * Evalua una posicion valida. `$timeUtc` es la hora de la posicion tal
     * como se guarda en la tabla de posiciones ('Y-m-d H:i:s').
     *
     * @param array|null $previous ['lat'=>, 'lng'=>, 'time'=>] ultima posicion valida anterior, si se conoce
     * @return bool true si hubo alguna escritura
     */
    public function process(float $lat, float $lng, string $timeUtc, ?array $previous = null): bool
    {
        $ts = strtotime($timeUtc);

        // Una trama anterior a la ultima evaluada no mueve el estado en vivo.
        if ($this->state->last_time && $ts < $this->state->last_time->getTimestamp()) {
            return false;
        }

        $this->currentTs = $ts;
        $changed = false;
        $inside = $this->pointAt($lat, $lng);

        if ($this->state->isInsidePoint()) {
            $changed = $this->whileInside($inside, $timeUtc, $ts);
        } else {
            if (! $inside && $previous && ! $this->state->candidate_point_id) {
                $crossed = $this->segmentCrosses($previous, $lat, $lng);

                if ($crossed) {
                    $changed = $this->passingVisit($crossed['point'], $crossed['time']);
                }
            }

            if (! $this->state->isInsidePoint()) {
                $changed = $this->whileOutside($inside, $timeUtc, $ts) || $changed;
            }
        }

        $this->state->last_time = $timeUtc;

        if ($this->state->state === OperationDeviceState::STATE_NO_DATA) {
            $this->state->state = $this->stateFromLeg();
            $changed = true;
        }

        $this->state->no_data_since = null;

        if ($changed || $this->state->isDirty(['state'])) {
            $this->state->save();
            return true;
        }

        return false;
    }

    // ------------------------------------------------------------------
    // Dentro de un punto
    // ------------------------------------------------------------------

    protected function whileInside(?OperationPoint $inside, string $timeUtc, int $ts): bool
    {
        $current = $this->state->currentPoint;

        if ($inside && $current && $inside->id === $current->id) {
            // Sigue dentro: se cancela cualquier salida candidata.
            if ($this->state->pending_since) {
                $this->state->pending_since = null;
                return true;
            }

            return false;
        }

        // Fuera del punto actual (o dentro de otro): candidato a salida.
        if (! $this->state->pending_since) {
            $this->state->candidate_point_id = null;
            $this->state->pending_since = $timeUtc;
            return true;
        }

        if ($ts - $this->state->pending_since->getTimestamp() < $this->dwellSeconds) {
            return false;
        }

        $exitAt = $this->state->pending_since->format('Y-m-d H:i:s');

        $this->confirmExit($current, $exitAt);

        // Si ya esta dentro de otro punto, empieza su candidatura de entrada.
        if ($inside) {
            $this->state->candidate_point_id = $inside->id;
            $this->state->pending_since = $timeUtc;
        }

        return true;
    }

    protected function confirmExit(?OperationPoint $point, string $exitAt): void
    {
        $visit = $this->state->openVisit;

        if ($visit && ! $visit->exited_at) {
            $visit->exited_at = $exitAt;
            $visit->save();
        }

        $cycle = $this->state->openCycle;
        $role = $point ? $point->role : ($visit->role ?? OperationPoint::ROLE_LOAD);

        if ($role === OperationPoint::ROLE_LOAD) {
            // Salir de carga abre una vuelta nueva. Si habia una abierta
            // (p. ej. volvio a carga sin pasar por descarga) ya se cerro al llegar.
            $cycle = $this->openCycle($exitAt);
            $cycle->load_seconds = $visit ? $visit->durationSeconds() : null;
            $cycle->save();

            $direction = OperationLeg::DIRECTION_OUTBOUND;
        } else {
            if (! $cycle) {
                // Empieza a contar estando en descarga: la vuelta nace aqui y
                // quedara incompleta (sin tramo de ida) al llegar a carga.
                $cycle = $this->openCycle($exitAt);
            }

            $cycle->unload_seconds = $visit ? $visit->durationSeconds() : null;
            $cycle->save();

            $direction = OperationLeg::DIRECTION_INBOUND;
        }

        $leg = OperationLeg::create([
            'cycle_id'      => $cycle->id,
            'device_id'     => $this->state->device_id,
            'direction'     => $direction,
            'from_point_id' => $point ? $point->id : null,
            'started_at'    => $exitAt,
            'status'        => OperationLeg::STATUS_OPEN,
        ]);

        $this->state->current_point_id = null;
        $this->state->candidate_point_id = null;
        $this->state->pending_since = null;
        $this->state->open_visit_id = null;
        $this->state->open_leg_id = $leg->id;
        $this->state->open_cycle_id = $cycle->id;
        $this->state->state = $direction === OperationLeg::DIRECTION_OUTBOUND
            ? OperationDeviceState::STATE_OUTBOUND
            : OperationDeviceState::STATE_INBOUND;

        $this->state->setRelation('openLeg', $leg);
        $this->state->setRelation('openCycle', $cycle);
        $this->state->setRelation('openVisit', null);
        $this->state->setRelation('currentPoint', null);
    }

    // ------------------------------------------------------------------
    // Fuera de todo punto (en tramo o sin estado)
    // ------------------------------------------------------------------

    protected function whileOutside(?OperationPoint $inside, string $timeUtc, int $ts): bool
    {
        if (! $inside) {
            if ($this->state->candidate_point_id) {
                // Rebote en el borde: no llego a quedarse.
                $this->state->candidate_point_id = null;
                $this->state->pending_since = null;
                return true;
            }

            return false;
        }

        if ($this->state->candidate_point_id !== $inside->id) {
            $this->state->candidate_point_id = $inside->id;
            $this->state->pending_since = $timeUtc;
            return true;
        }

        if ($ts - $this->state->pending_since->getTimestamp() < $this->dwellSeconds) {
            return false;
        }

        $this->confirmEntry($inside, $this->state->pending_since->format('Y-m-d H:i:s'), false);

        return true;
    }

    protected function confirmEntry(OperationPoint $point, string $enterAt, bool $passing): void
    {
        $leg = $this->state->openLeg;
        $cycle = $this->state->openCycle;

        if ($leg && $leg->isOpen()) {
            $expected = $leg->isOutbound() ? OperationPoint::ROLE_UNLOAD : OperationPoint::ROLE_LOAD;

            $leg->ended_at = $enterAt;
            $leg->to_point_id = $point->id;
            $leg->duration_seconds = max(0, strtotime($enterAt) - $leg->started_at->getTimestamp());
            $leg->status = $point->role === $expected
                ? OperationLeg::STATUS_COMPLETED
                : OperationLeg::STATUS_INCOMPLETE; // volvio al mismo tipo de punto
            $leg->save();
        }

        if ($point->isLoad() && $cycle && $cycle->isOpen()) {
            $this->closeCycle($cycle, $enterAt);
            $cycle = null;
        }

        $visit = OperationVisit::create([
            'operation_id'       => $this->operation->id,
            'operation_point_id' => $point->id,
            'device_id'          => $this->state->device_id,
            'role'               => $point->role,
            'entered_at'         => $enterAt,
            'exited_at'          => $passing ? $enterAt : null,
            'passing'            => $passing,
        ]);

        $this->state->current_point_id = $point->id;
        $this->state->candidate_point_id = null;
        $this->state->pending_since = null;
        $this->state->open_visit_id = $visit->id;
        $this->state->open_leg_id = null;
        $this->state->open_cycle_id = $cycle ? $cycle->id : null;
        $this->state->state = $point->isLoad()
            ? OperationDeviceState::STATE_AT_LOAD
            : OperationDeviceState::STATE_AT_UNLOAD;

        $this->state->setRelation('currentPoint', $point);
        $this->state->setRelation('openVisit', $visit);
        $this->state->setRelation('openLeg', null);
        $this->state->setRelation('openCycle', $cycle);
    }

    /** Visita de paso: el segmento cruzo el punto sin trama dentro. Entra y sale en el acto. */
    protected function passingVisit(OperationPoint $point, string $timeUtc): bool
    {
        $this->confirmEntry($point, $timeUtc, true);
        $this->confirmExit($point, $timeUtc);

        return true;
    }

    // ------------------------------------------------------------------
    // Vueltas
    // ------------------------------------------------------------------

    protected function openCycle(string $startedAt): OperationCycle
    {
        $local = $this->formatter->time()->convert($startedAt, 'Y-m-d H:i:s');
        $localDt = new \DateTime($local);
        $workDate = $localDt->format('Y-m-d');

        $shift = $this->operation->shiftAt($localDt->format('H:i:s'));

        $number = OperationCycle::where('operation_id', $this->operation->id)
            ->where('device_id', $this->state->device_id)
            ->where('work_date', $workDate)
            ->max('number');

        return OperationCycle::create([
            'operation_id' => $this->operation->id,
            'device_id'    => $this->state->device_id,
            'work_date'    => $workDate,
            'shift_id'     => $shift ? $shift->id : null,
            'shift_name'   => $shift ? $shift->name : null,
            'shift_date'   => $shift ? $shift->shiftDateFor($localDt) : null,
            'number'       => ((int) $number) + 1,
            'started_at'   => $startedAt,
            'status'       => OperationCycle::STATUS_OPEN,
        ]);
    }

    protected function closeCycle(OperationCycle $cycle, string $endedAt): void
    {
        $legs = $cycle->legs()->get();

        $hasOutbound = $legs->contains(fn ($l) => $l->isOutbound() && $l->status === OperationLeg::STATUS_COMPLETED);
        $hasInbound = $legs->contains(fn ($l) => ! $l->isOutbound() && $l->status === OperationLeg::STATUS_COMPLETED);

        $cycle->ended_at = $endedAt;
        $cycle->duration_seconds = max(0, strtotime($endedAt) - $cycle->started_at->getTimestamp());
        $cycle->status = ($hasOutbound && $hasInbound)
            ? OperationCycle::STATUS_COMPLETED
            : OperationCycle::STATUS_INCOMPLETE;
        $cycle->save();
    }

    /**
     * Cierra lo que quede abierto como incompleto (tramo vencido, vehiculo
     * sacado del grupo, operacion desactivada). `$at` es la hora de corte.
     */
    public function abandon(string $at): void
    {
        $leg = $this->state->openLeg;

        if ($leg && $leg->isOpen()) {
            $leg->ended_at = $at;
            $leg->duration_seconds = max(0, strtotime($at) - $leg->started_at->getTimestamp());
            $leg->status = OperationLeg::STATUS_INCOMPLETE;
            $leg->save();
        }

        $cycle = $this->state->openCycle;

        if ($cycle && $cycle->isOpen()) {
            $cycle->ended_at = $at;
            $cycle->duration_seconds = max(0, strtotime($at) - $cycle->started_at->getTimestamp());
            $cycle->status = OperationCycle::STATUS_INCOMPLETE;
            $cycle->save();
        }

        $this->state->open_leg_id = null;
        $this->state->open_cycle_id = null;
        $this->state->candidate_point_id = null;
        $this->state->pending_since = null;

        if (! $this->state->isInsidePoint()) {
            $this->state->state = OperationDeviceState::STATE_UNKNOWN;
        }

        $this->state->save();
    }

    // ------------------------------------------------------------------
    // Geometria
    // ------------------------------------------------------------------

    protected function pointAt(float $lat, float $lng): ?OperationPoint
    {
        // Si esta dentro de su punto actual, ese manda aunque haya solape.
        if ($this->state->current_point_id) {
            foreach ($this->points as $point) {
                if ($point->id === $this->state->current_point_id && $point->contains($lat, $lng)) {
                    return $point;
                }
            }
        }

        foreach ($this->points as $point) {
            if ($point->contains($lat, $lng)) {
                return $point;
            }
        }

        return null;
    }

    protected function stateFromLeg(): string
    {
        $leg = $this->state->openLeg;

        if ($leg && $leg->isOpen()) {
            return $leg->isOutbound() ? OperationDeviceState::STATE_OUTBOUND : OperationDeviceState::STATE_INBOUND;
        }

        if ($this->state->currentPoint) {
            return $this->state->currentPoint->isLoad() ? OperationDeviceState::STATE_AT_LOAD : OperationDeviceState::STATE_AT_UNLOAD;
        }

        return OperationDeviceState::STATE_UNKNOWN;
    }

    /**
     * Muestrea el segmento previa->actual cada ~20 m y devuelve el primer punto
     * de operacion que toca, con la hora interpolada. Evita perder una visita a
     * un punto pequeño cuando el equipo reporta cada 30 s.
     *
     * @return array{point: OperationPoint, time: string}|null
     */
    protected function segmentCrosses(array $previous, float $lat, float $lng): ?array
    {
        $prevTs = strtotime($previous['time']);
        $meters = getDistance($previous['lat'], $previous['lng'], $lat, $lng) * 1000;

        if ($meters < self::SEGMENT_STEP_METERS * 2) {
            return null;
        }

        $samples = min(self::SEGMENT_MAX_SAMPLES, (int) floor($meters / self::SEGMENT_STEP_METERS));

        for ($i = 1; $i < $samples; $i++) {
            $f = $i / $samples;
            $sLat = $previous['lat'] + ($lat - $previous['lat']) * $f;
            $sLng = $previous['lng'] + ($lng - $previous['lng']) * $f;

            foreach ($this->points as $point) {
                if ($point->contains($sLat, $sLng)) {
                    $ts = $prevTs + (int) round((($this->currentTs ?? $prevTs) - $prevTs) * $f);

                    return ['point' => $point, 'time' => date('Y-m-d H:i:s', $ts)];
                }
            }
        }

        return null;
    }

    protected ?int $currentTs = null;
}
