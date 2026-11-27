<?php

namespace App\Http\Controllers\Admin;

use Formatter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View as FacadeView;
use Tobuli\Services\IntegrationStatusService;

/**
 * Cumplimiento regulatorio: qué vehículos están al día con cada ente o
 * proveedor (Osinergmin, SUTRAN/MTC, Sidersales, WiseTrack, MININTER…) y
 * cuáles llevan tramas sin enviar.
 *
 * Lee el resumen precalculado (tabla `integration_status`), NUNCA las colas
 * directamente: son cientos de miles de filas y los jobs de envío están
 * trabajando sobre ellas. El resumen lo refresca `integrations:status`.
 */
class IntegrationsStatusController extends BaseController
{
    public function index(Request $request)
    {
        $integracion = (string) $request->get('search_integration', '');
        $soloFallos  = (bool) $request->get('search_failing', false);

        $filas = $this->filas();

        $resumen = $this->resumen($filas);

        $items = array_filter($filas, function ($f) use ($integracion, $soloFallos) {
            if ($integracion !== '' && $f['integration'] !== $integracion) {
                return false;
            }

            return ! $soloFallos || ! in_array($f['status'], ['ok', 'curso'], true);
        });

        $integraciones = [];
        foreach (array_keys($resumen) as $clave) {
            $integraciones[$clave] = $clave;
        }

        $calculado = DB::table('integration_status')->max('computed_at');

        $data = [
            'items'         => array_values($items),
            'resumen'       => array_values($resumen),
            'integraciones' => $integraciones,
            'fallos'        => $this->fallosDeJobs(),
            'calculado'     => $calculado ? Formatter::time()->human($calculado) : null,
            'umbrales'      => [
                'aviso'   => IntegrationStatusService::AVISO_MIN,
                'critico' => IntegrationStatusService::CRITICO_MIN,
            ],
        ];

        return FacadeView::make(
            'admin::IntegrationsStatus.' . ($request->ajax() ? 'table' : 'index')
        )->with($data);
    }

    /** Una fila por vehículo e integración, con su semáforo ya resuelto. */
    private function filas(): array
    {
        $filas = DB::table('integration_status AS s')
            ->join('devices AS d', 'd.id', '=', 's.device_id')
            ->select([
                's.device_id', 's.integration', 's.pending', 's.oldest_at',
                's.last_at', 'd.name', 'd.plate_number',
            ])
            ->orderBy('s.pending', 'desc')
            ->orderBy('d.name')
            ->limit(2000)
            ->get();

        $salida = [];

        foreach ($filas as $f) {
            $salida[] = [
                'device_id'   => (int) $f->device_id,
                'name'        => (string) $f->name,
                'plate'       => (string) ($f->plate_number ?? ''),
                'integration' => (string) $f->integration,
                'pending'     => (int) $f->pending,
                'oldest_at'   => $f->oldest_at ? Formatter::time()->human($f->oldest_at) : null,
                'last_at'     => $f->last_at ? Formatter::time()->human($f->last_at) : null,
                'status'      => IntegrationStatusService::semaforo((int) $f->pending, $f->oldest_at),
            ];
        }

        return $salida;
    }

    /** Totales por integración. Se calculan sobre TODO, no sobre lo filtrado. */
    private function resumen(array $filas): array
    {
        $resumen = [];

        foreach ($filas as $f) {
            $clave = $f['integration'];

            $resumen[$clave] = $resumen[$clave] ?? [
                'integration' => $clave,
                'devices'     => 0,
                'ok'          => 0,
                'curso'       => 0,
                'aviso'       => 0,
                'critico'     => 0,
                'pending'     => 0,
            ];

            $resumen[$clave]['devices']++;
            $resumen[$clave][$f['status']]++;
            $resumen[$clave]['pending'] += $f['pending'];
        }

        return $resumen;
    }

    /**
     * Fallos recientes de los jobs de reenvío: la otra mitad de la señal,
     * porque una cola que no drena suele venir de aquí.
     */
    private function fallosDeJobs(): array
    {
        $fallos = [];

        try {
            $jobs = DB::table('jobs_failed')->orderBy('failed_at', 'desc')->limit(200)->get();

            foreach ($jobs as $j) {
                if (preg_match('/([A-Za-z]+)Job/', (string) $j->payload, $m)) {
                    $fallos[$m[1]] = ($fallos[$m[1]] ?? 0) + 1;
                }
            }
        } catch (\Throwable $e) {
            return [];
        }

        arsort($fallos);

        return $fallos;
    }
}
