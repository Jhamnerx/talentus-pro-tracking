<?php namespace Tobuli\Services;

use Formatter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Tobuli\Entities\Device;
use Tobuli\Entities\RouteCheckpoint;
use Tobuli\Entities\RouteSchedule;
use Tobuli\Entities\User;

/**
 * Cumplimiento de las programaciones de ruta.
 *
 * Fuente unica del calculo: la usa el reporte, la API de la UI v2 y cualquier
 * cosa que venga despues, para que todas digan lo mismo de la misma vuelta.
 *
 * Dos decisiones que marcan todo lo demas:
 *
 * 1. El desvio se mide SIEMPRE contra el plan, nunca contra el punto anterior.
 *    Si la unidad sale 20 minutos tarde, los diez puntos siguientes salen
 *    tarde: eso es exactamente lo que paso y lo que el cliente quiere ver.
 *    Medir contra el punto anterior escondería el retraso arrastrado.
 *
 * 2. Nada se guarda. Las vueltas se reconstruyen desde las posiciones cuando
 *    se piden, como el resto de reportes. Asi no se toca PositionsWriter, que
 *    es la ruta caliente por la que pasa cada trama de cada equipo.
 */
class RouteAdherenceService
{
    /** Estado de un punto de control. */
    const CP_ON_TIME = 'on_time';
    const CP_EARLY   = 'early';
    const CP_LATE    = 'late';
    const CP_MISSED  = 'missed';

    /** Estado de una vuelta completa. */
    const ROUND_COMPLETED      = 'completed';
    const ROUND_COMPLETED_LATE = 'completed_late';
    const ROUND_INCOMPLETE     = 'incomplete';
    const ROUND_NOT_STARTED    = 'not_started';

    /**
     * Margen que se mira ANTES de la hora de salida, en minutos. Sin el, una
     * unidad que se adelanta no se detecta: se la contaria como "no paso".
     */
    const MARGEN_ANTES = 30;

    /** Tope de la ventana de una vuelta cuando no hay otra vuelta detras. */
    const VENTANA_MAXIMA_HORAS = 24;

    public static function estadosPunto(): array
    {
        return [
            self::CP_ON_TIME => trans('front.route_cp_on_time'),
            self::CP_EARLY   => trans('front.route_cp_early'),
            self::CP_LATE    => trans('front.route_cp_late'),
            self::CP_MISSED  => trans('front.route_cp_missed'),
        ];
    }

    public static function estadosVuelta(): array
    {
        return [
            self::ROUND_COMPLETED      => trans('front.route_round_completed'),
            self::ROUND_COMPLETED_LATE => trans('front.route_round_completed_late'),
            self::ROUND_INCOMPLETE     => trans('front.route_round_incomplete'),
            self::ROUND_NOT_STARTED    => trans('front.route_round_not_started'),
        ];
    }

    /**
     * Programaciones activas del usuario, con todo lo que hace falta para
     * evaluarlas ya cargado (ruta, puntos y geocercas de los puntos).
     */
    public function schedules(User $user, array $ids = []): Collection
    {
        $query = RouteSchedule::userOwned($user)
            ->visible()
            ->with(['route.checkpoints.geofence', 'devices']);

        if ($ids) {
            $query->whereIn('id', $ids);
        }

        return $query->orderBy('name')->get();
    }

