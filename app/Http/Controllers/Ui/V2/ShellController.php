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
    public function legacy()
    {
        // Preferencia explícita del usuario: volver a la UI anterior. Sin esto,
        // ObjectsController@index lo rebotaría de vuelta a la v2 (ui_version).
        if ($this->user) {
            $this->user->setSettings(self::PREF_UI_VERSION, 'legacy');
        }

        return redirect()->route('objects.index')
            ->withCookie(Cookie::forget('talentus_enabled'));
    }

    /**
     * Elección explícita de la UI v2 (botón «Nueva UI» del header legacy):
     * guarda ui_version='v2' para que ObjectsController@index redirija aquí.
     */
    public function switchToV2()
    {
        if ($this->user && $this->user->getSettings(self::PREF_UI_VERSION) !== 'v2') {
            $this->user->setSettings(self::PREF_UI_VERSION, 'v2');
        }

        return redirect()->route('ui.v2.live');
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
                        'meta'     => trans('ui.vehicle'),
                        'url'      => url('/ui/v2/live') . '?device=' . $d->id,
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
                        'meta'  => trans('ui.search_kind_geofence'),
                        'url'   => url('/ui/v2/live') . '?panel=zones',
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
                        'meta'  => trans('ui.search_kind_poi'),
                        'url'   => url('/ui/v2/live') . '?panel=pois',
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

        // Misma bandeja que la app (api get_notifications): NotificationsTrayService.
        // seen_id null = cuenta demo: el cliente resuelve el leído en el navegador.
        return response()->json(
            app(\Tobuli\Services\NotificationsTrayService::class)->page(
                $user,
                (int) $request->get('before_id', 0),
                // Fallback de navegación (sin coords o fuera de Live).
                fn ($event) => $event->device_id
                    ? url('/ui/v2/live') . '?device=' . (int) $event->device_id
                    : url('/ui/v2/events')
            )
        );
    }

    /**
     * «Marcar todo como leído»: guarda el id del evento más nuevo que el
     * usuario tenía en la bandeja. Solo avanza (otro dispositivo con la lista
     * desfasada no puede desmarcar lo que ya se leyó). Demo: como las demás
     * preferencias, solo en el navegador.
     */
    public function notificationsSeen(Request $request): JsonResponse
    {
        $user = $this->user;
        if (! $user) {
            return response()->json(['ok' => false], 401);
        }

        $result = app(\Tobuli\Services\NotificationsTrayService::class)
            ->markSeen($user, (int) $request->input('last_id', 0));

        return response()->json($result, $result['ok'] ? 200 : 422);
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
            // Paridad legacy (MyAccountController::changeMap): la cuenta demo
            // aplica la preferencia en el navegador pero nunca la persiste.
            if (isDemoUser()) {
                return response()->json(['ok' => true, 'demo' => true]);
            }
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

        // Idem para tema/densidad: en demo solo cookie (MyAccountSettingsController::changeMapSettings).
        if (isDemoUser()) {
            return response()->json(['ok' => true, 'demo' => true]);
        }

        // users.settings (clave plana, como ui_version): así la preferencia sigue al
        // usuario en otro navegador/dispositivo. El layout la lee al renderizar.
        $user = $this->user;
        if ($user) {
            try {
                if ($user->getSettings($key) !== $value) {
                    $user->setSettings($key, $value);
                }
            } catch (\Throwable $e) {
                report($e);
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

        // El legacy (MyAccountSettingsModalHelper::edit) rechaza el guardado en
        // cuentas demo; aquí se responde ok sin persistir para no romper la UI.
        if (isDemoUser()) {
            return response()->json(['ok' => true, 'demo' => true]);
        }

        $this->user->setSettings('widgets', $widgets, true);

        return response()->json(['ok' => true]);
    }
}
