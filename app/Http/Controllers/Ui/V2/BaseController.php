<?php

namespace App\Http\Controllers\Ui\V2;

use App\Http\Controllers\Controller;

/**
 * TalentusPro · BaseController
 * -----------------------------------------------------------------
 * Shared helpers + constants used by every controller under the
 * /ui/v2/* surface. Domain-specific controllers extend this class
 * to avoid duplicating map config, preference keys, and small
 * status-grouping helpers.
 *
 * Auth + $this->user are provided by App\Http\Controllers\Controller.
 * -----------------------------------------------------------------
 */
abstract class BaseController extends Controller
{
    public const PREF_THEME   = 'tp_theme';
    public const PREF_DENSITY = 'tp_density';
    public const PREF_MAP_ID  = 'tp_map_id';

    /** users.settings: 'v2' | 'legacy' — decide a qué UI entra el usuario. */
    public const PREF_UI_VERSION = 'ui_version';

    public function __construct()
    {
        parent::__construct();

        // Recordar la preferencia de UI: cualquier request al shell v2 marca
        // ui_version='v2' (así el usuario "siempre ve la v2": el legacy
        // ObjectsController@index redirige acá cuando la preferencia es v2).
        // "Versión anterior" la revierte en ShellController@legacy — esa ruta
        // se excluye para no pisarla. Solo escribe cuando el valor cambia.
        $this->middleware(function ($request, $next) {
            $user = \Illuminate\Support\Facades\Auth::user();
            if ($user && ! $request->routeIs('ui.v2.legacy')
                && $user->getSettings(self::PREF_UI_VERSION) !== 'v2') {
                $user->setSettings(self::PREF_UI_VERSION, 'v2');
            }

            return $next($request);
        });
    }

    /**
     * Build the map provider config consumed by every map-bearing page
     * (live, zones, history, …). Mirrors the legacy `getAvailableMaps()`
     * + `Helper::getJsConfig()` outputs but trimmed to the keys the
     * TalentusPro JS reads from `#tp-*-map-config` JSON blobs.
     */
    protected function buildMapConfig(): array
    {
        $available = function_exists('getAvailableMaps') ? getAvailableMaps() : [];
        $list = [];
        foreach ($available as $id => $name) {
            $list[] = ['id' => (int) $id, 'name' => (string) $name];
        }
        usort($list, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        $apiKeys = [
            'google_maps_key'     => settings('main_settings.google_maps_key'),
            'here_api_key'        => settings('main_settings.here_api_key'),
            'here_political_view' => settings('main_settings.here_political_view'),
            'mapbox_access_token' => settings('main_settings.mapbox_access_token'),
            'bing_maps_key'       => settings('main_settings.bing_maps_key'),
            'maptiler_key'        => settings('main_settings.maptiler_key'),
            'tomtom_key'          => settings('main_settings.tomtom_key'),
            'azure_maps_key'      => settings('main_settings.azure_maps_key'),
        ];

        // Estado de toggles compartido con el legacy: vive en user->map_controls
        // (keys m_*), con defaults globales settings('map_controls'). Igual que getJsConfig.
        $mc = (array) ($this->user->map_controls ?? []);
        $def = (array) settings('map_controls');
        $tg = function (string $mKey, string $defKey) use ($mc, $def): bool {
            if (array_key_exists($mKey, $mc)) {
                return (bool) $mc[$mKey];
            }
            return (bool) ($def[$defKey] ?? false);
        };

        return [
            'mapId'            => $this->user->map_id ? (int) $this->user->map_id : null,
            'available'        => $list,
            'keys'             => $apiKeys,
            'openmaptilesUrl'  => settings('main_settings.openmaptiles_url'),
            'googleQueryParam' => '',
            'locale'           => app()->getLocale(),
            // Color del marcador por velocidad: los valores por defecto van
            // aqui una sola vez, y cada vehiculo solo manda lo que cambie.
            // Los limites ya llegan en la unidad del usuario (el feed de zonas
            // los convierte), asi que estos rangos van en la misma unidad.
            'markerColors'     => (array) config('tobuli.marker_speed_colors', []),
            // Animacion de movimiento del marcador (plugin del admin). Misma
            // clave que lee el legacy en getJsConfig -> animateDeviceMove.
            'animateMove'      => (bool) settings('plugins.device_move_animation.status'),
            // Tamaño de la geocerca en su etiqueta (plugin del admin), con la
            // unidad de distancia del usuario. Igual que el legacy en
            // getJsConfig -> showGeofenceSize + units.distance.
            'geofenceSize'     => (bool) settings('plugins.geofence_size.status'),
            'distance'         => [
                'unit'  => \Formatter::distance()->getUnit(),
                'ratio' => \Formatter::distance()->getRatio(),
            ],
            // Toggles de capas (mismo store que el legacy → user->map_controls)
            'toggles'          => [
                'devices'   => $tg('m_objects', 'showDevice'),
                'geofences' => $tg('m_geofences', 'showGeofences'),
                'routes'    => $tg('m_routes', 'showRoutes'),
                'poi'       => $tg('m_poi', 'showPoi'),
                'names'     => $tg('m_show_names', 'showNames'),
                'tails'     => $tg('m_show_tails', 'showTail'),
                'traffic'   => $tg('m_traffic', 'showTraffic'),
                'cluster'   => $tg('m_objects_cluster', 'clusterDevice'),
                'hitos'     => $tg('m_hitos', 'showHitos'),
                // Preferencias del marcador (Ajustes de cuenta → Mapa)
                'showSpeed'    => array_key_exists('m_show_speed', $mc)
                    ? (bool) $mc['m_show_speed']
                    : (bool) settings('main_settings.map_show_device_speed'),
                'statusColor'  => (bool) ($mc['m_status_color'] ?? false),
            ],
            // Endpoints legacy de persistencia (estado compartido con la UI clásica)
            'urls'             => [
                'changeMapSettings' => route('my_account_settings.change_map_settings'),
                'changeMap'         => route('my_account.change_map'),
            ],
        ];
    }

    /**
     * Group a raw device status (online, idle, stop, …) into the four
     * high-level buckets the new UI surfaces in filters and KPIs.
     */
    /**
     * Código HTTP que le corresponde a una excepción capturada.
     *
     * Los `catch (\Throwable)` devolvían siempre 500, así que un recurso
     * inexistente (ResourseNotFoundException, que YA es un 404) o un permiso
     * denegado llegaban al cliente como error de servidor: ensucia los logs y
     * el front no puede distinguir "no existe" de "se rompió".
     */
    protected function statusFor(\Throwable $e, int $default = 500): int
    {
        if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            return $e->getStatusCode();
        }

        if ($e instanceof \App\Exceptions\PermissionException) {
            return 403;
        }

        return $default;
    }

