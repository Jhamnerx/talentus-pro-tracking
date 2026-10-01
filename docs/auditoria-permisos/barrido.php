<?php
/**
 * Prueba de permisos por EJECUCION, no por lectura del codigo.
 *
 * Los gates de la v2 viven en cuatro capas (controlador -> ayudante privado
 * -> servicio -> ModalHelper -> Manager), asi que leer el codigo da falsos
 * positivos sin parar. Aqui se lanza una peticion real a cada ruta de
 * escritura de /ui/v2 con un usuario del grupo 2, que NO tiene permiso de
 * edicion en poi, routes, tasks, drivers, gastos, devices, comandos,
 * eventos, forwards, servicios, camaras, usuarios ni checklists.
 *
 * Lectura del resultado:
 *   403 / 401        el gate salto      -> correcto
 *   422              paso el gate y murio en validacion  -> REVISAR
 *   200 / 2xx        paso del todo                       -> REVISAR
 *   404              no llego a saberse (recurso ausente)
 *
 * Todo va dentro de una transaccion que se revierte, y ademas se comparan
 * los recuentos de tablas antes y despues.
 */

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tobuli\Entities\User;

$user = User::where('group_id', 2)->orderBy('id')->first();
$GLOBALS['u'] = $user;
auth()->setUser($user);
app()->instance(\App\Http\Middleware\VerifyCsrfToken::class, new class {
    public function handle($r, $next) { return $next($r); }
});

printf("  usuario de prueba: %d · %s · grupo %d\n\n", $user->id, $user->email, $user->group_id);

// ── recuentos antes ──────────────────────────────────────────────────────
$TABLAS = ['user_map_icons', 'routes', 'tasks', 'user_drivers', 'device_expenses',
           'geofences', 'alerts', 'reports', 'device_services', 'events_custom',
           'device_sensors', 'users', 'devices', 'command_templates'];
$antes = [];
foreach ($TABLAS as $t) {
    try { $antes[$t] = DB::table($t)->count(); } catch (\Throwable $e) {}
}

// ── rutas de escritura de la v2 ──────────────────────────────────────────
$rutas = [];
foreach (app('router')->getRoutes() as $r) {
    $uri = $r->uri();
    if (strpos($uri, 'ui/v2/') !== 0) continue;
    $metodos = array_diff($r->methods(), ['HEAD']);
    foreach ($metodos as $m) {
        if (! in_array($m, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) continue;
        $rutas[] = ['m' => $m, 'uri' => $uri, 'action' => $r->getActionName()];
    }
}
printf("  rutas de escritura en /ui/v2: %d\n\n", count($rutas));

// ids que EXISTEN y son de otro usuario (admin): asi se prueba permiso y
// propiedad a la vez, no solo "no existe"
$admin = User::where('group_id', 1)->orderBy('id')->first();
$ids = [
    'poi'      => optional(DB::table('user_map_icons')->where('user_id', $admin->id)->first())->id,
    'route'    => optional(DB::table('routes')->where('user_id', $admin->id)->first())->id,
    'device'   => optional(DB::table('devices')->first())->id,
    'generico' => 1,
];

function rellena(string $uri, array $ids): string
{
    $out = $uri;
    foreach (['poi' => 'poi', 'route' => 'route', 'device' => 'device'] as $k => $v) {
        $out = str_replace('{' . $k . '}', (string) ($ids[$v] ?? 1), $out);
    }
    // cualquier otro {param} o {param?}
    $out = preg_replace('~\{[a-zA-Z_]+\??\}~', (string) ($ids['generico'] ?? 1), $out);

    return $out;
}

$res = [];
DB::beginTransaction();
foreach ($rutas as $r) {
    $uri = rellena($r['uri'], $ids);
    $req = Request::create('/' . ltrim($uri, '/'), $r['m'], []);
    $req->setUserResolver(fn () => $GLOBALS['u']);
    $req->headers->set('X-Requested-With', 'XMLHttpRequest');
    $req->headers->set('Accept', 'application/json');

    try {
        ob_start();
        $resp = app()->handle($req);
        ob_end_clean();
        $cod = $resp->getStatusCode();
    } catch (\Throwable $e) {
        @ob_end_clean();
        $cod = 'EXC';
    }
    $res[] = ['m' => $r['m'], 'uri' => $uri, 'cod' => $cod,
              'action' => str_replace('App\\Http\\Controllers\\Ui\\V2\\', '', $r['action'])];
}
DB::rollBack();

// ── recuentos despues ────────────────────────────────────────────────────
$cambios = [];
foreach ($TABLAS as $t) {
    if (! isset($antes[$t])) continue;
    $ahora = DB::table($t)->count();
    if ($ahora !== $antes[$t]) $cambios[$t] = $ahora - $antes[$t];
}

// ── informe ──────────────────────────────────────────────────────────────
$grupo = ['denegado' => [], 'paso' => [], 'otro' => []];
foreach ($res as $x) {
    if (in_array($x['cod'], [401, 403], true)) $grupo['denegado'][] = $x;
    elseif (is_int($x['cod']) && $x['cod'] >= 200 && $x['cod'] < 300) $grupo['paso'][] = $x;
    elseif ($x['cod'] === 422) $grupo['paso'][] = $x;
    else $grupo['otro'][] = $x;
}

printf("  DENEGADAS (403/401) ................ %d\n", count($grupo['denegado']));
printf("  PASARON EL GATE (2xx o 422) ........ %d   <- revisar\n", count($grupo['paso']));
printf("  otro (404/500/excepcion) ........... %d\n\n", count($grupo['otro']));

print("  ── PASARON EL GATE ──────────────────────────────────────────────\n");
foreach ($grupo['paso'] as $x)
    printf("   %-6s %-3s %-46s %s\n", $x['m'], $x['cod'], $x['uri'], $x['action']);

print("\n  ── otros codigos ────────────────────────────────────────────────\n");
foreach ($grupo['otro'] as $x)
    printf("   %-6s %-3s %-46s %s\n", $x['m'], $x['cod'], $x['uri'], $x['action']);

print("\n  ── efectos en la base ───────────────────────────────────────────\n");
print($cambios ? "   CAMBIOS: " . json_encode($cambios) . "\n" : "   ninguno (transaccion revertida)\n");
