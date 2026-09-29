<?php

namespace App\Http\Controllers\Ui\V2;

use App\Transformers\Device\DeviceIconTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Tobuli\Entities\Event;

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
    /**
     * Feed de eventos NUEVOS para notificaciones en vivo (popup + sonido).
     * Paridad con el bloque de eventos de DeviceModalHelper::itemsJson():
     * ventana máx 5 min desde `time`, excluye silenciados (higherTime) y
     * solo alertas con el canal popup activo. El EventLatestTransformer
     * deriva sound (URL del mp3), color y delay de alert.notifications.
     */
    public function live(Request $request): JsonResponse
    {
        $now = time();

        if (! $this->user->perm('events', 'view')) {
            return response()->json(['ok' => true, 'events' => [], 'time' => $now]);
        }

        $time = (int) $request->get('time') ?: $now - 5;
        $eventTime = ($now - $time > 300) ? $now - 300 : $time;

        $events = \Tobuli\Entities\Event::userAccessible($this->user)
            ->higherTime($eventTime, null)
            ->get()
            ->filter(function ($event) {
                return \Illuminate\Support\Arr::get($event, 'alert.notifications.popup.active', true);
            })
            ->values();

        $payload = \FractalTransformer::collection($events, \App\Transformers\Event\EventLatestTransformer::class)->toArray();

        return response()->json([
            'ok'     => true,
            'events' => $payload['data'] ?? $payload,
            'time'   => $now,
        ]);
    }

    public function index(Request $request)
    {
        if (! $this->user->perm('events', 'view')) {
            abort(403, trans('ui.no_permission_events'));
        }

        return View::make('ui.v2.pages.events', [
            'pageTitle' => 'Eventos',
            'mapConfig' => $this->buildMapConfig(),
            // Vehículos del usuario para el filtro (tp-select con búsqueda).
            'devices'   => $this->user->devices()->orderBy('devices.name')
                ->get(['devices.id', 'devices.name'])
                ->map(function ($d) {
                    return ['id' => (int) $d->id, 'name' => (string) $d->name];
                })->all(),
            // Tipos de respuesta REALES (mismas etiquetas que la tabla de
            // Acciones de llamada) para el modal "Atender".
            'responseTypes' => \Tobuli\Entities\CallAction::getResponseTypes(),
            // Catálogo COMPLETO de tipos de evento/alerta (mismas etiquetas y
            // gating por permiso que el dropdown legacy) para el filtro Tipo.
            'eventTypes' => collect(\ModalHelpers\AlertModalHelper::getTypes())
                ->map(function ($t) {
                    return ['type' => $t['type'], 'title' => $t['title']];
                })->values()->all(),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        if (! $this->user->perm('events', 'view')) {
            return response()->json(['ok' => false], 403);
        }

        // hours = 0 → "Todos" (sin límite temporal, como el legacy: carga todo
        // paginado por scroll). Cualquier otro valor se acota a [1, 168].
        $hoursRaw = (int) $request->get('hours', 0);
        $hours    = $hoursRaw <= 0 ? 0 : min(168, max(1, $hoursRaw));
        $offset   = max(0, (int) $request->get('offset', 0));
        $perPage  = min(100, max(20, (int) $request->get('per_page', 50)));
        $type     = trim((string) $request->get('type', ''));
        $q        = trim((string) $request->get('q', ''));
        $deviceId = (int) $request->get('device_id', 0);

        // Cargamos el device con su icono + traccar para reconstruir el MARCADOR
        // real (arrow/rotating/icon) igual que el mapa Live.
        // userAccessible (no user->events()): incluye eventos de alertas
        // COMPARTIDAS vía alert_user, como el legacy — con user->events() los
        // sub-usuarios con alertas compartidas veían la lista vacía.
        $query = Event::userAccessible($this->user)
            ->with(['device' => function ($q) {
                // sensors: DeviceIconTransformer los consulta para el color del
                // marcador; sin eager-load eran ~1 query por evento.
                $q->with(['icon', 'traccar', 'sensors']);
            }])
            // Con el plugin event_section_alert el título sale del nombre de la
            // alerta: eager-load para no disparar N+1 (igual que el legacy).
            ->when(settings('plugins.event_section_alert.status'), function ($q) {
                $q->with('alert');
            })
            ->orderBy('time', 'desc');

        // Rango absoluto (date_from/date_to): tiene prioridad sobre la ventana
        // relativa 'hours'. Las fechas llegan en la TZ del usuario → reverse()
        // a la zona almacenada, igual que EventModalHelper::lookup.
        $dateFrom = trim((string) $request->get('date_from', ''));
        $dateTo   = trim((string) $request->get('date_to', ''));

        if ($dateFrom !== '' || $dateTo !== '') {
            if ($dateFrom !== '') {
                $query->where('time', '>=', \Formatter::time()->reverse($dateFrom));
            }
            if ($dateTo !== '') {
                $query->where('time', '<=', \Formatter::time()->reverse($dateTo));
            }
        } elseif ($hours > 0) {
            $query->where('time', '>=', date('Y-m-d H:i:s', strtotime("-{$hours} hours")));
        }

        if ($type !== '') {
            $query->where('type', $type);
        }
        if ($deviceId > 0) {
            $query->where('device_id', $deviceId);
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
                // Título con el plugin event_section_alert aplicado: cuando está
                // activo el legacy muestra el NOMBRE DE LA ALERTA en vez del
                // mensaje (Event::getTitleAttribute()).
                'title'       => (string) $e->title,
                // Solo eventos con alerta pueden registrarse como acción de
                // llamada ("Atender"): gatea el botón en el popup.
                'alert_id'    => $e->alert_id ? (int) $e->alert_id : null,
                'device_id'   => $e->device_id ? (int) $e->device_id : null,
                'device_name' => $e->device->name ?? null,
                'plate'       => $e->device->plate_number ?? null,
                // convert() = UTC → TZ del usuario ('Y-m-d H:i:s'): el crudo de
                // BD mostraba hora de servidor y el salto a Historial abría el
                // tramo equivocado.
                'time'        => \Formatter::time()->convert($e->time),
                'lat'         => $e->latitude !== null ? (float) $e->latitude : null,
                'lng'         => $e->longitude !== null ? (float) $e->longitude : null,
                'speed'       => $e->speed !== null ? (float) $e->speed : null,
                'icon'        => $icon,
                'icon_color'  => $iconColor,
                'course'      => $course !== null ? (float) $course : null,
            ];
        }

        // Marca los eventos que YA tienen una acción de llamada registrada
        // (indicador "Atendido" en la lista). Un solo query por página.
        $ids = array_column($events, 'id');
        if ($ids) {
            $attended = \Tobuli\Entities\CallAction::whereIn('event_id', $ids)
                ->pluck('event_id')
                ->map(function ($v) { return (int) $v; })
                ->flip();
            foreach ($events as &$evRow) {
                $evRow['attended'] = $attended->has($evRow['id']);
            }
            unset($evRow);
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

    /**
     * Puntos para el "mapa de riesgo" de la flota: TODOS los eventos del filtro
     * actual con coordenadas (capado), coloreados por severidad. Alimenta la
     * capa de marcadores + leyenda flotante de la página de Eventos.
     */
    public function mapData(Request $request): JsonResponse
    {
        if (! $this->user->perm('events', 'view')) {
            return response()->json(['ok' => false, 'points' => []], 403);
        }

        $hoursRaw = (int) $request->get('hours', 0);
        $hours    = $hoursRaw <= 0 ? 0 : min(168, max(1, $hoursRaw));
        $type     = trim((string) $request->get('type', ''));
        $q        = trim((string) $request->get('q', ''));
        $deviceId = (int) $request->get('device_id', 0);
        $cap      = 1500;

        // Mismo alcance que data(): eventos propios + de alertas compartidas.
        $query = Event::userAccessible($this->user)
            ->with(['device' => function ($qq) { $qq->select('id', 'name'); }])
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('time', 'desc');

        if ($hours > 0) {
            $query->where('time', '>=', date('Y-m-d H:i:s', strtotime("-{$hours} hours")));
        }
        if ($type !== '') {
            $query->where('type', $type);
        }
        if ($deviceId > 0) {
            $query->where('device_id', $deviceId);
        }
        if ($q !== '') {
            $query->where('message', 'like', '%' . $q . '%');
        }

        $query->when(settings('plugins.event_section_alert.status'), function ($q) {
            $q->with('alert');
        });
        // alert_id es necesario para que Event::getTitleAttribute() resuelva
        // el nombre de la alerta cuando el plugin está activo.
        $rows   = $query->limit($cap + 1)->get(['id', 'type', 'message', 'alert_id', 'device_id', 'latitude', 'longitude', 'time']);
        $capped = $rows->count() > $cap;
        if ($capped) {
            $rows = $rows->slice(0, $cap);
        }

        $points = $rows->map(function ($e) {
            return [
                'id'          => (int) $e->id,
                'lat'         => (float) $e->latitude,
                'lng'         => (float) $e->longitude,
                'severity'    => $this->eventSeverity((string) $e->type),
                'message'     => (string) $e->message,
                'title'       => (string) $e->title,
                'device_name' => $e->device->name ?? null,
                'time'        => \Formatter::time()->convert($e->time),
            ];
        })->values()->all();

        return response()->json(['ok' => true, 'points' => $points, 'capped' => $capped, 'cap' => $cap]);
    }

    /**
     * Borrar un evento (o TODOS los del usuario si no se pasa id).
     * Paridad legacy EventModalHelper::destroy: perm 'events.clean' (→ remove)
     * y alcance userControllable (SOLO los propios; los de alertas compartidas
     * se ven pero no se borran).
     */
    public function destroy(Request $request, $id = null): JsonResponse
    {
        if (! $this->user->perm('events', 'remove')) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

        $deleted = Event::userControllable($this->user)
            ->when($id, function ($q) use ($id) {
                $q->where('id', (int) $id);
            })
            ->delete();

        return response()->json(['ok' => true, 'deleted' => (int) $deleted]);
    }
}
