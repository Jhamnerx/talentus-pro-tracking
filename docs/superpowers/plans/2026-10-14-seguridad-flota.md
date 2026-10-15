# Módulo «Seguridad · Control de flota» — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Objetivo:** una pantalla de la UI v2 que reproduce el Excel `docs/Dashboard_FM_Seguridad.xlsx` del cliente (hojas Seguridad, Operaciones, Datos, Telemetría) con filtros, gráficos interactivos y exportación al mismo Excel, alimentada con datos reales de la plataforma.

**Arquitectura:** una tabla de **agregados diarios por unidad y turno** (`security_daily_stats`) que llena un comando nocturno usando el pipeline de historial (`Tobuli/History`), más una tabla de **ajustes por usuario** (turno, límite manual, vigencia). La página lee los agregados (rápido) y el estado en vivo de `TraccarDevice`; el Excel se genera en servidor con `maatwebsite/excel` (PhpSpreadsheet) con hojas y gráficos nativos. Sin estado en memoria: todo recalculable por rango.

**Stack:** Laravel 8 (PHP 8), MySQL 8 dev / MariaDB 10.5 prod (tipos portables), Blade `resources/views/ui/v2`, JS vanilla en `resources/assets/ui-v2/js` compilado con `gulp ui-v2-js` / `ui-v2-css`, ECharts 5 (ya lo usa el tablero), `maatwebsite/excel` 3.1.

**Convenciones del proyecto que aplican (no negociables):**
- Sin pruebas automatizadas (decisión del proyecto, ver memoria `feedback_no_default_tests`). Cada tarea termina con una **verificación manual** reproducible (`php artisan tinker`, navegador o `curl`), y el resultado esperado está escrito.
- Migraciones con clase anónima, `dateTime` (no `timestamp`), FKs `unsignedInteger`, sin funciones espaciales.
- Permisos en `config/permissions.php`; no se siembran en `user_permissions` (el admin los marca por usuario).
- Comandos registrados a mano en `app/Console/Kernel.php` (`$commands` y `schedule()`).
- Rail de la v2 en `resources/views/ui/v2/partials/rail.blade.php`; páginas que extienden `ui.v2.layout`; claves i18n en `resources/lang/{es,en}/ui.php` y `front.php` y, si son nuevas en `front`, añadirlas a la lista del puente `resources/views/ui/v2/partials/i18n.blade.php`.
- Commit por tarea, mensaje en castellano con prefijo `feat(seguridad):` / `fix(seguridad):`, y la línea `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.
- Rama: `seguridad`, creada desde `nuevo-tema` (`git checkout -b seguridad nuevo-tema`). Al terminar, merge `--no-ff` a `nuevo-tema` como se hizo con `operaciones`.

---

## Decisiones ya tomadas con el cliente / el usuario

| Tema | Decisión |
| --- | --- |
| Sobrevelocidad | Dos fuentes combinadas: eventos de alertas (`overspeed`, `geofence_overspeed`) **y** cálculo desde posiciones. Dentro de geocerca manda el límite de la geocerca (por tipo/estado de vehículo; menor área si se solapan). Fuera, el **límite manual** del filtro; vacío = fuera no se cuenta. Un exceso = tramo continuo por encima del límite; si un evento cae dentro de un tramo calculado, cuenta una vez. |
| Cinturón | Solo para unidades con un sensor de tipo `seatbelt`. Sin sensor, la celda va vacía (no cero). |
| Turno | Franja fija configurable por usuario en la pantalla; por defecto Día 07:00–19:00, Noche 19:00–07:00 (la noche se atribuye al día en que empieza). |
| Vigencia | Minutos para dar por vigente la última lectura; por defecto 10 (configurable en la pantalla). |
| Tipo de unidad | `vehicle_types` del usuario (Volquete, Camioneta, Minibús, Combi…). Unidad sin tipo = «Sin tipo». |
| Stand by | Motor encendido y velocidad < 1 km/h (en vivo); «Stand by*» diario = horas de motor > 0 y movimiento = 0. |
| Desconexiones | Eventos `power_cut` + `offline_duration` del día **más** huecos de señal > 10 min (`no_data_duration` del historial, contado por hueco). Se guardan por separado y se muestra la suma. |
| Paradas | `stop_count` del historial con el umbral de parada del reporte (`stops` > 1 min). |

---

## Estructura de archivos

```
database/migrations/2026_10_15_000001_create_security_tables.php          (nuevo)
Tobuli/Entities/SecurityDailyStat.php                                     (nuevo)
Tobuli/Entities/SecuritySetting.php                                       (nuevo)
Tobuli/History/Actions/AppendSpeedLimitGeofenceOrStatic.php               (nuevo)
Tobuli/Services/Security/SecurityShift.php                                (nuevo)  franjas de turno
Tobuli/Services/Security/SecurityDailyCalculator.php                      (nuevo)  un día × unidad × turno
Tobuli/Services/Security/SecurityLiveStatus.php                           (nuevo)  estado vigente por unidad
Tobuli/Services/Security/SecuritySummary.php                              (nuevo)  tarjetas, resumen diario, por unidad
Tobuli/Exports/Security/SecurityWorkbookExport.php                        (nuevo)  4 hojas + gráficos
app/Console/Commands/SecurityAggregateCommand.php                         (nuevo)  security:aggregate
app/Http/Controllers/Ui/V2/SecurityController.php                         (nuevo)
resources/views/ui/v2/pages/security.blade.php                            (nuevo)
resources/assets/ui-v2/js/tp-security.js                                  (nuevo)
resources/assets/ui-v2/css/security.css                                   (nuevo)
config/permissions.php, app/Console/Kernel.php, routes/web.php            (modificar)
resources/views/ui/v2/partials/rail.blade.php, partials/i18n.blade.php     (modificar)
resources/lang/{es,en}/ui.php, front.php                                  (modificar)
docs/manual-usuario/manual-usuario-plataforma.html                        (modificar, capítulo nuevo)
```

---

## Fase 1 · Datos

### Task 1: Rama, migración y entidades

**Files:**
- Create: `database/migrations/2026_10_15_000001_create_security_tables.php`
- Create: `Tobuli/Entities/SecurityDailyStat.php`
- Create: `Tobuli/Entities/SecuritySetting.php`

- [ ] **Step 1: Crear la rama**

```bash
git checkout nuevo-tema && git pull origin nuevo-tema && git checkout -b seguridad
```

- [ ] **Step 2: Escribir la migración**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        // Ajustes por usuario: turno, limite manual, vigencia.
        Schema::create('security_settings', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->unique();
            $table->string('day_start', 5)->default('07:00');   // HH:MM local
            $table->string('day_end', 5)->default('19:00');     // la noche es el complemento
            $table->unsignedInteger('speed_limit')->nullable();  // km/h fuera de geocercas; null = no contar
            $table->unsignedInteger('validity_minutes')->default(10);
            $table->unsignedInteger('stop_minutes')->default(1);
            $table->unsignedInteger('gap_minutes')->default(10);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        // Un registro por unidad, dia local y turno. Es la hoja "Datos" del Excel.
        Schema::create('security_daily_stats', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('device_id');
            $table->date('work_date');                 // dia local al que se atribuye el turno
            $table->string('shift', 5);                // day | night
            $table->dateTime('range_from');            // UTC
            $table->dateTime('range_to');              // UTC
            $table->decimal('distance_km', 10, 3)->default(0);
            $table->unsignedInteger('engine_seconds')->default(0);
            $table->unsignedInteger('moving_seconds')->default(0);
            $table->unsignedInteger('stop_count')->default(0);
            $table->decimal('speed_max', 6, 1)->nullable();      // km/h GPS
            $table->unsignedInteger('overspeed_events')->default(0); // de la tabla events
            $table->unsignedInteger('overspeed_calc')->default(0);   // tramos calculados
            $table->unsignedInteger('overspeed_total')->default(0);  // combinados sin duplicar
            $table->unsignedInteger('zone_exits')->default(0);
            $table->unsignedInteger('disconnect_events')->default(0);
            $table->unsignedInteger('signal_gaps')->default(0);
            $table->unsignedInteger('seatbelt_alerts')->nullable(); // null = sin sensor
            $table->unsignedInteger('positions')->default(0);      // "Registros"
            $table->dateTime('calculated_at');
            $table->unique(['device_id', 'work_date', 'shift']);
            $table->index(['user_id', 'work_date']);
            $table->foreign('device_id')->references('id')->on('devices')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('security_daily_stats');
        Schema::dropIfExists('security_settings');
    }
};
```

