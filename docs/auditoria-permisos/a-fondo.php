<?php
/**
 * Segunda vuelta: a los sospechosos se les manda un payload VALIDO, para que
 * la peticion llegue hasta la logica de negocio y el gate -que en varios
 * casos vive en el servicio, no en el controlador- tenga ocasion de saltar.
 *
 * Con payload vacio salia 422 y no se podia distinguir "no hay gate" de "no
 * llego al gate".
 *
 *   403        el gate salto       -> correcto
 *   2xx        escribio            -> HUECO
 *   422        murio en validacion -> sigue sin saberse, se anota
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

$dev = $user->devices()->first();
printf("  usuario %d · %s (grupo 2)\n  vehiculo de prueba: %d %s\n\n",
    $user->id, $user->email, $dev->id, $dev->name);

$manana = date('Y-m-d', strtotime('+1 day'));

$CASOS = [
    ['tasks.store', 'POST', '/ui/v2/api/tasks', [
        'device_id' => $dev->id, 'title' => 'SONDA', 'address_to' => 'Lima',
        'lat_to' => -12.04, 'lng_to' => -77.03, 'date_from' => $manana, 'date_to' => $manana,
    ], 'tasks.create'],

    ['tasks.update', 'PUT', '/ui/v2/api/tasks/' . (int) (DB::table('tasks')->value('id') ?: 1), [
        'device_id' => $dev->id, 'title' => 'SONDA', 'address_to' => 'Lima',
        'lat_to' => -12.04, 'lng_to' => -77.03, 'date_from' => $manana, 'date_to' => $manana,
    ], 'tasks.edit'],

    ['services.store', 'POST', '/ui/v2/api/services', [
        'device_id' => $dev->id, 'name' => 'SONDA', 'expiration_by' => 'days',
        'last_service' => date('Y-m-d'), 'interval' => 30, 'remind' => 7,
    ], 'devices.show (asi lo hace el legacy)'],

    ['commands.storeSchedule', 'POST', '/ui/v2/api/commands/schedules', [
        'device_id' => $dev->id, 'type' => 'gprs', 'message' => 'TEST',
        'time' => '12:00', 'days' => [1],
    ], 'send_command'],

    ['sensors.preview', 'POST', '/ui/v2/api/sensors/preview', [
        'device_id' => $dev->id, 'type' => 'numerical', 'tag_name' => 'io1',
    ], 'devices'],

    ['devices.sensorStore', 'POST', '/ui/v2/api/devices/' . $dev->id . '/sensors', [
        'name' => 'SONDA', 'type' => 'numerical', 'tag_name' => 'io1',
    ], 'devices.edit'],

    ['poi.store', 'POST', '/ui/v2/api/pois', [
        'name' => 'SONDA', 'lat' => -12.04, 'lng' => -77.03,
    ], 'poi'],

    ['routes.update', 'PUT', '/ui/v2/api/routes/' . (int) (DB::table('routes')->value('id') ?: 1), [
        'name' => 'SONDA',
    ], 'routes'],

    ['drivers.store', 'POST', '/ui/v2/api/drivers', [
        'name' => 'SONDA', 'rfid' => '999999',
    ], 'drivers'],

    ['expenses.store', 'POST', '/ui/v2/api/expenses', [
        'device_id' => $dev->id, 'type_id' => 1, 'amount' => 10, 'date' => date('Y-m-d'),
    ], 'device_expenses'],

    ['forwards.store', 'POST', '/ui/v2/api/forwards', [
        'name' => 'SONDA', 'url' => 'https://example.com', 'type' => 'webhook',
    ], 'forwards'],

    ['customEvents.store', 'POST', '/ui/v2/api/custom-events', [
        'name' => 'SONDA', 'type' => 'custom',
    ], 'custom_events'],

    ['deviceGroups.store', 'POST', '/ui/v2/api/device-groups', [
        'title' => 'SONDA',
    ], 'devices_groups'],
];

DB::beginTransaction();
printf("  %-24s %-5s %-46s %s\n", 'caso', 'cod', 'ruta', 'permiso que deberia pedir');
printf("  %s\n", str_repeat('-', 112));
$sospechosos = [];
foreach ($CASOS as [$nombre, $m, $uri, $payload, $perm]) {
    $req = Request::create($uri, $m, $payload);
    $req->setUserResolver(fn () => $GLOBALS['u']);
    $req->headers->set('X-Requested-With', 'XMLHttpRequest');
    $req->headers->set('Accept', 'application/json');
    try {
        ob_start(); $res = app()->handle($req); $eco = ob_get_clean();
        $cod = $res->getStatusCode();
        $cuerpo = substr(preg_replace('/\s+/', ' ', strip_tags((string) $res->getContent())), 0, 60);
    } catch (\Throwable $e) {
        @ob_end_clean();
        $cod = 'EXC'; $cuerpo = substr(get_class($e), 0, 60);
    }
    $marca = in_array($cod, [401, 403], true) ? ' ' : (($cod >= 200 && $cod < 300) ? '<<<< ESCRIBIO' : '?');
    printf("  %-24s %-5s %-46s %-26s %s\n", $nombre, $cod, substr($uri, 0, 44), $perm, $marca);
    if (! in_array($cod, [401, 403], true)) {
        $sospechosos[] = [$nombre, $cod, $cuerpo];
    }
}
DB::rollBack();

print("\n  ── respuestas de los que NO dieron 403 ──────────────────────────\n");
foreach ($sospechosos as [$n, $c, $b]) printf("   %-24s %-5s %s\n", $n, $c, $b);
