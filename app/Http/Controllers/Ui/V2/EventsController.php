<?php

namespace App\Http\Controllers\Ui\V2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

/**
 * UI v2 · Eventos — página dedicada con mapa.
 *
 * Lista los eventos del usuario y, al seleccionar uno, centra el mapa en la
 * ubicación del evento y muestra el marcador del dispositivo con un popup de
 * información. Reutiliza el mismo feed de eventos que la página de Alertas
 * ($user->events()), sin geocodificar en lote (rápido).
 */
class EventsController extends BaseController
{
    public function index(Request $request)
    {
        if (! $this->user->perm('events', 'view')) {
            abort(403, 'No tienes permiso para ver eventos.');
        }

        return View::make('ui.v2.pages.events', [
            'pageTitle' => 'Eventos',
            'mapConfig' => $this->buildMapConfig(),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        if (! $this->user->perm('events', 'view')) {
            return response()->json(['ok' => false], 403);
        }

        $hours = min(168, max(1, (int) $request->get('hours', 24)));
        $limit = min(1000, max(50, (int) $request->get('limit', 300)));
        $type  = trim((string) $request->get('type', ''));
        $q     = trim((string) $request->get('q', ''));
        $since = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));

        $query = $this->user->events()
            ->with(['device:id,name,plate_number'])
            ->where('time', '>=', $since)
            ->orderBy('time', 'desc');

        if ($type !== '') {
            $query->where('type', $type);
        }
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('message', 'like', '%' . $q . '%');
            });
        }

        $rows = $query->limit($limit)->get();

        $events = [];
        $byType = [];
        foreach ($rows as $e) {
            $t = (string) $e->type;
            $byType[$t] = ($byType[$t] ?? 0) + 1;

            $events[] = [
                'id'          => (int) $e->id,
                'type'        => $t,
                'severity'    => $this->eventSeverity($t),
                'message'     => (string) $e->message,
                'device_id'   => $e->device_id ? (int) $e->device_id : null,
                'device_name' => $e->device->name ?? null,
                'plate'       => $e->device->plate_number ?? null,
                'time'        => (string) $e->time,
                'lat'         => $e->latitude !== null ? (float) $e->latitude : null,
                'lng'         => $e->longitude !== null ? (float) $e->longitude : null,
                'speed'       => $e->speed !== null ? (float) $e->speed : null,
            ];
        }

        return response()->json([
            'ok'      => true,
            'hours'   => $hours,
            'events'  => $events,
            'by_type' => $byType,
        ]);
    }
}