- [ ] **Step 3: Entidad de agregados**

```php
<?php

namespace Tobuli\Entities;

use Illuminate\Database\Eloquent\Model;

class SecurityDailyStat extends Model
{
    const SHIFT_DAY = 'day';
    const SHIFT_NIGHT = 'night';

    protected $table = 'security_daily_stats';
    protected $guarded = [];
    protected $casts = [
        'work_date'     => 'date',
        'range_from'    => 'datetime',
        'range_to'      => 'datetime',
        'calculated_at' => 'datetime',
        'distance_km'   => 'float',
        'speed_max'     => 'float',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    /** Estado diario del Excel: Movimiento / Stand by* / Sin actividad. */
    public function dailyState(): string
    {
        if ($this->positions <= 0) return 'no_data';
        if ($this->moving_seconds > 0) return 'moving';
        if ($this->engine_seconds > 0) return 'standby';

        return 'inactive';
    }

    public static function shifts(): array
    {
        return [self::SHIFT_DAY, self::SHIFT_NIGHT];
    }
}
```

- [ ] **Step 4: Entidad de ajustes**

```php
<?php

namespace Tobuli\Entities;

use Illuminate\Database\Eloquent\Model;

class SecuritySetting extends Model
{
    protected $table = 'security_settings';
    protected $guarded = [];
    protected $casts = ['speed_limit' => 'int', 'validity_minutes' => 'int', 'stop_minutes' => 'int', 'gap_minutes' => 'int'];

    public static function forUser(User $user): self
    {
        return static::firstOrCreate(['user_id' => $user->id]);
    }
}
```

- [ ] **Step 5: Migrar y verificar**

```bash
php artisan migrate
php artisan tinker --execute="echo Tobuli\Entities\SecuritySetting::forUser(Tobuli\Entities\User::find(864))->day_start, PHP_EOL;"
```
Esperado: `07:00` y las dos tablas en `SHOW TABLES`.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_10_15_000001_create_security_tables.php Tobuli/Entities/SecurityDailyStat.php Tobuli/Entities/SecuritySetting.php
git commit -m "feat(seguridad): tablas de agregados diarios y ajustes por usuario"
```

---

### Task 2: Franjas de turno (`SecurityShift`)

**Files:**
- Create: `Tobuli/Services/Security/SecurityShift.php`

- [ ] **Step 1: Implementar**

```php
<?php

namespace Tobuli\Services\Security;

use Carbon\Carbon;
use Tobuli\Entities\SecurityDailyStat;
use Tobuli\Entities\SecuritySetting;

/**
 * Convierte (dia local, turno) en un rango UTC. La noche empieza el dia
 * indicado y termina al dia siguiente (19:00 D -> 07:00 D+1).
 */
class SecurityShift
{
    private string $tz;
    private string $dayStart;
    private string $dayEnd;

    public function __construct(SecuritySetting $settings, string $timezone)
    {
        $this->tz = $timezone;
        $this->dayStart = $settings->day_start;
        $this->dayEnd = $settings->day_end;
    }

    /** @return array{0: Carbon, 1: Carbon} [desde, hasta) en UTC */
    public function range(string $workDate, string $shift): array
    {
        $day = Carbon::parse($workDate, $this->tz)->startOfDay();

        if ($shift === SecurityDailyStat::SHIFT_DAY) {
            $from = $day->copy()->setTimeFromTimeString($this->dayStart);
            $to = $day->copy()->setTimeFromTimeString($this->dayEnd);
        } else {
            $from = $day->copy()->setTimeFromTimeString($this->dayEnd);
            $to = $day->copy()->addDay()->setTimeFromTimeString($this->dayStart);
        }

        return [$from->utc(), $to->utc()];
    }

    /** Turno al que pertenece un instante UTC, y su dia de trabajo local. */
    public function shiftAt(Carbon $utc): array
    {
        $local = $utc->copy()->setTimezone($this->tz);
        $start = $local->copy()->setTimeFromTimeString($this->dayStart);
        $end = $local->copy()->setTimeFromTimeString($this->dayEnd);

        if ($local >= $start && $local < $end) {
            return [$local->toDateString(), SecurityDailyStat::SHIFT_DAY];
        }
        // Noche: antes del inicio del dia pertenece a la noche del dia anterior.
        $workDate = $local < $start ? $local->copy()->subDay()->toDateString() : $local->toDateString();

        return [$workDate, SecurityDailyStat::SHIFT_NIGHT];
    }
}
```

- [ ] **Step 2: Verificar en tinker**

```bash
php artisan tinker --execute="\$s = new Tobuli\Services\Security\SecurityShift(Tobuli\Entities\SecuritySetting::forUser(Tobuli\Entities\User::find(864)), 'America/Lima'); [\$a,\$b] = \$s->range('2026-10-09','night'); echo \$a, ' -> ', \$b, PHP_EOL; print_r(\$s->shiftAt(Carbon\Carbon::parse('2026-10-10 05:30:00','UTC')));"
```
Esperado: `2026-10-10 00:00:00 -> 2026-10-10 12:00:00` (19:00 y 07:00 de Lima en UTC) y `['2026-10-09', 'night']` (00:30 de Lima del día 10 es la noche del día 9).

- [ ] **Step 3: Commit**

```bash
git add Tobuli/Services/Security/SecurityShift.php
git commit -m "feat(seguridad): franjas de turno dia/noche en UTC"
```

---

### Task 3: Límite de velocidad «geocerca o manual» (acción de historial)

**Files:**
- Create: `Tobuli/History/Actions/AppendSpeedLimitGeofenceOrStatic.php`

- [ ] **Step 1: Implementar** (hereda de la de prioridad por área; fuera de geocerca usa el límite manual del config `speed_limit`, o `null` si no hay)

```php
<?php

namespace Tobuli\History\Actions;

/**
 * Dentro de una geocerca con limite manda la geocerca (menor area si se
 * solapan, por tipo y estado del vehiculo); fuera, el limite manual del
 * reporte (config 'speed_limit'). Sin limite manual, fuera no se evalua.
 */
class AppendSpeedLimitGeofenceOrStatic extends AppendSpeedLimitGeofenceByPriority
{
    protected $static;

    public function boot()
    {
        parent::boot();
        $this->static = $this->history->hasConfig('speed_limit') && $this->history->config('speed_limit') > 0
            ? (float) $this->history->config('speed_limit')
            : null;
    }

    public function proccess(&$position)
    {
        parent::proccess($position);

        if (empty($position->speed_limit)) {
            $position->speed_limit = $this->static;
        }
    }
}
```

- [ ] **Step 2: Verificar que `AppendOverspeeding` la acepta como proveedor del límite**

Abrir `Tobuli/History/Actions/AppendOverspeeding.php:11-29` (`required()` / `after()`): si `after()` lista `AppendSpeedLimitGeofenceByPriority`, añadir la nueva clase a esa lista para que corra antes. Verificar con:

```bash
php artisan tinker --execute="\$d = Tobuli\Entities\Device::find(1822); \$h = new Tobuli\History\DeviceHistory(\$d); \$h->setConfig(['speed_limit' => 20, 'stop_speed' => \$d->min_moving_speed]); \$h->setGeofences(Tobuli\Entities\Geofence::where('user_id', 864)->get()); \$h->registerActions([Tobuli\History\Actions\AppendSpeedLimitGeofenceOrStatic::class, Tobuli\History\Actions\GroupOverspeed::class, Tobuli\History\Actions\Overspeed::class]); \$h->setRange(Carbon\Carbon::parse('2026-10-09 13:00:00'), Carbon\Carbon::parse('2026-10-09 17:00:00')); \$r = \$h->get(); echo 'grupos overspeed: ', collect(\$r['groups']->all())->filter(fn(\$g) => strpos(\$g->getKey(), 'overspeed') === 0)->count(), PHP_EOL;"
```
Esperado: un número > 0 (CFX-784 superó 20 km/h ese día; la velocidad máxima del historial fue 32 km/h).

- [ ] **Step 3: Commit**

```bash
git add Tobuli/History/Actions/AppendSpeedLimitGeofenceOrStatic.php Tobuli/History/Actions/AppendOverspeeding.php
git commit -m "feat(seguridad): limite de velocidad por geocerca con respaldo manual"
```

---

### Task 4: Calculadora diaria (`SecurityDailyCalculator`)

**Files:**
- Create: `Tobuli/Services/Security/SecurityDailyCalculator.php`

- [ ] **Step 1: Implementar**

```php
<?php

