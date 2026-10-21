<?php

namespace Tobuli\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estado de reenvío por vehículo e integración.
 *
 * Las colas no guardan estado: la fila se borra al enviarse. Así que lo que se
 * mide es el ATRASO — cuántas filas esperan y desde cuándo.
 */
class IntegrationStatusService
{
    /**
     * Bandera del vehículo => tabla de la cola.
     *
     * Ojo con dos: la bandera `mtc` escribe en una tabla llamada `sutran`
     * (comprobado: los 50 vehículos de esa cola tienen mtc=1 y ninguno
     * mininter), y `consatel` escribe en `comsatel`. Los nombres no coinciden.
     */
    public const MAPA = [
        'wisetrack'  => 'wisetrack',
        'mtc'        => 'sutran',
        'osinergmin' => 'osinergmin',
        'consatel'   => 'comsatel',
        'mininter'   => 'mininter',
        'sidersales' => 'sidersales',
        'unigis'     => 'unigis',
    ];

    /** Minutos de atraso a partir de los cuales se considera problema. */
    public const AVISO_MIN = 30;
    public const CRITICO_MIN = 180;

    /**
     * Recalcula el resumen completo.
     *
     * @return array recuento por integración
     */
    public function refresh(): array
    {
        $resumen = [];
        $ahora = now();

        foreach (self::MAPA as $bandera => $tabla) {
            if (! Schema::hasColumn('devices', $bandera) || ! Schema::hasTable($tabla)) {
                continue;
            }

            $obligados = DB::table('devices')->where($bandera, 1)->pluck('id')->all();

            if (! $obligados) {
                // Nadie la usa: se limpia lo que hubiera quedado
                DB::table('integration_status')->where('integration', $bandera)->delete();
                $resumen[$bandera] = 0;
                continue;
            }

            // UNA consulta agregada por integración, no una por vehículo
            $cola = DB::table($tabla)
                ->selectRaw('device_id, COUNT(*) AS pendientes, MIN(created_at) AS mas_vieja, MAX(created_at) AS mas_nueva')
                ->whereIn('device_id', $obligados)
                ->groupBy('device_id')
                ->get()
                ->keyBy('device_id');

            $filas = [];

            foreach ($obligados as $deviceId) {
                $d = $cola->get($deviceId);

                $filas[] = [
                    'device_id'   => $deviceId,
                    'integration' => $bandera,
                    'pending'     => $d ? (int) $d->pendientes : 0,
                    'oldest_at'   => $d ? $d->mas_vieja : null,
                    'last_at'     => $d ? $d->mas_nueva : null,
                    'computed_at' => $ahora,
                    'created_at'  => $ahora,
                    'updated_at'  => $ahora,
                ];
            }

            foreach (array_chunk($filas, 500) as $lote) {
                DB::table('integration_status')->upsert(
                    $lote,
                    ['device_id', 'integration'],
                    ['pending', 'oldest_at', 'last_at', 'computed_at', 'updated_at']
                );
            }

            // Vehículos a los que se les quitó la bandera
            DB::table('integration_status')
                ->where('integration', $bandera)
                ->whereNotIn('device_id', $obligados)
                ->delete();

            $resumen[$bandera] = count($filas);
        }

        return $resumen;
    }

    /**
     * Semáforo de una fila del resumen.
     *
     * - sin pendientes            -> al dia
     * - pendientes recientes      -> en curso (normal, se estan enviando)
     * - pendientes con atraso     -> aviso / critico
     */
    public static function semaforo(?int $pending, $oldestAt): string
    {
        if (! $pending) {
            return 'ok';
        }

        if (! $oldestAt) {
            return 'aviso';
        }

        $minutos = now()->diffInMinutes(\Carbon\Carbon::parse($oldestAt));

        if ($minutos >= self::CRITICO_MIN) {
            return 'critico';
        }

        if ($minutos >= self::AVISO_MIN) {
            return 'aviso';
        }

        return 'curso';
    }
}
