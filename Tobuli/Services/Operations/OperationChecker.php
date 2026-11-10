<?php

namespace Tobuli\Services\Operations;

use Illuminate\Support\Facades\Cache;
use Tobuli\Entities\Device;
use Tobuli\Entities\Operation;
use Tobuli\Entities\OperationDeviceState;
use Tobuli\Entities\OperationRecalculation;
use Tobuli\Helpers\Formatter\Formatter;

/**
 * Evaluacion en vivo de las vueltas dentro de PositionsWriter, con la misma
 * forma que el Checker de alertas:
 *
 * - Se construye UNA vez por lote (por instancia del writer). La primera
 *   llamada hace una consulta indexada a operation_device_state; sin fila,
 *   el resto del lote no cuesta nada.
 * - La configuracion de la operacion (puntos vigentes, umbrales) vive en
 *   Cache::store('array') por proceso y se invalida por `updated_at` de la
 *   operacion, igual que cacheAlerts().
 * - El estado NO vive en memoria entre lotes: esta en la fila, que
 *   OperationCycleWriter escribe solo en transiciones.
 * - Las tramas atrasadas no mueven el estado: acumulan un recalculo que se
 *   escribe una vez, en flush(), al final del lote.
 */
class OperationChecker
{
    protected Device $device;

    /** @var OperationCycleWriter|false|null null = sin resolver, false = sin operacion */
    protected $writer = null;

    protected ?Operation $operation = null;

    protected ?string $recalculateFrom = null;

    public function __construct(Device $device)
    {
        $this->device = $device;
    }

    public static function enabled(): bool
    {
        return (bool) config('tobuli.operations_enabled');
    }

    /**
     * Trama en vivo y valida. `$previous` es la ultima posicion valida
     * guardada en traccar (device->positionTraccar()), puede ser null.
     */
    public function check($position, $previous = null): void
    {
        if (! $this->resolve()) {
            return;
        }

        if (! $position->isValid()) {
            return;
        }

        if ((int) $position->getParameter('sat', 99) < 4) {
            return;
        }

        $prev = null;

        if ($previous && $previous->latitude && $previous->longitude && $previous->time) {
            $prev = [
                'lat'  => (float) $previous->latitude,
                'lng'  => (float) $previous->longitude,
                'time' => $previous->time,
            ];
        }

        $this->writer->process(
            (float) $position->latitude,
            (float) $position->longitude,
            $position->time,
            $prev
        );
    }

    /** Trama atrasada: no se evalua, marca desde cuando rehacer. */
    public function late($position): void
    {
        if (! $this->resolve()) {
            return;
        }

        if (! $position->isValid()) {
            return;
        }

        if (is_null($this->recalculateFrom) || $position->time < $this->recalculateFrom) {
            $this->recalculateFrom = $position->time;
        }
    }

    /** Al final del lote: una escritura como mucho. */
    public function flush(): void
    {
        if ($this->recalculateFrom && $this->operation) {
            OperationRecalculation::enqueue($this->operation->id, $this->device->id, $this->recalculateFrom);
            $this->recalculateFrom = null;
        }
    }

    protected function resolve(): bool
    {
        if ($this->writer === false) {
            return false;
        }

        if ($this->writer instanceof OperationCycleWriter) {
            return true;
        }

        $state = OperationDeviceState::where('device_id', $this->device->id)
            ->with(['currentPoint', 'openVisit', 'openLeg', 'openCycle'])
            ->first();

        if (! $state) {
            $this->writer = false;
            return false;
        }

        $config = $this->operationConfig($state->operation_id);

        if (! $config) {
            $this->writer = false;
            return false;
        }

        $this->operation = $config['operation'];
        $this->writer = (new OperationCycleWriter($this->operation, $state, $config['points'], $config['formatter']))
            ->setLiveMode(true);

        return true;
    }

    /**
     * Operacion + puntos vigentes + formatter del dueño, cacheados por
     * proceso. La consulta ligera por lote es la de la operacion; si su
     * updated_at no cambio se reutilizan las relaciones ya cargadas.
     */
    protected function operationConfig(int $operationId): ?array
    {
        $operation = Operation::visible()->find($operationId);

        if (! $operation) {
            return null;
        }

        $key = "operation.{$operation->id}.config";
        $cached = Cache::store('array')->get($key);

        if ($cached && $cached['updated_at'] == (string) $operation->updated_at) {
            return $cached;
        }

        $operation->load(['shifts', 'user']);

        $formatter = new Formatter();
        $formatter->byUser($operation->user);

        $config = [
            'updated_at' => (string) $operation->updated_at,
            'operation'  => $operation,
            'points'     => $operation->currentPoints()->get(),
            'formatter'  => $formatter,
        ];

        Cache::store('array')->set($key, $config);

        return $config;
    }
}