namespace Tobuli\Services\Security;

use Carbon\Carbon;
use Tobuli\Entities\Device;
use Tobuli\Entities\Event;
use Tobuli\Entities\Geofence;
use Tobuli\Entities\SecurityDailyStat;
use Tobuli\Entities\SecuritySetting;
use Tobuli\Entities\User;
use Tobuli\History\Actions\AppendSpeedLimitGeofenceOrStatic;
use Tobuli\History\Actions\Distance;
use Tobuli\History\Actions\DriveStop;
use Tobuli\History\Actions\Duration;
use Tobuli\History\Actions\EngineHours;
use Tobuli\History\Actions\GroupGeofenceIn;
use Tobuli\History\Actions\GroupOverspeed;
use Tobuli\History\Actions\NoDataDuration;
use Tobuli\History\Actions\Overspeed;
use Tobuli\History\Actions\Speed;
use Tobuli\History\DeviceHistory;

/**
 * Calcula y guarda la fila (unidad, dia, turno) de security_daily_stats.
 * Una pasada del pipeline de historial por turno; eventos de la tabla
 * events; cinturon leyendo el sensor posicion a posicion.
 */
class SecurityDailyCalculator
{
    private User $user;
    private SecuritySetting $settings;
    private SecurityShift $shift;
    private $geofences; // Collection<Geofence> del usuario

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->settings = SecuritySetting::forUser($user);
        $this->shift = new SecurityShift($this->settings, $user->timezone ? $user->timezone->zone : config('app.timezone'));
        $this->geofences = Geofence::where('user_id', $user->id)->get();
    }

    public function calculate(Device $device, string $workDate, string $shift): SecurityDailyStat
    {
        [$from, $to] = $this->shift->range($workDate, $shift);

        $history = new DeviceHistory($device);
        $history->setConfig([
            'stop_speed'   => $device->min_moving_speed,
            'speed_limit'  => $this->settings->speed_limit,
            'gap_seconds'  => $this->settings->gap_minutes * 60,
            'stop_minutes' => $this->settings->stop_minutes,
        ]);
        $history->setGeofences($this->geofences);
        $history->registerActions([
            Duration::class, Distance::class, DriveStop::class, EngineHours::class, Speed::class,
            NoDataDuration::class, GroupGeofenceIn::class,
            AppendSpeedLimitGeofenceOrStatic::class, GroupOverspeed::class, Overspeed::class,
        ]);
        $history->setRange($from, $to);

        $data = $history->get();
        $root = $data['root'];
        $groups = collect($data['groups']->all());

        $overspeedGroups = $groups->filter(fn($g) => strpos($g->getKey(), 'overspeed') === 0);
        $zoneExits = $groups->filter(fn($g) => strpos($g->getKey(), 'geofence_in_') === 0 && $g->getEndPosition())->count();

        $events = Event::where('device_id', $device->id)
            ->whereBetween('time', [$from, $to])
            ->whereIn('type', [Event::TYPE_OVERSPEED, Event::TYPE_ZONE_OVERSPEED, Event::TYPE_POWER_CUT, Event::TYPE_OFFLINE_DURATION])
            ->get(['type', 'time']);

        $overspeedEvents = $events->whereIn('type', [Event::TYPE_OVERSPEED, Event::TYPE_ZONE_OVERSPEED]);
        $overspeedTotal = $overspeedGroups->count() + $overspeedEvents->filter(function ($e) use ($overspeedGroups) {
            // Un evento dentro de un tramo calculado ya esta contado.
            $t = Carbon::parse($e->time);
            return ! $overspeedGroups->contains(fn($g) => $t->between($g->getStartPosition()->time, $g->getEndPosition()->time));
        })->count();

        $positions = (int) $this->stat($root, 'positions', 0) ?: $this->countPositions($device, $from, $to);

        return SecurityDailyStat::updateOrCreate(
            ['device_id' => $device->id, 'work_date' => $workDate, 'shift' => $shift],
            [
                'user_id'           => $this->user->id,
                'range_from'        => $from,
                'range_to'          => $to,
                'distance_km'       => round(((float) $this->stat($root, 'distance', 0)) / 1000, 3),
                'engine_seconds'    => (int) $this->stat($root, 'engine_hours', 0),
                'moving_seconds'    => (int) $this->stat($root, 'drive_duration', 0),
                'stop_count'        => (int) $this->stat($root, 'stop_count', 0),
                'speed_max'         => $positions ? (float) $this->stat($root, 'speed_max', 0) : null,
                'overspeed_events'  => $overspeedEvents->count(),
                'overspeed_calc'    => $overspeedGroups->count(),
                'overspeed_total'   => $overspeedTotal,
                'zone_exits'        => $zoneExits,
                'disconnect_events' => $events->whereIn('type', [Event::TYPE_POWER_CUT, Event::TYPE_OFFLINE_DURATION])->count(),
                'signal_gaps'       => (int) $this->stat($root, 'no_data_count', 0),
                'seatbelt_alerts'   => $this->seatbeltAlerts($device, $from, $to),
                'positions'         => $positions,
                'calculated_at'     => now(),
            ]
        );
    }

    /** Recalcula todas las unidades del usuario entre dos dias locales (ambos incluidos). */
    public function calculateRange(string $fromDate, string $toDate, ?array $deviceIds = null): int
    {
        $devices = $this->user->devices()->clearOrdersBy()->when($deviceIds, fn($q) => $q->whereIn('devices.id', $deviceIds))->get();
        $n = 0;
        for ($d = Carbon::parse($fromDate); $d->lte(Carbon::parse($toDate)); $d->addDay()) {
            foreach ($devices as $device) {
                foreach (SecurityDailyStat::shifts() as $shift) {
                    try {
                        $this->calculate($device, $d->toDateString(), $shift);
                        $n++;
                    } catch (\PDOException $e) {
                        // Unidad sin tabla de posiciones: se omite.
                    }
                }
            }
        }

        return $n;
    }

    private function stat($group, string $key, $default)
    {
        if (! $group->hasStat($key)) return $default;
        $s = $group->getStat($key);

        return $s ? $s->get() : $default;
    }

    private function countPositions(Device $device, Carbon $from, Carbon $to): int
    {
        return (int) $device->positions()->whereBetween('time', [$from, $to])->count();
    }

    /**
     * Alertas de cinturon: tramos de >= 30 s con el sensor en 0 y velocidad
     * >= 1 km/h. null si la unidad no tiene sensor de tipo seatbelt.
     */
    private function seatbeltAlerts(Device $device, Carbon $from, Carbon $to): ?int
    {
        $sensor = $device->getSensorByType('seatbelt');
        if (! $sensor) return null;

        $alerts = 0; $openSince = null;
        $device->positions()->whereBetween('time', [$from, $to])->orderBy('time')
            ->chunk(2000, function ($rows) use ($sensor, &$alerts, &$openSince) {
                foreach ($rows as $p) {
                    $unbuckled = ! $sensor->getValueCurrent($p) && $p->speed >= 1;
                    if ($unbuckled && $openSince === null) {
                        $openSince = Carbon::parse($p->time);
                    } elseif (! $unbuckled && $openSince !== null) {
                        if (Carbon::parse($p->time)->diffInSeconds($openSince) >= 30) $alerts++;
                        $openSince = null;
                    }
                }
            });

        return $alerts;
    }
}
```

- [ ] **Step 2: Comprobar las claves de estadística que usa el código**

`no_data_count` no existe todavía: `Tobuli/History/Actions/NoDataDuration.php` registra solo `no_data_duration`. Añadirle un contador de huecos:

```php
// en boot(), junto a registerStat('no_data_duration', ...):
$this->registerStat('no_data_count', new \Tobuli\History\Stats\StatCount());
// donde detecta un hueco (gap > gap_seconds) y aplica no_data_duration:
$this->history->applyStat('no_data_count', 1);
```
(`StatCount` existe en `Tobuli/History/Stats/`; `StatCount::get()` devuelve el número de aplicaciones.) La clave `positions` tampoco existe: por eso el código cae a `countPositions()`; no añadir nada más.

- [ ] **Step 3: Verificar con la unidad de prueba**

```bash
php artisan tinker --execute="\$c = new Tobuli\Services\Security\SecurityDailyCalculator(Tobuli\Entities\User::find(864)); \$r = \$c->calculate(Tobuli\Entities\Device::find(1822), '2026-10-09', 'day'); print_r(\$r->only(['distance_km','engine_seconds','moving_seconds','stop_count','speed_max','overspeed_calc','overspeed_events','overspeed_total','zone_exits','signal_gaps','seatbelt_alerts','positions']));"
```
Esperado: `distance_km` > 0, `moving_seconds` > 0, `speed_max` ≈ 32, `seatbelt_alerts` = null (sin sensor), `overspeed_calc` = 0 hasta que el usuario fije un límite manual (las geocercas de prueba no tienen límite).

Repetir con `speed_limit = 20` guardado en `security_settings` del usuario 864 y confirmar que `overspeed_calc` > 0.

- [ ] **Step 4: Commit**

```bash
git add Tobuli/Services/Security/SecurityDailyCalculator.php Tobuli/History/Actions/NoDataDuration.php
git commit -m "feat(seguridad): calculo diario por unidad y turno desde el historial"
```

---

### Task 5: Comando `security:aggregate` y programación

**Files:**
- Create: `app/Console/Commands/SecurityAggregateCommand.php`
- Modify: `app/Console/Kernel.php` (`$commands` junto a `OperationsProcessCommand`, y `schedule()`)
- Modify: `config/permissions.php`, `config/tobuli.php`

- [ ] **Step 1: Permiso y bandera**

En `config/permissions.php`, debajo del bloque `'operations'`:

```php
        // Seguridad - control de flota (dashboard F&M). Igual que operations:
        // no se siembra; el admin lo marca por usuario.
        'security' => [
            'view' => 1,
            'edit' => 1,
        ],