    /**
     * Vueltas planificadas de una programacion dentro del rango pedido.
     *
     * El rango llega en UTC (como en los reportes) pero el horario del cliente
     * es local: "sale a las 08:00" es 08:00 en SU huso. Por eso el recorrido de
     * dias se hace en local y cada salida se pasa a UTC al final.
     *
     * @return array<int, array>
     */
    public function rounds(RouteSchedule $schedule, string $fromUtc, string $toUtc): array
    {
        $dia     = date('Y-m-d', strtotime(Formatter::time()->convert($fromUtc)));
        $ultimo  = date('Y-m-d', strtotime(Formatter::time()->convert($toUtc)));
        $vueltas = [];

        while ($dia <= $ultimo) {
            if ($schedule->runsOn((int) date('N', strtotime($dia)))) {
                foreach ($schedule->startTimes() as $sentido => $hora) {
                    $salidaLocal = $dia . ' ' . $hora;
                    $salidaUtc   = Formatter::time()->reverse($salidaLocal);

                    if ($salidaUtc < $fromUtc || $salidaUtc > $toUtc) {
                        continue;
                    }

                    $vueltas[] = [
                        'schedule'    => $schedule,
                        'date'        => $dia,
                        'direction'   => $sentido,
                        'start_local' => $salidaLocal,
                        'start_utc'   => $salidaUtc,
                    ];
                }
            }

            $dia = date('Y-m-d', strtotime($dia . ' +1 day'));
        }

        usort($vueltas, function ($a, $b) {
            return strcmp($a['start_utc'], $b['start_utc']);
        });

        // La ventana de una vuelta acaba donde empieza la siguiente de la misma
        // programacion: asi la ida no se puede "comer" los pasos de la vuelta.
        $total = count($vueltas);

        foreach ($vueltas as $i => &$vuelta) {
            $tope = date('Y-m-d H:i:s', strtotime($vuelta['start_utc']) + self::VENTANA_MAXIMA_HORAS * 3600);

            if ($i + 1 < $total) {
                $siguiente = $vueltas[$i + 1]['start_utc'];

                if ($siguiente < $tope) {
                    $tope = $siguiente;
                }
            }

            $vuelta['window_from'] = date('Y-m-d H:i:s', strtotime($vuelta['start_utc']) - self::MARGEN_ANTES * 60);
            $vuelta['window_to']   = $tope;
        }

        return $vueltas;
    }

    /**
     * Evalua una vuelta contra las posiciones reales del vehiculo.
     *
     * @return array|null null si el sentido no tiene puntos definidos
     */
    public function evaluate(Device $device, array $round): ?array
    {
        /** @var RouteSchedule $schedule */
        $schedule = $round['schedule'];

        $puntos = $schedule->route
            ? $schedule->route->checkpointsFor($round['direction'])
            : collect();

        if ($puntos->isEmpty()) {
            return null;
        }

        $resultados = [];

        foreach ($puntos as $punto) {
            $previstoUtc = date('Y-m-d H:i:s', strtotime($round['start_utc']) + $punto->planned_offset * 60);

            $resultados[] = [
                'checkpoint'    => $punto,
                'label'         => $punto->label,
                'planned_utc'   => $previstoUtc,
                'planned_local' => Formatter::time()->convert($previstoUtc),
                'actual_utc'    => null,
                'actual_local'  => null,
                'deviation'     => null,
                'status'        => self::CP_MISSED,
            ];
        }

        $this->match($device, $round, $resultados);

        foreach ($resultados as &$resultado) {
            if ($resultado['actual_utc'] === null) {
                continue;
            }

            $punto  = $resultado['checkpoint'];
            $desvio = (int) round(
                (strtotime($resultado['actual_utc']) - strtotime($resultado['planned_utc'])) / 60
            );

            $resultado['actual_local'] = Formatter::time()->convert($resultado['actual_utc']);
            $resultado['deviation']    = $desvio;
            $resultado['status']       = $this->statusPunto($desvio, $punto, $schedule);
        }
        unset($resultado);

        return [
            'schedule'    => $schedule,
            'route'       => $schedule->route,
            'device'      => $device,
            'date'        => $round['date'],
            'direction'   => $round['direction'],
            'start_local' => $round['start_local'],
            'start_utc'   => $round['start_utc'],
            'checkpoints' => $resultados,
            'summary'     => $this->summary($resultados),
            'status'      => $this->statusVuelta($resultados),
        ];
    }