    protected function groupStatus(string $status): string
    {
        if (in_array($status, ['online', 'move', 'excessive_speed'], true)) {
            return 'moving';
        }
        // El naranja de ralenti es SOLO del motor encendido. Un vehiculo
        // parado con el motor apagado (ack) va con los detenidos, como en el
        // legacy (Device::getStatusColor: STATUS_ACK -> 'stopped') y como ya
        // hace la lista de Live desde ccff70c7.
        if (in_array($status, ['engine', 'idle'], true)) {
            return 'idle';
        }
        // blocked estaba cayendo en 'offline': el vehiculo esta parado, no
        // desconectado. El legacy le da color propio, que aqui no existe.
        if (in_array($status, ['ack', 'stop', 'park', 'blocked'], true)) {
            return 'stopped';
        }
        return 'offline';
    }

    /**
     * Severity bucket for an event type (UI-only convention).
     */
    protected function eventSeverity(string $type): string
    {
        $critical = ['sos', 'overspeed', 'geofence_overspeed', 'unplugged', 'fuel_theft', 'jump_from_stop', 'ghost_speed'];
        $warning  = ['zone_out', 'idle_duration', 'stop_duration', 'operation_stop', 'offline_duration', 'expiring_device', 'expiring_sim', 'expiring_user', 'driver_unauthorized'];
        $info     = ['zone_in', 'move_start', 'ignition_on', 'ignition_off', 'driver', 'driver_authorized', 'plugged_in', 'fuel_fill', 'task_new', 'task_complete'];
        if (in_array($type, $critical, true)) return 'critical';
        if (in_array($type, $warning, true))  return 'warning';
        if (in_array($type, $info, true))     return 'info';
        return 'info';
    }

    /**
     * Haversine distance in meters between two WGS84 coordinates.
     */
    protected function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $R * $c;
    }
}