```

En `config/tobuli.php`, debajo de `operations_enabled`:

```php
    'security_enabled' => env('SECURITY_ENABLED', false),
```

- [ ] **Step 2: Comando**

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Tobuli\Entities\SecuritySetting;
use Tobuli\Entities\User;
use Tobuli\Services\Security\SecurityDailyCalculator;

class SecurityAggregateCommand extends Command
{
    protected $signature = 'security:aggregate {--from=} {--to=} {--user=} {--device=*}';
    protected $description = 'Agregados diarios de Seguridad (km, motor, movimiento, excesos, cinturon) por unidad y turno';

    public function handle(): int
    {
        if (! config('tobuli.security_enabled')) {
            return 0;
        }

        // Sin rango: ayer y hoy (hoy queda parcial y se rehace manana).
        $from = $this->option('from') ?: now()->subDay()->toDateString();
        $to = $this->option('to') ?: now()->toDateString();

        $users = SecuritySetting::query()
            ->when($this->option('user'), fn($q, $u) => $q->where('user_id', $u))
            ->pluck('user_id');

        foreach ($users as $userId) {
            $user = User::find($userId);
            if (! $user || ! $user->perm('security', 'view')) continue;

            $n = (new SecurityDailyCalculator($user))->calculateRange($from, $to, $this->option('device') ?: null);
            $this->info("usuario {$userId}: {$n} filas");
        }

        return 0;
    }
}
```

Solo se agregan los usuarios con fila en `security_settings` (la crea la pantalla al abrirse por primera vez) y con el permiso: así no se recorre toda la base.

- [ ] **Step 3: Registrar en el Kernel**

En `$commands`, después de `'App\Console\Commands\OperationsProcessCommand',`:
```php
        'App\Console\Commands\SecurityAggregateCommand',
```
En `schedule()`, después del bloque de `operations:process`:
```php
        // Seguridad: agregados de ayer y hoy, cada hora a y 20 (hoy queda
        // parcial y se rehace en la siguiente pasada).
        $schedule->command('security:aggregate')->hourlyAt(20)->withoutOverlapping();
```

- [ ] **Step 4: Verificar**

```bash
php artisan security:aggregate --user=864 --from=2026-10-05 --to=2026-10-12
php artisan tinker --execute="echo Tobuli\Entities\SecurityDailyStat::where('user_id',864)->count(), PHP_EOL;"
```
Esperado: con `SECURITY_ENABLED=true` en `.env` (y `config:clear`), «usuario 864: 80 filas» (8 días × 5 unidades × 2 turnos) y el conteo 80.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/SecurityAggregateCommand.php app/Console/Kernel.php config/permissions.php config/tobuli.php
git commit -m "feat(seguridad): comando security:aggregate programado y permiso del modulo"
```

---

## Fase 2 · Pantalla

### Task 6: Estado en vivo y resumen (`SecurityLiveStatus`, `SecuritySummary`)

**Files:**
- Create: `Tobuli/Services/Security/SecurityLiveStatus.php`
- Create: `Tobuli/Services/Security/SecuritySummary.php`

- [ ] **Step 1: Estado vigente por unidad** (sección «ESTADO ACTUAL» del Excel)

```php
<?php

namespace Tobuli\Services\Security;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tobuli\Entities\Device;
use Tobuli\Entities\SecuritySetting;

class SecurityLiveStatus
{
    public function __construct(private SecuritySetting $settings) {}

    /** @param Collection<Device> $devices  @return array[] una fila por unidad */
    public function rows(Collection $devices): array
    {
        $rows = [];
        foreach ($devices as $device) {
            $time = $device->traccar && $device->traccar->time ? Carbon::parse($device->traccar->time) : null;
            $age = $time ? max(0, $time->diffInMinutes(now())) : null;
            $speed = $time ? (float) $device->traccar->speed : null;
            $engine = $device->getSensorByType('ignition') ? (bool) $device->engine_status : null; // accessor existente
            $rows[] = [
                'device_id'   => $device->id,
                'unit'        => $device->name,
                'type'        => $device->vehicleType ? $device->vehicleType->name : null,
                'last_time'   => $time,
                'speed'       => $speed,
                'engine'      => $engine,
                'age_minutes' => $age,
                'state'       => $this->state($age, $speed, $engine),
            ];
        }

        return $rows;
    }

    /** Misma regla del Excel: vigencia > minutos => expirada; >= 1 km/h movimiento; motor on => stand by. */
    private function state(?int $age, ?float $speed, ?bool $engine): string
    {
        if ($age === null || $age > $this->settings->validity_minutes) return 'expired';
        if ($speed >= 1) return 'moving';
        if ($engine === true) return 'standby';

        return 'stopped';
    }
}
```

Comprobar el nombre real del accessor de motor en `Tobuli/Entities/Device.php` (`grep -n "engine_status\|function getEngineStatus\|isEngineOn" Tobuli/Entities/Device.php`) y usar ese.

- [ ] **Step 2: Resumen** (tarjetas, resumen diario y detalle por unidad de las hojas Seguridad y Operaciones)

```php
<?php

namespace Tobuli\Services\Security;

use Illuminate\Support\Collection;
use Tobuli\Entities\SecurityDailyStat;

class SecuritySummary
{
    /** @param Collection<SecurityDailyStat> $stats ya filtradas por usuario, fechas, turno, tipo y unidad */
    public function cards(Collection $stats): array
    {
        return [
            'overspeed'   => (int) $stats->sum('overspeed_total'),
            'seatbelt'    => (int) $stats->whereNotNull('seatbelt_alerts')->sum('seatbelt_alerts'),
            'zone_exits'  => (int) $stats->sum('zone_exits'),
            'distance_km' => round($stats->sum('distance_km'), 1),
            'engine_h'    => round($stats->sum('engine_seconds') / 3600, 2),
            'moving_h'    => round($stats->sum('moving_seconds') / 3600, 2),
            'stops'       => (int) $stats->sum('stop_count'),
            'disconnects' => (int) ($stats->sum('disconnect_events') + $stats->sum('signal_gaps')),
        ];
    }