    /**
     * Empareja cada punto con el primer paso real, en orden.
     *
     * Va siempre hacia adelante: si la unidad se salta el punto 2 y aparece en
     * el 3, el 2 queda como no pasado y la busqueda sigue desde el 3. Volver
     * atras solo serviria para maquillar el resultado.
     *
     * Antes de poder emparejar otro punto hay que haber SALIDO de la geocerca
     * del anterior. Sin eso, una ruta que empieza y termina en el mismo
     * deposito daria por cumplido el ultimo punto nada mas arrancar.
     */
    private function match(Device $device, array $round, array &$resultados): void
    {
        $total     = count($resultados);
        $indice    = 0;
        $pendiente = null; // geocerca de la que todavia hay que salir

        try {
            $device->positions()
                ->orderliness('asc')
                ->whereBetween('time', [$round['window_from'], $round['window_to']])
                ->chunk(2000, function ($posiciones) use (&$indice, &$pendiente, &$resultados, $total) {
                    foreach ($posiciones as $posicion) {
                        if ($indice >= $total) {
                            return false;
                        }

                        if ($pendiente !== null) {
                            if ($pendiente->pointIn($posicion)) {
                                continue;
                            }

                            $pendiente = null;
                        }

                        for ($i = $indice; $i < $total; $i++) {
                            $geocerca = $resultados[$i]['checkpoint']->geofence;

                            if (! $geocerca || ! $geocerca->pointIn($posicion)) {
                                continue;
                            }

                            $resultados[$i]['actual_utc'] = $posicion->time;

                            $indice    = $i + 1;
                            $pendiente = $geocerca;

                            break;
                        }
                    }

                    return $indice < $total;
                });
        } catch (QueryException $e) {
            // Vehiculo sin tabla de posiciones todavia: no hay nada que medir,
            // la vuelta queda como no iniciada.
        }
    }

    private function statusPunto(int $desvio, RouteCheckpoint $punto, RouteSchedule $schedule): string
    {
        if ($desvio > $punto->toleranceLate($schedule)) {
            return self::CP_LATE;
        }

        if ($desvio < -$punto->toleranceEarly($schedule)) {
            return self::CP_EARLY;
        }

        return self::CP_ON_TIME;
    }

    private function statusVuelta(array $resultados): string
    {
        $pasados = 0;
        $tarde   = 0;

        foreach ($resultados as $resultado) {
            if ($resultado['status'] === self::CP_MISSED) {
                continue;
            }

            $pasados++;

            if ($resultado['status'] === self::CP_LATE) {
                $tarde++;
            }
        }

        if ($pasados === 0) {
            return self::ROUND_NOT_STARTED;
        }

        if ($pasados < count($resultados)) {
            return self::ROUND_INCOMPLETE;
        }

        return $tarde ? self::ROUND_COMPLETED_LATE : self::ROUND_COMPLETED;
    }

    private function summary(array $resultados): array
    {
        $resumen = [
            'total'      => count($resultados),
            'passed'     => 0,
            'on_time'    => 0,
            'early'      => 0,
            'late'       => 0,
            'missed'     => 0,
            'max_delay'  => null,
        ];

        foreach ($resultados as $resultado) {
            $resumen[$resultado['status']]++;

            if ($resultado['status'] !== self::CP_MISSED) {
                $resumen['passed']++;
            }

            if ($resultado['status'] === self::CP_LATE
                && ($resumen['max_delay'] === null || $resultado['deviation'] > $resumen['max_delay'])
            ) {
                $resumen['max_delay'] = $resultado['deviation'];
            }
        }

        return $resumen;
    }

    /**
     * Todas las vueltas de un vehiculo en el rango, ya evaluadas.
     *
     * @param Collection|null $schedules programaciones ya cargadas; si va nula
     *                                   se toman las del vehiculo
     * @return array<int, array>
     */
    public function forDevice(Device $device, string $fromUtc, string $toUtc, ?Collection $schedules = null): array
    {
        if ($schedules === null) {
            $schedules = $device->routeSchedules()
                ->where('active', 1)
                ->with(['route.checkpoints.geofence'])
                ->get();
        }

        $vueltas = [];

        foreach ($schedules as $schedule) {
            foreach ($this->rounds($schedule, $fromUtc, $toUtc) as $round) {
                $evaluada = $this->evaluate($device, $round);

                if ($evaluada !== null) {
                    $vueltas[] = $evaluada;
                }
            }
        }

        usort($vueltas, function ($a, $b) {
            return strcmp($a['start_utc'], $b['start_utc']);
        });

        return $vueltas;
    }
}
