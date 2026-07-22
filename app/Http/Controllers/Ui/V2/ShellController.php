<?php

namespace App\Http\Controllers\Ui\V2;

use App\Http\Controllers\Ui\V2\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\View;

/**
 * Frontend\ShellController (SHELL)
 * ----------------------------------------------------------------
 * Thin shell kept for backwards compatibility. The domain-specific
 * methods (live/fleet, devices, dashboard, zones, alerts, history,
 * reports, admin, copilot) live under App\Http\Controllers\Ui\V2\*.
 *
 * This shell only keeps cross-cutting endpoints that don't belong
 * to a single domain:
 *   - preview()      → /ui/v2/preview
 *   - search()       → /ui/v2/api/search          (command palette)
 *   - notifications()→ /ui/v2/api/notifications   (tray feed)
 *   - preferences()  → /ui/v2/api/preferences     (theme/density/map)
 *   - legacy()       → /ui/v2/legacy              (switch back to legacy UI)
 *
 * Extending BaseController gives this shell the same `$this->user`,
 * `buildMapConfig()`, and `PREF_*` constants.
 * ----------------------------------------------------------------
 */
class ShellController extends BaseController
{
    public function preview(Request $request)
    {
        return View::make('ui.v2.pages.preview', [
            'pageTitle' => 'Live Ops',
        ]);
    }

    public function legacy()
    {
        return redirect()->route('objects.index')
            ->withCookie(Cookie::forget('talentus_enabled'));
    }

    /**
     * Global Command Palette search across devices, geofences, POIs.
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->get('q', ''));
        if ($q === '' || mb_strlen($q) < 2) {
            return response()->json(['total' => 0, 'results' => []]);
        }

        $user = $this->user;
        if (! $user) {
            return response()->json(['total' => 0, 'results' => []], 401);
        }

        $results = [];

        try {
            if (method_exists($user, 'devices') && $user->perm('devices', 'view')) {
                $devices = $user->devices()
                    ->search($q)
                    ->limit(8)
                    ->get(['id', 'name', 'imei', 'plate_number']);

                foreach ($devices as $d) {
                    $plate = trim((string) ($d->plate_number ?? ''));
                    $imei  = trim((string) ($d->imei ?? ''));
                    $results[] = [
                        'type'     => 'device',
                        'id'       => $d->id,
                        'label'    => $d->name ?: ($plate ?: $imei),
                        'subtitle' => trim(implode(' · ', array_filter([$plate, $imei]))),
                        'meta'     => 'Vehículo',
                        'url'      => url('/ui/v2/preview') . '#device-' . $d->id,
                    ];
                }
            }
        } catch (\Throwable $e) {
        }

        try {
            if (method_exists($user, 'geofences') && $user->perm('geofences', 'view')) {
                $geofences = $user->geofences()
                    ->search($q)
                    ->limit(6)
                    ->get(['id', 'name']);

                foreach ($geofences as $g) {
                    $results[] = [
                        'type'  => 'geofence',
                        'id'    => $g->id,
                        'label' => $g->name,
                        'meta'  => 'Geocerca',
                        'url'   => url('/ui/v2/preview') . '#geofence-' . $g->id,
                    ];
                }
            }
        } catch (\Throwable $e) {
        }

        try {
            if (method_exists($user, 'pois') && $user->perm('pois', 'view')) {
                $pois = $user->pois()
                    ->search($q)
                    ->limit(4)
                    ->get(['id', 'name']);

                foreach ($pois as $p) {
                    $results[] = [
                        'type'  => 'poi',
                        'id'    => $p->id,
                        'label' => $p->name,
                        'meta'  => 'POI',
                        'url'   => url('/ui/v2/preview') . '#poi-' . $p->id,
                    ];
                }
            }
        } catch (\Throwable $e) {
        }

        return response()->json([
            'total'   => count($results),
            'results' => $results,
        ]);
    }

    /**
     * Tray de notificaciones: últimos eventos de alerta del usuario.
     * El estado "leído" es client-side (localStorage last-seen en
     * notifications.js) — la tabla events no tiene marcador de lectura.
     */
    public function notifications(Request $request): JsonResponse
    {
        $user = $this->user;
        if (! $user) {
            return response()->json(['notifications' => []], 401);
        }

        if (! $user->perm('events', 'view')) {
            return response()->json(['notifications' => []]);
        }

        $items = \Tobuli\Entities\Event::userAccessible($user)
            ->with(['device', 'geofence'])
            ->orderBy('events.id', 'desc')
            ->take(30)
            ->get()
            ->map(function ($event) {
                $desc = trim(implode(' · ', array_filter([
                    $event->device->name ?? null,
                    $event->geofence->name ?? null,
                ])));

                return [
                    'id'          => (int) $event->id,
                    'title'       => (string) $event->title,
                    'description' => $desc,
                    'variant'     => $this->eventSeverity((string) $event->type),
                    'category'    => 'alerts',
                    'read'        => false, // lo resuelve el cliente (last-seen)
                    'created_at'  => optional($event->created_at)->toIso8601String(),
                    'url'         => url('/ui/v2/events'),
                ];
            })
            ->all();

        return response()->json(['notifications' => $items]);
    }