    /** Una fila por fecha: km, movimiento h, media, maxima, unidades con movimiento / stand by, excesos. */
    public function daily(Collection $stats): array
    {
        return $stats->groupBy(fn($s) => $s->work_date->toDateString())->map(function ($rows, $date) {
            $km = $rows->sum('distance_km'); $mh = $rows->sum('moving_seconds') / 3600;
            $byUnit = $rows->groupBy('device_id');
            return [
                'date'       => $date,
                'km'         => round($km, 1),
                'moving_h'   => round($mh, 2),
                'avg_kmh'    => $mh > 0 ? round($km / $mh, 1) : null,
                'max_kmh'    => $rows->max('speed_max'),
                'moving'     => $byUnit->filter(fn($u) => $u->sum('moving_seconds') > 0)->count(),
                'standby'    => $byUnit->filter(fn($u) => $u->sum('moving_seconds') == 0 && $u->sum('engine_seconds') > 0)->count(),
                'overspeed'  => (int) $rows->sum('overspeed_total'),
            ];
        })->values()->all();
    }

    /** Detalle diario por unidad (hoja Seguridad, bloque 16 fechas x unidades) y por unidad (hoja Operaciones). */
    public function perUnitDaily(Collection $stats): array
    {
        return $stats->groupBy(fn($s) => $s->work_date->toDateString() . '|' . $s->device_id)->map(function ($rows) {
            $first = $rows->first(); $km = $rows->sum('distance_km'); $mh = $rows->sum('moving_seconds') / 3600; $eh = $rows->sum('engine_seconds') / 3600;
            return [
                'date' => $first->work_date->toDateString(), 'device_id' => $first->device_id, 'unit' => $first->device->name,
                'type' => optional($first->device->vehicleType)->name,
                'km' => round($km, 1), 'moving_h' => round($mh, 2), 'engine_h' => round($eh, 2),
                'avg_kmh' => $mh > 0 ? round($km / $mh, 1) : null, 'max_kmh' => $rows->max('speed_max'),
                'state' => $rows->sum('positions') == 0 ? 'no_data' : ($mh > 0 ? 'moving' : ($eh > 0 ? 'standby' : 'inactive')),
                'records' => (int) $rows->sum('positions'), 'stops' => (int) $rows->sum('stop_count'),
                'overspeed' => (int) $rows->sum('overspeed_total'),
                'seatbelt' => $rows->whereNotNull('seatbelt_alerts')->isEmpty() ? null : (int) $rows->sum('seatbelt_alerts'),
            ];
        })->values()->all();
    }

    public function perUnit(Collection $stats): array
    {
        return $stats->groupBy('device_id')->map(function ($rows) {
            $first = $rows->first();
            return [
                'device_id' => $first->device_id, 'unit' => $first->device->name, 'type' => optional($first->device->vehicleType)->name,
                'km' => round($rows->sum('distance_km'), 1), 'engine_h' => round($rows->sum('engine_seconds') / 3600, 2),
                'moving_h' => round($rows->sum('moving_seconds') / 3600, 2), 'stops' => (int) $rows->sum('stop_count'),
                'records' => (int) $rows->sum('positions'), 'overspeed' => (int) $rows->sum('overspeed_total'),
                'avg_kmh' => $rows->sum('moving_seconds') > 0 ? round($rows->sum('distance_km') / ($rows->sum('moving_seconds') / 3600), 1) : null,
            ];
        })->values()->all();
    }
}
```

- [ ] **Step 3: Verificar**

```bash
php artisan tinker --execute="\$s = Tobuli\Entities\SecurityDailyStat::with('device.vehicleType')->where('user_id',864)->get(); \$r = new Tobuli\Services\Security\SecuritySummary; print_r(\$r->cards(\$s)); print_r(array_slice(\$r->daily(\$s), 0, 2)); print_r((new Tobuli\Services\Security\SecurityLiveStatus(Tobuli\Entities\SecuritySetting::forUser(Tobuli\Entities\User::find(864))))->rows(Tobuli\Entities\User::find(864)->devices()->clearOrdersBy()->get())[0]);"
```
Esperado: tarjetas con km > 0; dos días con `avg_kmh`; la fila en vivo con `state` = `expired` (las unidades de dev no transmiten) y `age_minutes` grande.

- [ ] **Step 4: Commit**

```bash
git add Tobuli/Services/Security/SecurityLiveStatus.php Tobuli/Services/Security/SecuritySummary.php
git commit -m "feat(seguridad): estado vigente por unidad y resumenes para la pantalla"
```

---

### Task 7: Controlador, rutas y menú

**Files:**
- Create: `app/Http/Controllers/Ui/V2/SecurityController.php`
- Modify: `routes/web.php` (junto a `operations`, línea ~1182 para la página y ~1211 para la API)
- Modify: `resources/views/ui/v2/partials/rail.blade.php:39`

- [ ] **Step 1: Controlador**

```php
<?php

namespace App\Http\Controllers\Ui\V2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Tobuli\Entities\SecurityDailyStat;
use Tobuli\Entities\SecuritySetting;
use Tobuli\Entities\VehicleType;
use Tobuli\Exports\Security\SecurityWorkbookExport;
use Tobuli\Services\Security\SecurityDailyCalculator;
use Tobuli\Services\Security\SecurityLiveStatus;
use Tobuli\Services\Security\SecuritySummary;

class SecurityController extends BaseController
{
    public function page()
    {
        $this->checkException('security', 'view');
        SecuritySetting::forUser($this->user); // alta implicita: el comando nocturno solo agrega usuarios con ajustes

        return View::make('ui.v2.pages.security');
    }

    /** Filtros disponibles y ajustes del usuario. */
    public function meta(): JsonResponse
    {
        $this->checkException('security', 'view');
        $settings = SecuritySetting::forUser($this->user);

        return response()->json([
            'ok'       => true,
            'can_edit' => $this->user->perm('security', 'edit'),
            'settings' => $settings->only(['day_start', 'day_end', 'speed_limit', 'validity_minutes', 'stop_minutes', 'gap_minutes']),
            'types'    => VehicleType::where('user_id', $this->user->id)->orderBy('name')->get(['id', 'name']),
            'devices'  => $this->user->devices()->clearOrdersBy()->orderBy('devices.name')->get(['devices.id', 'devices.name', 'devices.vehicle_type_id']),
            'last_calculated' => optional(SecurityDailyStat::where('user_id', $this->user->id)->max('calculated_at')),
        ]);
    }

