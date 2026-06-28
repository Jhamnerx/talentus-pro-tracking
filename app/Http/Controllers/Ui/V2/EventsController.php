<?php

namespace App\Http\Controllers\Ui\V2;

use App\Transformers\Device\DeviceIconTransformer;
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

        $hours   = min(168, max(1, (int) $request->get('hours', 24)));
        $offset  = max(0, (int) $request->get('offset', 0));
        $perPage = min(100, max(20, (int) $request->get('per_page', 50)));
        $type    = trim((string) $request->get('type', ''));
        $q       = trim((string) $request->get('q', ''));
        $since   = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));

        // Cargamos el device con su icono + traccar para reconstruir el MARCADOR
        // real (arrow/rotating/icon) igual que el mapa Live.
        $query = $this->user->events()
            ->with(['device' => function ($q) {
                $q->with(['icon', 'traccar']);
            }])
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

        // Paginación por offset (scroll infinito): pedimos uno extra para saber si hay más.
        $rows = $query->offset($offset)->limit($perPage + 1)->get();
        $hasMore = $rows->count() > $perPage;
        if ($hasMore) {
            $rows = $rows->slice(0, $perPage);
        }

        $events = [];
        $byType = [];
        $iconTransformer = new DeviceIconTransformer();
        $iconCache = [];
        foreach ($rows as $e) {
            $t = (string) $e->type;
            $byType[$t] = ($byType[$t] ?? 0) + 1;

            // Icono del device (cacheado por device → varios eventos comparten device).
            $icon = null;
            $iconColor = null;
            $course = null;
            if ($e->device) {
                $did = $e->device->id;
                if (! array_key_exists($did, $iconCache)) {
                    $dev = $e->device;
                    $iconCache[$did] = [
                        'icon'       => $iconTransformer->transform($dev),
                        'icon_color' => $dev->getStatusColor(),
                        'course'     => $dev->course,
                    ];
                }
                $icon      = $iconCache[$did]['icon'];
                $iconColor = $iconCache[$did]['icon_color'];
                $course    = $iconCache[$did]['course'];
            }

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
                'icon'        => $icon,
                'icon_color'  => $iconColor,
                'course'      => $course !== null ? (float) $course : null,
            ];
        }

        return response()->json([
            'ok'       => true,
            'hours'    => $hours,
            'events'   => $events,
            'by_type'  => $byType,
            'offset'   => $offset + count($events),
            'has_more' => $hasMore,
        ]);
    }
}