    public function preferences(Request $request): JsonResponse
    {
        $key   = (string) $request->input('key', '');
        $value = (string) $request->input('value', '');

        if ($key === self::PREF_MAP_ID) {
            if (! ctype_digit($value)) {
                return response()->json(['ok' => false, 'error' => 'invalid_preference'], 422);
            }
            Cookie::queue($key, $value, 60 * 24 * 365);
            $user = $this->user;
            if ($user) {
                try {
                    if (isset($user->map_id)) {
                        $user->map_id = (int) $value;
                        $user->save();
                    }
                } catch (\Throwable $e) {
                }
            }
            return response()->json(['ok' => true]);
        }

        $allowed = [
            self::PREF_THEME   => ['light', 'dark'],
            self::PREF_DENSITY => ['comfortable', 'compact'],
        ];

        if (! isset($allowed[$key]) || ! in_array($value, $allowed[$key], true)) {
            return response()->json(['ok' => false, 'error' => 'invalid_preference'], 422);
        }

        Cookie::queue($key, $value, 60 * 24 * 365);

        $user = $this->user;
        if ($user && method_exists($user, 'setSetting')) {
            try {
                $user->setSetting('ui.v2.' . $key, $value);
            } catch (\Throwable $e) {
            }
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Widget configuration modal (UI v2). Renders the new modal reusing the
     * legacy widget catalog (config('lists.widgets')) and the user's current
     * config (getSettings('widgets')). No legacy redirect.
     */
    public function widgetsConfig(Request $request): JsonResponse
    {
        if (function_exists('listsTranslations')) {
            listsTranslations(); // asegura config('lists.widgets')
        }
        $catalog = config('lists.widgets', []);

        $current = $this->user->getSettings('widgets');
        if (empty($current)) {
            $current = settings('widgets');
        }

        // Orden del catálogo según la lista guardada (drag-sort), luego el resto.
        $list = (array) ($current['list'] ?? []);
        $ordered = [];
        foreach ($list as $k) {
            if (isset($catalog[$k])) {
                $ordered[$k] = $catalog[$k];
            }
        }
        foreach ($catalog as $k => $label) {
            if (! isset($ordered[$k])) {
                $ordered[$k] = $label;
            }
        }

        $html = View::make('ui.v2.modals.widgets-config', [
            'catalog' => $ordered,
            'current' => $current,
        ])->render();

        return response()->json(['ok' => true, 'html' => $html]);
    }

    /**
     * Persist the widget configuration (mirrors MyAccountSettingsModalHelper:
     * setSettings('widgets', [default, status, list], true)).
     */
    public function widgetsConfigSave(Request $request): JsonResponse
    {
        if (function_exists('listsTranslations')) {
            listsTranslations();
        }
        $catalog = config('lists.widgets', []);

        $list = array_values(array_filter(
            (array) $request->input('list', []),
            fn ($key) => isset($catalog[$key])
        ));

        $widgets = [
            'default' => $request->input('default') ? 1 : 0,
            'status'  => $request->input('status') ? 1 : 0,
            'list'    => $list,
        ];

        $this->user->setSettings('widgets', $widgets, true);

        return response()->json(['ok' => true]);
    }
}