    /** Tarjetas, resumen diario, detalle por unidad y estado en vivo, con los filtros del Excel. */
    public function data(Request $request): JsonResponse
    {
        $this->checkException('security', 'view');
        $stats = $this->filtered($request)->with('device.vehicleType')->get();
        $summary = new SecuritySummary();
        $devices = $this->filteredDevices($request);

        return response()->json([
            'ok'       => true,
            'cards'    => $summary->cards($stats),
            'daily'    => $summary->daily($stats),
            'per_unit_daily' => $summary->perUnitDaily($stats),
            'per_unit' => $summary->perUnit($stats),
            'live'     => (new SecurityLiveStatus(SecuritySetting::forUser($this->user)))->rows($devices),
        ]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $this->checkException('security', 'edit');
        $data = $request->validate([
            'day_start' => 'required|date_format:H:i', 'day_end' => 'required|date_format:H:i',
            'speed_limit' => 'nullable|integer|min:1|max:300', 'validity_minutes' => 'required|integer|min:1|max:1440',
            'stop_minutes' => 'required|integer|min:1|max:120', 'gap_minutes' => 'required|integer|min:1|max:240',
        ]);
        SecuritySetting::forUser($this->user)->update($data);

        return response()->json(['ok' => true]);
    }

    /** Recalculo sincrono de un rango corto (<= 31 dias); el nocturno cubre el resto. */
    public function recalculate(Request $request): JsonResponse
    {
        $this->checkException('security', 'edit');
        $data = $request->validate(['from' => 'required|date', 'to' => 'required|date|after_or_equal:from']);
        if (\Carbon\Carbon::parse($data['from'])->diffInDays($data['to']) > 31) {
            return response()->json(['ok' => false, 'errors' => ['to' => [trans('ui.security_range_too_long')]]], 422);
        }
        $n = (new SecurityDailyCalculator($this->user))->calculateRange($data['from'], $data['to'], $request->get('devices'));

        return response()->json(['ok' => true, 'rows' => $n]);
    }

    public function export(Request $request)
    {
        $this->checkException('security', 'view');
        $stats = $this->filtered($request)->with('device.vehicleType')->get();

        return (new SecurityWorkbookExport($this->user, $stats, $this->filteredDevices($request), $request->all()))
            ->download('Dashboard_Seguridad_' . now()->format('Ymd_Hi') . '.xlsx');
    }

    private function filtered(Request $request)
    {
        return SecurityDailyStat::where('user_id', $this->user->id)
            ->whereBetween('work_date', [$request->get('from', now()->subDays(15)->toDateString()), $request->get('to', now()->toDateString())])
            ->when($request->get('shift') && $request->get('shift') !== 'all', fn($q, $s) => $q->where('shift', $request->get('shift')))
            ->when($request->get('device_id'), fn($q, $id) => $q->where('device_id', $id))
            ->when($request->get('type_id'), fn($q, $t) => $q->whereIn('device_id', $this->user->devices()->clearOrdersBy()->where('vehicle_type_id', $t)->pluck('devices.id')));
    }

    private function filteredDevices(Request $request)
    {
        return $this->user->devices()->clearOrdersBy()->with(['traccar', 'vehicleType'])
            ->when($request->get('device_id'), fn($q, $id) => $q->where('devices.id', $id))
            ->when($request->get('type_id'), fn($q, $t) => $q->where('vehicle_type_id', $t))
            ->orderBy('devices.name')->get();
    }
}
```

- [ ] **Step 2: Rutas** (mismo grupo que Operaciones)

Página, junto a la línea `Route::get('operations', ...)`:
```php
    Route::get('security', ['as' => 'security', 'uses' => 'Ui\V2\SecurityController@page']);
```
API, dentro del grupo `ui/v2/api` junto a `api.operations`:
```php
        Route::get('security/meta',         ['as' => 'api.security.meta',        'uses' => 'Ui\V2\SecurityController@meta']);
        Route::get('security/data',         ['as' => 'api.security.data',        'uses' => 'Ui\V2\SecurityController@data']);
        Route::post('security/settings',    ['as' => 'api.security.settings',    'uses' => 'Ui\V2\SecurityController@saveSettings']);
        Route::post('security/recalculate', ['as' => 'api.security.recalculate', 'uses' => 'Ui\V2\SecurityController@recalculate']);
        Route::get('security/export',       ['as' => 'api.security.export',      'uses' => 'Ui\V2\SecurityController@export']);
```

- [ ] **Step 3: Rail**, después de la entrada `operations`:
```php
        ['key' => 'security', 'label' => trans('ui.security'), 'icon' => 'shield', 'url' => url('/ui/v2/security'), 'can' => $u->perm('security', 'view')],
```
Si el set de iconos del rail no tiene `shield`, usar `alert` (ver el mapa de iconos al inicio de `rail.blade.php`).

- [ ] **Step 4: Verificar la API** (con el permiso `security` marcado al usuario 864 desde el admin, y sesión iniciada en el navegador)

```
GET http://talentus-pro-tracking.test/ui/v2/api/security/meta
GET http://talentus-pro-tracking.test/ui/v2/api/security/data?from=2026-10-05&to=2026-10-12&shift=all
```
Esperado: `ok: true`, `settings.day_start = "07:00"`, `cards.distance_km` > 0, `live` con 5 filas.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Ui/V2/SecurityController.php routes/web.php resources/views/ui/v2/partials/rail.blade.php
git commit -m "feat(seguridad): controlador, API y entrada de menu"
```

---

### Task 8: Página, JS y CSS

**Files:**
- Create: `resources/views/ui/v2/pages/security.blade.php`
- Create: `resources/assets/ui-v2/js/tp-security.js`
- Create: `resources/assets/ui-v2/css/security.css`
- Modify: `resources/lang/es/ui.php`, `resources/lang/en/ui.php`, `gulpfile.js` solo si los JS de la v2 no se recogen por comodín (comprobar `grep -n "ui-v2/js" gulpfile.js`).

- [ ] **Step 1: Claves i18n** (bloque nuevo en `ui.php`, antes de `// Operaciones (control de vueltas)`; mismas claves en inglés)

```php
    // Seguridad - control de flota
    'security'                            => 'Seguridad',
    'security_title'                      => 'Seguridad · Control de flota',
    'security_fleet'                      => 'Flota',
    'security_shift'                      => 'Turno',
    'security_shift_both'                 => 'Ambos',
    'security_shift_day'                  => 'Día',
    'security_shift_night'                => 'Noche',
    'security_validity'                   => 'Vigencia (min)',
    'security_speed_limit_hint'           => 'Fuera de geocercas. Dentro manda el límite de la geocerca.',
    'security_overspeed_events'           => 'Eventos de sobrevelocidad',
    'security_seatbelt_alerts'            => 'Alertas de cinturón',
    'security_zone_exits'                 => 'Salidas de geocerca',
    'security_units_valid'                => 'Unidades con lectura vigente',
    'security_units_moving'               => 'Unidades en movimiento',
    'security_units_standby'              => 'Unidades stand by',
    'security_units_expired'              => 'Sin lectura vigente',
    'security_last_reading'               => 'Última lectura de la flota',
    'security_live_title'                 => 'Estado actual · última lectura vigente por unidad',
    'security_daily_title'                => 'Resumen diario · flota filtrada',
    'security_per_unit_title'             => 'Detalle diario por unidad',
    'security_ops_title'                  => 'Operaciones · resultado por unidad',
    'security_state_moving'               => 'En movimiento',
    'security_state_standby'              => 'Stand by',
    'security_state_stopped'              => 'Detenido / motor apagado',
    'security_state_expired'              => 'Sin lectura vigente',
    'security_daily_moving'               => 'Movimiento',
    'security_daily_standby'              => 'Stand by*',
    'security_daily_inactive'             => 'Sin actividad',
    'security_daily_no_data'              => 'Sin registro',
    'security_standby_note'               => '*Stand by diario: hubo horas de motor pero 0 horas de movimiento en el período. No representa el estado actual.',
    'security_chart_states'               => 'Unidades por estado diario',
    'security_chart_avg_speed'            => 'Velocidad media por unidad · período filtrado',
    'security_chart_overspeed'            => 'Eventos de sobrevelocidad por día',
    'security_chart_live'                 => 'Estado de unidades · lectura vigente',
    'security_export_excel'               => 'Exportar a Excel',
    'security_settings'                   => 'Ajustes',
    'security_recalculate'                => 'Recalcular',
    'security_range_too_long'             => 'El recálculo en pantalla admite hasta 31 días; el resto lo hace el proceso nocturno.',
    'security_no_sensor'                  => 'Sin sensor',
    'security_last_calculated'            => 'Datos calculados hasta',
```

- [ ] **Step 2: Blade** (misma estructura que `operations.blade.php`: `@extends('ui.v2.layout')`, `@section('rail-active', 'security')`, `@push('head')` con `security.css`, ECharts desde el mismo CDN que `dashboard.blade.php:276`, y el JS al final con `@push('scripts')`). Marcado mínimo con ids que el JS usa:

```blade
@extends('ui.v2.layout')
@section('title', trans('ui.security_title'))
@section('rail-active', 'security')
@push('head')
    <link rel="stylesheet" href="{{ asset_resource('assets/ui/v2/css/security.css') }}">
@endpush
@section('workspace-class', 'tp-workspace--full')
@section('workspace')
<div class="tp-sec" id="tp-security"
     data-meta-url="{{ route('ui.v2.api.security.meta') }}" data-data-url="{{ route('ui.v2.api.security.data') }}"
     data-settings-url="{{ route('ui.v2.api.security.settings') }}" data-recalc-url="{{ route('ui.v2.api.security.recalculate') }}"
     data-export-url="{{ route('ui.v2.api.security.export') }}">
    <header class="tp-sec__head">
        <h1>{{ trans('ui.security_title') }}</h1>
        <div class="tp-sec__actions">
            <button type="button" class="tp-btn tp-btn--ghost" id="tp-sec-settings">{{ trans('ui.security_settings') }}</button>
            <button type="button" class="tp-btn tp-btn--ghost" id="tp-sec-recalc">{{ trans('ui.security_recalculate') }}</button>
            <button type="button" class="tp-btn tp-btn--accent" id="tp-sec-export">{{ trans('ui.security_export_excel') }}</button>
        </div>
    </header>
    <section class="tp-sec__filters">
        <label>{{ trans('ui.security_fleet') }}<select class="tp-input" id="tp-sec-type"><option value="">{{ trans('front.all') }}</option></select></label>
        <label>{{ trans('ui.security_shift') }}<select class="tp-input" id="tp-sec-shift"><option value="all">{{ trans('ui.security_shift_both') }}</option><option value="day">{{ trans('ui.security_shift_day') }}</option><option value="night">{{ trans('ui.security_shift_night') }}</option></select></label>
        <label>{{ trans('front.from') }}<input type="date" class="tp-input" id="tp-sec-from"></label>
        <label>{{ trans('front.to') }}<input type="date" class="tp-input" id="tp-sec-to"></label>
        <label>{{ trans('front.device') }}<select class="tp-input" id="tp-sec-device"><option value="">{{ trans('front.all') }}</option></select></label>
        <label>{{ trans('ui.security_validity') }}<input type="number" class="tp-input" id="tp-sec-validity" min="1" max="1440"></label>
        <label>{{ trans('ui.speed_limit') }}<input type="number" class="tp-input" id="tp-sec-speed-limit" min="1" max="300" placeholder="—"><small>{{ trans('ui.security_speed_limit_hint') }}</small></label>
        <button type="button" class="tp-btn tp-btn--accent" id="tp-sec-run">{{ trans('front.search') }}</button>
        <span class="tp-sec__calc" id="tp-sec-last-calc"></span>
    </section>
    <section class="tp-sec__cards" id="tp-sec-cards"></section>
    <section class="tp-sec__grid">
        <div class="tp-sec__panel"><h2>{{ trans('ui.security_live_title') }}</h2><table class="tp-table" id="tp-sec-live"></table></div>
        <div class="tp-sec__panel"><h2>{{ trans('ui.security_chart_live') }}</h2><div class="tp-sec__chart" id="tp-sec-chart-live"></div></div>
        <div class="tp-sec__panel tp-sec__panel--wide"><h2>{{ trans('ui.security_daily_title') }}</h2><table class="tp-table" id="tp-sec-daily"></table><p class="tp-text-muted">{{ trans('ui.security_standby_note') }}</p></div>
        <div class="tp-sec__panel"><h2>{{ trans('ui.security_chart_states') }}</h2><div class="tp-sec__chart" id="tp-sec-chart-states"></div></div>
        <div class="tp-sec__panel"><h2>{{ trans('ui.security_chart_avg_speed') }}</h2><div class="tp-sec__chart" id="tp-sec-chart-avg"></div></div>
        <div class="tp-sec__panel"><h2>{{ trans('ui.security_chart_overspeed') }}</h2><div class="tp-sec__chart" id="tp-sec-chart-overspeed"></div></div>
        <div class="tp-sec__panel tp-sec__panel--wide"><h2>{{ trans('ui.security_ops_title') }}</h2><table class="tp-table" id="tp-sec-units"></table></div>
        <div class="tp-sec__panel tp-sec__panel--wide"><h2>{{ trans('ui.security_per_unit_title') }}</h2><table class="tp-table" id="tp-sec-unit-daily"></table></div>
    </section>
</div>
@endsection
@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.5.1/dist/echarts.min.js"></script>
    <script src="{{ asset_resource('assets/ui/v2/js/tp-security.js') }}"></script>
@endpush
```

- [ ] **Step 3: JS** (`tp-security.js`, IIFE como `tp-operations.js`; fechas con `tpDate.value(el)` / `el._tpdp.set(iso)`; peticiones con `tpModal.fetchJSON`; errores con `tpModal.showFieldErrors` / `tpModal.firstError`). Responsabilidades, cada una en una función:

```js
(function () {
  var root = document.getElementById("tp-security"); if (!root) return;
  var URLS = { meta: root.dataset.metaUrl, data: root.dataset.dataUrl, settings: root.dataset.settingsUrl, recalc: root.dataset.recalcUrl, export: root.dataset.exportUrl };
  var $ = function (id) { return document.getElementById(id); };
  var charts = {};
  var meta = null;

  function chart(id) { var el = $(id); if (!el || typeof echarts === "undefined") return null; if (!charts[id]) charts[id] = echarts.init(el, null, { renderer: "svg" }); return charts[id]; }

  function filters() {
    return { from: tpDate.value($("tp-sec-from")), to: tpDate.value($("tp-sec-to")), shift: $("tp-sec-shift").value, type_id: $("tp-sec-type").value, device_id: $("tp-sec-device").value };
  }
  function qs(o) { return Object.keys(o).filter(function (k) { return o[k] !== "" && o[k] != null; }).map(function (k) { return k + "=" + encodeURIComponent(o[k]); }).join("&"); }

  function loadMeta() {
    return tpModal.fetchJSON(URLS.meta).then(function (r) {
      meta = r.data;
      fill($("tp-sec-type"), meta.types.map(function (t) { return [t.id, t.name]; }));
      fill($("tp-sec-device"), meta.devices.map(function (d) { return [d.id, d.name]; }));
      $("tp-sec-validity").value = meta.settings.validity_minutes;
      $("tp-sec-speed-limit").value = meta.settings.speed_limit || "";
      $("tp-sec-last-calc").textContent = meta.last_calculated ? tpT("ui.security_last_calculated") + " " + meta.last_calculated : "";
      var to = new Date(), from = new Date(); from.setDate(to.getDate() - 15);
      $("tp-sec-from")._tpdp.set(from.toISOString().slice(0, 10)); $("tp-sec-to")._tpdp.set(to.toISOString().slice(0, 10));
    });
  }
  function fill(sel, pairs) { pairs.forEach(function (p) { var o = document.createElement("option"); o.value = p[0]; o.textContent = p[1]; sel.appendChild(o); }); }

  function load() {
    return tpModal.fetchJSON(URLS.data + "?" + qs(filters())).then(function (r) {
      if (!r.ok) return tpModal.errorToast(r, tpT("ui.load_failed"));
      var d = r.data;
      renderCards(d.cards, d.live); renderLive(d.live); renderDaily(d.daily); renderUnits(d.per_unit); renderUnitDaily(d.per_unit_daily);
      chartLive(d.live); chartStates(d.per_unit_daily); chartAvg(d.per_unit); chartOverspeed(d.daily);
    });
  }

  // renderCards: 8 tarjetas (excesos, cinturon, salidas, vigentes, en movimiento, stand by, sin lectura, ultima lectura).
  // renderLive: tabla unidad | tipo | ultima lectura | vel. GPS | motor | edad min | estado (con clase por estado).
  // renderDaily / renderUnits / renderUnitDaily: tablas con las mismas columnas del Excel.
  // chartLive: barras por estado vigente. chartStates: lineas por fecha (movimiento / stand by* / sin actividad).
  // chartAvg: barras velocidad media por unidad. chartOverspeed: linea excesos por dia.
  // (Cada funcion construye el HTML con esc() y setOption de echarts; ver dashboard.js como referencia de estilo.)

  function wire() {
    $("tp-sec-run").addEventListener("click", load);
    $("tp-sec-export").addEventListener("click", function () { window.location.href = URLS.export + "?" + qs(filters()); });
    $("tp-sec-validity").addEventListener("change", saveSettings);
    $("tp-sec-speed-limit").addEventListener("change", saveSettings);
    $("tp-sec-settings").addEventListener("click", openSettings);   // modal: day_start, day_end, stop_minutes, gap_minutes
    $("tp-sec-recalc").addEventListener("click", openRecalc);       // modal: from, to (<= 31 dias) -> POST recalc -> load()
    window.addEventListener("resize", function () { Object.keys(charts).forEach(function (k) { charts[k].resize(); }); });
  }
  function saveSettings() {
    var body = Object.assign({}, meta.settings, { validity_minutes: $("tp-sec-validity").value, speed_limit: $("tp-sec-speed-limit").value || null });
    return tpModal.fetchJSON(URLS.settings, { method: "POST", json: body }).then(function (r) {
      if (!r.ok) return tpModal.errorToast(r, tpT("ui.save_failed"));
      meta.settings = body; tpModal.toast(tpT("ui.saved"), "success");
      // El limite manual cambia los excesos calculados: avisar que hay que recalcular.
      if (String(body.speed_limit || "") !== String(meta.settings.speed_limit || "")) tpModal.toast(tpT("ui.security_recalculate"), "warning");
    });
  }

  loadMeta().then(load).then(wire);
})();
```

Escribir completas `renderCards`, `renderLive`, `renderDaily`, `renderUnits`, `renderUnitDaily`, `chartLive`, `chartStates`, `chartAvg`, `chartOverspeed`, `openSettings`, `openRecalc` (el esqueleto de arriba marca qué pinta cada una). Los modales se abren con `tpModal.open({ title, body, buttons })` como en `tp-operations.js` (buscar `tpModal.open(` para el contrato exacto).

- [ ] **Step 4: CSS** (`security.css`): rejilla de dos columnas (`.tp-sec__grid`, `--wide` a ancho completo), tarjetas `.tp-sec__card` con valor grande y etiqueta, alturas de gráfico 280 px, colores de estado reutilizando los tokens de `tokens.css` (`--status-moving`, `--status-idle`, `--status-stopped`, `--status-offline`; comprobar nombres con `grep -n "status-" resources/assets/ui-v2/css/tokens.css`).

- [ ] **Step 5: Compilar y verificar en el navegador**

```bash
npx gulp ui-v2-js ui-v2-css && php artisan view:clear
```
Abrir `http://talentus-pro-tracking.test/ui/v2/security` con el usuario 864: filtros con tipos y unidades, 8 tarjetas con datos, tabla de estado actual con 5 unidades «Sin lectura vigente», resumen diario con 8 fechas, cuatro gráficos pintados, cambiar el turno a «Noche» y que cambien las cifras, cambiar «Vigencia» y que se guarde (recargar y persiste).

- [ ] **Step 6: Commit**

```bash
git add resources/views/ui/v2/pages/security.blade.php resources/assets/ui-v2/js/tp-security.js resources/assets/ui-v2/css/security.css public/assets/ui/v2 resources/lang/es/ui.php resources/lang/en/ui.php
git commit -m "feat(seguridad): pantalla con filtros, tarjetas, tablas y graficos"
```

---

## Fase 3 · Exportar a Excel

### Task 9: Libro Excel con las cuatro hojas y gráficos

**Files:**
- Create: `Tobuli/Exports/Security/SecurityWorkbookExport.php` (+ una clase por hoja en el mismo archivo o en `Tobuli/Exports/Security/Sheets/`)

- [ ] **Step 1: Estructura con `maatwebsite/excel`** (`WithMultipleSheets`; cada hoja `FromArray` + `WithTitle` + `WithHeadings`; la hoja Seguridad además `WithCharts` para los cuatro gráficos y `WithEvents` para formatos)

```php
<?php

namespace Tobuli\Exports\Security;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Tobuli\Entities\User;
use Tobuli\Services\Security\SecurityLiveStatus;
use Tobuli\Services\Security\SecuritySummary;
use Tobuli\Entities\SecuritySetting;

class SecurityWorkbookExport implements WithMultipleSheets
{
    use Exportable;

    public function __construct(private User $user, private $stats, private $devices, private array $filters) {}

    public function sheets(): array
    {
        $summary = new SecuritySummary();
        $live = (new SecurityLiveStatus(SecuritySetting::forUser($this->user)))->rows($this->devices);

        return [
            new SecuritySheet($this->filters, $summary->cards($this->stats), $live, $summary->daily($this->stats), $summary->perUnitDaily($this->stats)),
            new OperationsSheet($this->filters, $summary->perUnit($this->stats)),
            new DataSheet($this->stats),          // hoja "Datos": una fila por unidad, fecha y turno, columnas A..L y Q del Excel
            new TelemetrySheet($this->devices),   // hoja "Telemetria": ultima lectura por unidad (hora, unidad, tipo, turno, velocidad, motor)
        ];
    }
}
```

Hoja «Datos», columnas en el mismo orden que el Excel del cliente (`A:Fecha B:Unidad C:Tipo D:Turno E:Distancia km F:Motor h G:Movimiento h H:Eventos sobrevelocidad I:Alertas cinturón J:Salidas geocerca K:Paradas L:Desconexiones` y `Q:Velocidad máxima GPS`), valores (no fórmulas). Hoja «Seguridad»: fila de filtros aplicados, las ocho tarjetas, tabla de estado actual, resumen diario y detalle por unidad.

- [ ] **Step 2: Gráficos nativos** con PhpSpreadsheet en `SecuritySheet::charts()` (`Maatwebsite\Excel\Concerns\WithCharts`): `PhpOffice\PhpSpreadsheet\Chart\Chart` con `DataSeries` de tipo `TYPE_LINECHART` (estados por día, excesos por día) y `TYPE_BARCHART` (velocidad media por unidad, estado vigente), referenciando los rangos de la propia hoja (por ejemplo `'Seguridad'!$A$33:$A$48` para fechas). Ver `vendor/phpoffice/phpspreadsheet/samples/Chart/32_Chart_create_line.php` para el contrato exacto.

- [ ] **Step 3: Verificar**

Desde la pantalla, «Exportar a Excel» con 15 días: descarga un `.xlsx`; abrirlo en Excel: cuatro hojas, tarjetas con los mismos números que la pantalla, gráficos visibles, hoja Datos con `N` filas = días × unidades × turnos. Verificación automática del conteo:

```bash
python -c "import openpyxl,sys; wb=openpyxl.load_workbook(sys.argv[1]); print(wb.sheetnames, wb['Datos'].max_row-1, len(wb['Seguridad']._charts))" "C:/Users/jamne/Downloads/Dashboard_Seguridad_XXXXXXXX_XXXX.xlsx"
```
Esperado: `['Seguridad', 'Operaciones', 'Datos', 'Telemetría'] 80 4`.

- [ ] **Step 4: Commit**

```bash
git add Tobuli/Exports/Security
git commit -m "feat(seguridad): exportacion a Excel con cuatro hojas y graficos nativos"
```

---

## Cierre

### Task 10: Documentación, despliegue y merge

**Files:**
- Modify: `docs/manual-usuario/manual-usuario-plataforma.html` (capítulo «Seguridad · Control de flota» antes del de Mantenimiento, con 3 capturas: filtros y tarjetas, gráficos, Excel exportado)
- Modify: `docs/guia-actualizacion-operaciones.md` → añadir sección «Seguridad»: migración, `SECURITY_ENABLED=true`, `security:aggregate` en el scheduler, permiso `security` por usuario, recálculo inicial `php artisan security:aggregate --from=<fecha> --to=<hoy>`.
- Memoria: actualizar `project_operations_module.md` o crear `project_security_module.md` con decisiones (fuentes de sobrevelocidad, cinturón solo con sensor, turno fijo por usuario).

- [ ] **Step 1: Capturas y capítulo del manual** (mismo flujo que los manuales anteriores: Playwright a 1440×900, tema claro, JPEG, Edge sin interfaz para el PDF).
- [ ] **Step 2: Guía de actualización** con los pasos anteriores.
- [ ] **Step 3: Merge**

```bash
git checkout nuevo-tema && git merge --no-ff seguridad -m "Merge branch 'seguridad' into nuevo-tema" && git push origin nuevo-tema seguridad
```

- [ ] **Step 4: En el servidor de pruebas**: `git pull`, `php artisan migrate --force`, `SECURITY_ENABLED=true` + `php artisan config:cache`, marcar el permiso al usuario de prueba, `php artisan security:aggregate --user=864 --from=2026-10-05 --to=$(date +%F)`, abrir la pantalla y exportar.

---

## Riesgos y cómo se cubren

| Riesgo | Mitigación en el plan |
| --- | --- |
| Recorrer posiciones de muchas unidades es lento | Agregados nocturnos por hora; el recálculo en pantalla se limita a 31 días; la página solo lee `security_daily_stats` |
| El límite manual cambia y los agregados quedan viejos | Al guardar un límite distinto se avisa «Recalcular»; `overspeed_events` y `overspeed_calc` se guardan por separado para auditar |
| Unidad sin sensor de encendido | `engine_seconds` = 0 → nunca «Stand by», sale «Sin actividad» o «Movimiento»; se documenta en el manual |
| Cinturón sin señal cableada | `seatbelt_alerts = null` y la celda vacía; no se inventa un cero |
| MariaDB 10.5 en producción | Solo `dateTime`, `decimal`, índices normales; sin JSON ni espacial |
| Permiso no sembrado | Igual que Operaciones: el admin lo marca; `PermissionService::addToAll('security')` si se quiere abrir a todos |
