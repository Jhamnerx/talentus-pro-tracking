<?php

namespace App\Http\Controllers\Frontend;

use App\Exceptions\ResourseNotFoundException;
use App\Http\Controllers\Controller;
use Illuminate\Support\Arr;
use Tobuli\Entities\Geofence;
use Tobuli\Entities\Route;
use Tobuli\Entities\RouteCheckpoint;

/**
 * Puntos de control de una ruta.
 *
 * Un punto de control es una geocerca ya existente del usuario: no se dibuja
 * nada nuevo aqui. La ruta dice el recorrido; los puntos dicen por donde hay
 * que pasar y cuando.
 */
class RouteCheckpointsController extends Controller
{
    public function edit($routeId)
    {
        $route = $this->findRoute($routeId);

        $this->checkException('routes', 'edit', $route);

        $route->load('checkpoints.geofence');

        return view('front::RouteCheckpoints.edit')->with([
            'route'     => $route,
            'geofences' => $this->geofenceOptions(),
            'outbound'  => $route->checkpointsFor(RouteCheckpoint::DIRECTION_OUTBOUND),
            'inbound'   => $route->checkpointsFor(RouteCheckpoint::DIRECTION_INBOUND),
        ]);
    }

    public function update($routeId)
    {
        $route = $this->findRoute($routeId);

        $this->checkException('routes', 'edit', $route);

        // Las geocercas se comprueban contra las del usuario: el id llega del
        // formulario y podria apuntar a la geocerca de otro cliente.
        $permitidas = $this->geofenceOptions()->keys()->all();

        $nuevos = array_merge(
            $this->readDirection('out', RouteCheckpoint::DIRECTION_OUTBOUND, $permitidas),
            $this->readDirection('in', RouteCheckpoint::DIRECTION_INBOUND, $permitidas)
        );

        // Se borra y se rehace: los puntos no tienen historia propia (el
        // cumplimiento se recalcula desde las posiciones), asi que reescribir
        // es mas simple y mas fiable que casar altas, bajas y reordenes.
        beginTransaction();

        try {
            RouteCheckpoint::where('route_id', $route->id)->delete();

            foreach ($nuevos as $punto) {
                RouteCheckpoint::create($punto + ['route_id' => $route->id]);
            }

            commitTransaction();
        } catch (\Exception $e) {
            rollbackTransaction();

            throw $e;
        }

        return ['status' => 1];
    }

    /**
     * Lee una tanda de filas del formulario. Los arrays van en paralelo (una
     * posicion por fila), que es como los manda el repetidor de la UI.
     */
    private function readDirection(string $prefijo, string $direction, array $permitidas): array
    {
        $geocercas = (array) Arr::get($this->data, "cp_{$prefijo}_geofence", []);
        $nombres   = (array) Arr::get($this->data, "cp_{$prefijo}_name", []);
        $offsets   = (array) Arr::get($this->data, "cp_{$prefijo}_offset", []);
        $antes     = (array) Arr::get($this->data, "cp_{$prefijo}_early", []);
        $despues   = (array) Arr::get($this->data, "cp_{$prefijo}_late", []);

        $puntos   = [];
        $posicion = 0;

        foreach ($geocercas as $i => $geofenceId) {
            $geofenceId = (int) $geofenceId;

            // Fila vacia: el repetidor siempre deja una al final
            if (! $geofenceId || ! in_array($geofenceId, $permitidas, true)) {
                continue;
            }

            $puntos[] = [
                'geofence_id'     => $geofenceId,
                'direction'       => $direction,
                'position'        => ++$posicion,
                'name'            => trim((string) ($nombres[$i] ?? '')) ?: null,
                'planned_offset'  => max(0, (int) ($offsets[$i] ?? 0)),
                'tolerance_early' => $this->opcional($antes[$i] ?? null),
                'tolerance_late'  => $this->opcional($despues[$i] ?? null),
            ];
        }

        return $puntos;
    }

    /** Vacio significa "usa la tolerancia de la programacion", no cero. */
    private function opcional($valor): ?int
    {
        return ($valor === null || $valor === '') ? null : max(0, (int) $valor);
    }

    private function geofenceOptions()
    {
        return Geofence::userAccessible($this->user)
            ->orderBy('name')
            ->pluck('name', 'id');
    }

    private function findRoute($id): Route
    {
        $route = Route::userOwned($this->user)->find($id);

        if (! $route) {
            throw new ResourseNotFoundException(trans('front.route'));
        }

        return $route;
    }
}
