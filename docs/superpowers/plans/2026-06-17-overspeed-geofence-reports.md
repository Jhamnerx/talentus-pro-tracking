# Reportes 904/905/906: reutilización y prioridad de geocercas — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Hacer el reporte 904 reutilizable (filtro "solo excesos" + un evento por geocerca excedida) y corregir la prioridad de geocercas superpuestas en 904/905/906 usando el área más pequeña, sin afectar el motor compartido que usan otros clientes (`RagGeofencesReport`).

**Architecture:** Se agregan dos métodos puros y testeables a `Geofence` (`approxAreaSqMeters()`, `pickBySpeedLimitPriority()`) que sirven como única fuente de verdad para el cálculo de área y el desempate por prioridad. La 904 (loop manual sobre posiciones) y una nueva acción de Historial (`AppendSpeedLimitGeofenceByPriority`, usada solo por 905/906) consumen esos métodos. El motor compartido `AppendSpeedLimitGeofence` no se toca.

**Tech Stack:** Laravel 8 / PHP 8.x, Eloquent, PHPUnit 9 (sin `phpunit.xml` en el proyecto — los tests se ejecutan invocando el archivo directamente con `vendor/bin/phpunit`).

**Spec de referencia:** `docs/superpowers/specs/2026-06-17-overspeed-geofence-reports-design.md`

---

### Notas de entorno (verificado antes de escribir este plan)

- No existe `phpunit.xml` en el repo. Los tests existentes en `tests/Unit/` están rotos (incompatibilidad de firma `setUp()` con PHPUnit 9) — no son una referencia funcional, solo de convención de estilo.
- `tests/TestCase.php` (clase global `TestCase`, sin namespace) SÍ está cargado vía `autoload-dev.classmap` en `composer.json` y arranca la aplicación Laravel completa.
- Se verificó que `vendor/bin/phpunit tests/Unit/<Archivo>.php` funciona correctamente sin `phpunit.xml`, instanciando `Geofence` sin tocar la base de datos y usando el helper global `getDistance()`.
- Todos los tests nuevos de este plan deben declarar `public function setUp(): void` (con el tipo de retorno `void`), a diferencia del estilo roto de los tests viejos.

---

### Task 1: `PolygonHelper::getArea()`

**Files:**
- Modify: `Tobuli/Helpers/PolygonHelper.php`
- Test: `tests/Unit/PolygonHelperTest.php`

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/Unit/PolygonHelperTest.php`:

```php
<?php

use Tobuli\Helpers\PolygonHelper;

class PolygonHelperTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
    }

    public function testGetAreaForUnitSquareReturnsOne()
    {
        $polygon = new PolygonHelper([
            ['lat' => 0, 'lng' => 0],
            ['lat' => 0, 'lng' => 1],
            ['lat' => 1, 'lng' => 1],
            ['lat' => 1, 'lng' => 0],
        ]);

        $this->assertEqualsWithDelta(1.0, $polygon->getArea(), 0.0001);
    }

    public function testGetAreaReturnsNullForEmptyPolygon()
    {
        $polygon = new PolygonHelper([]);

        $this->assertNull($polygon->getArea());
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `vendor/bin/phpunit tests/Unit/PolygonHelperTest.php`
Expected: `Error: Call to undefined method Tobuli\Helpers\PolygonHelper::getArea()`

- [ ] **Step 3: Implementar `getArea()` en `PolygonHelper`**

En `Tobuli/Helpers/PolygonHelper.php`, agregar la propiedad `private $area;` justo después de `private $center;` (línea 26):

```php
    private $center;

    private $area;

    private $lastPointHash;
    private $lastPointValue;
```

Luego modificar `getCenter()` (líneas 150-181) para guardar el área calculada, y agregar el método público `getArea()`. Reemplazar el método completo:

```php
    function getCenter()
    {
        if ( ! is_null($this->center))
            return $this->center;

        $cx = 0;
        $cy = 0;
        $area = 0;

        for ($vi = 0, $vl = sizeof($this->vertices); $vi < $vl; $vi++) {
            $thisx = $this->vertices[$vi]['x'];
            $thisy = $this->vertices[$vi]['y'];
            $nextx = $this->vertices[($vi + 1) % $vl]['x'];
            $nexty = $this->vertices[($vi + 1) % $vl]['y'];

            $p = ($thisx * $nexty) - ($thisy * $nextx);
            $cx += ($thisx + $nextx) * $p;
            $cy += ($thisy + $nexty) * $p;
            $area += ($thisx * $nexty) - ($thisy * $nextx);
        }

        if (empty($area))
            return null;

        $area = abs(($area / 2));
        $this->area = $area;

        $cx = -$cx / (6 * $area);
        $cy = -$cy / (6 * $area);

        $this->center = ['lat' => $cx, 'lng' => $cy];

        return $this->center;
    }

    function getArea(): ?float
    {
        $this->getCenter();

        return $this->area;
    }

}
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `vendor/bin/phpunit tests/Unit/PolygonHelperTest.php`
Expected: `OK (2 tests, 2 assertions)`

- [ ] **Step 5: Commit**

```bash
git add Tobuli/Helpers/PolygonHelper.php tests/Unit/PolygonHelperTest.php
git commit -m "Add getArea() to PolygonHelper, exposing the existing shoelace area calculation"
```

---

### Task 2: `Geofence::approxAreaSqMeters()` y `Geofence::pickBySpeedLimitPriority()`

**Files:**
- Modify: `Tobuli/Entities/Geofence.php`
- Test: `tests/Unit/GeofenceAreaPriorityTest.php`

- [ ] **Step 1: Escribir los tests que fallan**

Crear `tests/Unit/GeofenceAreaPriorityTest.php`:

```php
<?php

use Tobuli\Entities\Geofence;

class GeofenceAreaPriorityTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
    }

    public function testApproxAreaSqMetersForCircle()
    {
        $geofence = new Geofence([
            'type' => 'circle',
            'radius' => 500,
            'speed_limit' => 10,
        ]);

        $this->assertEqualsWithDelta(M_PI * 500 ** 2, $geofence->approxAreaSqMeters(), 0.01);
    }

    public function testApproxAreaSqMetersForCircleWithoutRadiusReturnsNull()
    {
        $geofence = new Geofence([
            'type' => 'circle',
            'speed_limit' => 10,
        ]);

        $this->assertNull($geofence->approxAreaSqMeters());
    }

    public function testApproxAreaSqMetersForPolygon()
    {
        $geofence = new Geofence([
            'type' => 'polygon',
            'polygon' => [
                ['lat' => 0, 'lng' => 0],
                ['lat' => 0, 'lng' => 1],
                ['lat' => 1, 'lng' => 1],
                ['lat' => 1, 'lng' => 0],
            ],
            'speed_limit' => 10,
        ]);

        // Centroide del cuadrado unitario es (0.5, 0.5); área en grados^2 es 1.0.
        $expected = 1.0 * 111320 * (111320 * cos(deg2rad(0.5)));

        $this->assertEqualsWithDelta($expected, $geofence->approxAreaSqMeters(), 1.0);
    }

    public function testApproxAreaSqMetersForPolygonWithoutCoordinatesReturnsNull()
    {
        $geofence = new Geofence([
            'type' => 'polygon',
            'speed_limit' => 10,
        ]);

        $this->assertNull($geofence->approxAreaSqMeters());
    }

    public function testPickBySpeedLimitPriorityReturnsSmallestArea()
    {
        $small = new Geofence(['type' => 'circle', 'radius' => 100, 'speed_limit' => 30]);
        $large = new Geofence(['type' => 'circle', 'radius' => 1000, 'speed_limit' => 50]);

        $winner = Geofence::pickBySpeedLimitPriority([$large, $small]);

        $this->assertSame($small, $winner);
    }

    public function testPickBySpeedLimitPriorityIgnoresGeofencesWithoutSpeedLimit()
    {
        $noLimit = new Geofence(['type' => 'circle', 'radius' => 10]);
        $withLimit = new Geofence(['type' => 'circle', 'radius' => 1000, 'speed_limit' => 50]);

        $winner = Geofence::pickBySpeedLimitPriority([$noLimit, $withLimit]);

        $this->assertSame($withLimit, $winner);
    }

    public function testPickBySpeedLimitPriorityReturnsNullWhenNoCandidates()
    {
        $noLimit = new Geofence(['type' => 'circle', 'radius' => 10]);

        $this->assertNull(Geofence::pickBySpeedLimitPriority([$noLimit]));
        $this->assertNull(Geofence::pickBySpeedLimitPriority([]));
    }

    public function testPickBySpeedLimitPriorityTreatsUnknownAreaAsLowestPriority()
    {
        $unknownArea = new Geofence(['type' => 'polygon', 'speed_limit' => 20]);
        $known = new Geofence(['type' => 'circle', 'radius' => 100000, 'speed_limit' => 50]);

        $winner = Geofence::pickBySpeedLimitPriority([$unknownArea, $known]);

        $this->assertSame($known, $winner);
    }
}
```

- [ ] **Step 2: Correr los tests y verificar que fallan**

Run: `vendor/bin/phpunit tests/Unit/GeofenceAreaPriorityTest.php`
Expected: `Error: Call to undefined method Tobuli\Entities\Geofence::approxAreaSqMeters()`

- [ ] **Step 3: Implementar los métodos en `Geofence`**

En `Tobuli/Entities/Geofence.php`, agregar estos dos métodos públicos. Insertarlos justo después de `getCenter()` (después de la línea `}` que cierra el método, antes de `private function pointInPolygon($point)`):

```php
    public function approxAreaSqMeters(): ?float
    {
        if ($this->type === self::TYPE_CIRCLE) {
            if (empty($this->radius))
                return null;

            return M_PI * pow($this->radius, 2);
        }

        $area = $this->getPolygonHelper()->getArea();

        if (empty($area))
            return null;

        $center = $this->getCenter();

        if (empty($center))
            return null;

        $metersPerDegreeLat = 111320;
        $metersPerDegreeLng = 111320 * cos(deg2rad($center['lat']));

        return $area * $metersPerDegreeLat * $metersPerDegreeLng;
    }

    public static function pickBySpeedLimitPriority(iterable $geofences): ?self
    {
        $candidates = collect($geofences)->filter(function (self $geofence) {
            return !empty($geofence->speed_limit);
        });

        if ($candidates->isEmpty())
            return null;

        return $candidates->sortBy(function (self $geofence) {
            $area = $geofence->approxAreaSqMeters();

            return is_null($area) ? PHP_FLOAT_MAX : $area;
        })->first();
    }
```

- [ ] **Step 4: Correr los tests y verificar que pasan**

Run: `vendor/bin/phpunit tests/Unit/GeofenceAreaPriorityTest.php`
Expected: `OK (8 tests, 9 assertions)`

- [ ] **Step 5: Commit**

```bash
git add Tobuli/Entities/Geofence.php tests/Unit/GeofenceAreaPriorityTest.php
git commit -m "Add Geofence::approxAreaSqMeters() and pickBySpeedLimitPriority() for overlapping-geofence priority"
```

---

### Task 3: Nueva acción `AppendSpeedLimitGeofenceByPriority`

**Files:**
- Create: `Tobuli/History/Actions/AppendSpeedLimitGeofenceByPriority.php`

No lleva test automatizado dedicado: la única lógica de selección que no es boilerplate (`Geofence::pickBySpeedLimitPriority`) ya está cubierta por los tests de la Task 2. Esta clase solo conecta esa lógica al pipeline de Historial.

- [ ] **Step 1: Crear el archivo**

```php
<?php

namespace Tobuli\History\Actions;

use Tobuli\Entities\Geofence;

class AppendSpeedLimitGeofenceByPriority extends ActionAppend
{
    private $limits = [];

    static public function required()
    {
        return [
            AppendGeofences::class,
        ];
    }

    public function boot()
    {
    }

    public function proccess(&$position)
    {
        $position->speed_limit = $this->getSpeedLimit($position);
    }

    protected function getSpeedLimit($position)
    {
        if (empty($position->geofences))
            return null;

        $key = $this->candidatesKey($position->geofences);

        if (array_key_exists($key, $this->limits))
            return $this->limits[$key];

        $candidates = collect($this->history->getGeofences())
            ->whereIn('id', $position->geofences)
            ->filter(function (Geofence $geofence) {
                return !empty($geofence->speed_limit);
            });

        $geofence = Geofence::pickBySpeedLimitPriority($candidates);

        return $this->limits[$key] = $geofence->speed_limit ?? null;
    }

    private function candidatesKey(array $geofenceIds): string
    {
        sort($geofenceIds);

        return implode(',', $geofenceIds);
    }
}
```

- [ ] **Step 2: Verificar que el archivo carga sin errores de sintaxis**

Run: `php -l Tobuli/History/Actions/AppendSpeedLimitGeofenceByPriority.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add Tobuli/History/Actions/AppendSpeedLimitGeofenceByPriority.php
git commit -m "Add AppendSpeedLimitGeofenceByPriority action for area-based geofence priority"
```

---

### Task 4: Conectar la nueva acción a 905/906 sin tocar el motor compartido

**Files:**
- Modify: `Tobuli/History/Actions/AppendOverspeeding.php:19-27`
- Modify: `Tobuli/Reports/Reports/OverspeedsInGeofenceCustomReportV2.php`
- Modify: `Tobuli/Reports/Reports/OverspeedsInGeofenceCustomReportBuenaventura.php`

`AppendOverspeeding::after()` declara qué acciones "fuente de límite de velocidad" deben ejecutarse antes que ella **si están presentes en el pipeline del reporte**. Hoy ya lista `AppendSpeedLimitGeofence` y `AppendSpeedLimitGeofenceMulti` (entre otras) — agregar la nueva clase ahí es el mismo patrón ya usado, y no afecta a ningún reporte que no la incluya explícitamente en su `getActionsList()`.

- [ ] **Step 1: Registrar la nueva acción en `AppendOverspeeding::after()`**

En `Tobuli/History/Actions/AppendOverspeeding.php`, reemplazar el método `after()` (líneas 19-27):

```php
    static public function after()
    {
        return [
            AppendSpeedLimitStatic::class,
            AppendSpeedLimitRoads::class,
            AppendSpeedLimitGeofence::class,
            AppendSpeedLimitGeofenceByPriority::class,
            AppendSpeedLimitGeofenceMulti::class,
        ];
    }
```

- [ ] **Step 2: Actualizar 905 (`OverspeedsInGeofenceCustomReportV2.php`)**

Reemplazar el import (línea con `use Tobuli\History\Actions\AppendSpeedLimitGeofence;`):

```php
use Tobuli\History\Actions\AppendSpeedLimitGeofenceByPriority;
```

Y en `getActionsList()` (líneas 45-59), reemplazar la línea `AppendSpeedLimitGeofence::class,` por:

```php
            AppendSpeedLimitGeofenceByPriority::class,
```

El método completo queda:

```php
    protected function getActionsList()
    {
        return [
            Duration::class,
            Distance::class,
            Speed::class,
            AppendSpeedLimitGeofenceByPriority::class,
            Overspeed::class,
            AppendPosition::class,
            Harsh::class,
            Drivers::class,
            GeofencesIn::class,
            AppendOverspeedOnlyInGeofences::class,
            GroupOverspeed::class,
        ];
    }
```

- [ ] **Step 3: Actualizar 906 (`OverspeedsInGeofenceCustomReportBuenaventura.php`)**

Aplicar exactamente el mismo cambio que en el Step 2 (mismo import, mismo `getActionsList()`) en `Tobuli/Reports/Reports/OverspeedsInGeofenceCustomReportBuenaventura.php`.

- [ ] **Step 4: Verificar que los tres archivos cargan sin errores de sintaxis**

Run:
```bash
php -l Tobuli/History/Actions/AppendOverspeeding.php
php -l Tobuli/Reports/Reports/OverspeedsInGeofenceCustomReportV2.php
php -l Tobuli/Reports/Reports/OverspeedsInGeofenceCustomReportBuenaventura.php
```
Expected: `No syntax errors detected` en los tres.

- [ ] **Step 5: Commit**

```bash
git add Tobuli/History/Actions/AppendOverspeeding.php Tobuli/Reports/Reports/OverspeedsInGeofenceCustomReportV2.php Tobuli/Reports/Reports/OverspeedsInGeofenceCustomReportBuenaventura.php
git commit -m "Use area-based geofence priority in 905/906, leaving the shared engine untouched"
```

---

### Task 5: Reescribir 904 — toggle, rendimiento y un evento por geocerca excedida

**Files:**
- Modify: `Tobuli/Reports/Reports/OverspeedsInGeofenceCustomReport.php` (reescritura completa)
- Test: `tests/Unit/OverspeedsInGeofenceCustomReportTest.php`

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/Unit/OverspeedsInGeofenceCustomReportTest.php`:

```php
<?php

use Tobuli\Entities\Geofence;
use Tobuli\Reports\Reports\OverspeedsInGeofenceCustomReport;

class TestableOverspeedsInGeofenceCustomReport extends OverspeedsInGeofenceCustomReport
{
    public function setTestGeofences(array $geofences)
    {
        $this->speedLimitGeofences = collect($geofences);
    }

    public function setTestOnlyViolations(bool $value)
    {
        $this->onlyViolations = $value;
    }

    public function callResolveGeofenceEvents(array $point, float $speed): array
    {
        return $this->resolveGeofenceEvents($point, $speed);
    }
}

class OverspeedsInGeofenceCustomReportTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
    }

    private function curveGeofence(): Geofence
    {
        return new Geofence([
            'type' => 'circle',
            'center' => ['lat' => 0, 'lng' => 0],
            'radius' => 1000,
            'speed_limit' => 40,
        ]);
    }

    private function zoneGeofence(): Geofence
    {
        return new Geofence([
            'type' => 'circle',
            'center' => ['lat' => 0, 'lng' => 0],
            'radius' => 5000,
            'speed_limit' => 55,
        ]);
    }

    public function testReturnsOneRowPerExceededGeofence()
    {
        $report = new TestableOverspeedsInGeofenceCustomReport();
        $report->setTestGeofences([$this->curveGeofence(), $this->zoneGeofence()]);
        $report->setTestOnlyViolations(true);

        $events = $report->callResolveGeofenceEvents(['latitude' => 0, 'longitude' => 0], 60);

        $this->assertCount(2, $events);
        $this->assertTrue($events[0]['exceeded']);
        $this->assertTrue($events[1]['exceeded']);
    }

    public function testOnlyExceededGeofencesAreReturnedWhenMixed()
    {
        $report = new TestableOverspeedsInGeofenceCustomReport();
        $report->setTestGeofences([$this->curveGeofence(), $this->zoneGeofence()]);
        $report->setTestOnlyViolations(true);

        // 45 km/h excede la curva (40) pero no la zona (55)
        $events = $report->callResolveGeofenceEvents(['latitude' => 0, 'longitude' => 0], 45);

        $this->assertCount(1, $events);
        $this->assertSame(40, $events[0]['geofence']->speed_limit);
    }

    public function testReturnsNoRowsWhenNoExceedAndOnlyViolationsEnabled()
    {
        $report = new TestableOverspeedsInGeofenceCustomReport();
        $report->setTestGeofences([$this->curveGeofence(), $this->zoneGeofence()]);
        $report->setTestOnlyViolations(true);

        $events = $report->callResolveGeofenceEvents(['latitude' => 0, 'longitude' => 0], 30);

        $this->assertCount(0, $events);
    }

    public function testReturnsOneRowByPriorityWhenNoExceedAndOnlyViolationsDisabled()
    {
        $report = new TestableOverspeedsInGeofenceCustomReport();
        // Orden intencional: la zona grande primero, para probar que el orden de
        // entrada no determina el resultado -- el área sí.
        $report->setTestGeofences([$this->zoneGeofence(), $this->curveGeofence()]);
        $report->setTestOnlyViolations(false);

        $events = $report->callResolveGeofenceEvents(['latitude' => 0, 'longitude' => 0], 30);

        $this->assertCount(1, $events);
        $this->assertFalse($events[0]['exceeded']);
        $this->assertSame(40, $events[0]['geofence']->speed_limit);
    }

    public function testReturnsNoRowsWhenPointOutsideAllGeofences()
    {
        $report = new TestableOverspeedsInGeofenceCustomReport();
        $report->setTestGeofences([$this->curveGeofence(), $this->zoneGeofence()]);
        $report->setTestOnlyViolations(false);

        $events = $report->callResolveGeofenceEvents(['latitude' => 50, 'longitude' => 50], 30);

        $this->assertCount(0, $events);
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `vendor/bin/phpunit tests/Unit/OverspeedsInGeofenceCustomReportTest.php`
Expected: `Error: Call to undefined method TestableOverspeedsInGeofenceCustomReport::resolveGeofenceEvents()` — la clase 904 actual todavía no tiene ese método (`setTestGeofences`/`setTestOnlyViolations` no fallan porque PHP permite crear propiedades dinámicas, pero `callResolveGeofenceEvents()` sí falla al invocar el método inexistente).

- [ ] **Step 3: Reescribir `OverspeedsInGeofenceCustomReport.php` completo**

Reemplazar **todo el contenido** de `Tobuli/Reports/Reports/OverspeedsInGeofenceCustomReport.php`:

```php
<?php

namespace Tobuli\Reports\Reports;

use Formatter;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Tobuli\History\Group;
use Tobuli\Entities\Geofence;
use Illuminate\Database\QueryException;
use Tobuli\Reports\DeviceReport;

class OverspeedsInGeofenceCustomReport extends DeviceReport
{
    const TYPE_ID = 904;

    protected $disableFields = ['stops', 'geofences'];

    /** @var Collection */
    protected $speedLimitGeofences;

    protected $onlyViolations = true;

    public function typeID()
    {
        return self::TYPE_ID;
    }

    public function title()
    {
        return trans('front.overspeeds') . ' / ' . trans('front.geofences') . ' - Talentus';
    }

    public function getInputParameters(): array
    {
        return [
            \Field::checkbox('only_violations', 'Mostrar solo excesos de velocidad', true),
        ];
    }

    protected function beforeGenerate()
    {
        parent::beforeGenerate();

        $this->speedLimitGeofences = Geofence::where('user_id', $this->user->id)
            ->where('active', 1)
            ->whereNotNull('speed_limit')
            ->get();
    }

    protected function isEmptyResult($data)
    {
        return empty($data['groups']) || empty($data['groups']->all());
    }

    /**
     * Para una posicion: si excede el limite de una o mas geocercas, retorna un
     * evento por cada una. Si no excede ninguna, retorna un solo evento usando
     * la geocerca mas especifica (area mas pequena) -- solo cuando
     * onlyViolations esta desactivado.
     *
     * @return array<int, array{geofence: Geofence, exceeded: bool}>
     */
    protected function resolveGeofenceEvents(array $point, float $speed): array
    {
        $matches = $this->speedLimitGeofences->filter(function (Geofence $geofence) use ($point) {
            return $geofence->pointIn($point);
        });

        if ($matches->isEmpty()) {
            return [];
        }

        $exceeded = $matches->filter(function (Geofence $geofence) use ($speed) {
            return $speed > $geofence->speed_limit;
        });

        if ($exceeded->isNotEmpty()) {
            return $exceeded->map(function (Geofence $geofence) {
                return ['geofence' => $geofence, 'exceeded' => true];
            })->values()->all();
        }

        if ($this->onlyViolations) {
            return [];
        }

        $primary = Geofence::pickBySpeedLimitPriority($matches);

        return $primary ? [['geofence' => $primary, 'exceeded' => false]] : [];
    }

    protected function buildRow($position, Geofence $geofence, bool $exceeded): array
    {
        $speedDifference = $exceeded ? round($position->speed - $geofence->speed_limit, 2) : '';

        return [
            'server_time' => Formatter::time()->human($position->server_time),
            'time'        => Formatter::time()->human($position->time),
            'speed'       => Formatter::speed()->human($position->speed),
            'altitude'    => Formatter::altitude()->human($position->altitude),
            'latitude'    => $position->latitude,
            'longitude'   => $position->longitude,
            'location'    => $this->getLocation($position, $this->getAddress($position)),
            'geofence_name' => $geofence->name,
            'speed_limit' => $geofence->speed_limit,
            'speed_difference' => $speedDifference,
            'violation_level' => $exceeded ? $this->getInfringementLevel($speedDifference) : '',
            'speed_limit_exceeded' => $exceeded,
        ];
    }

    protected function getInfringementLevel($speedDifference)
    {
        if ($speedDifference <= 10) {
            return 'Moderado';
        } elseif ($speedDifference <= 20) {
            return 'Grave';
        } else {
            return 'Muy Grave';
        }
    }

    protected function processPosition($position): array
    {
        $point = [
            'latitude'  => $position->latitude,
            'longitude' => $position->longitude,
        ];

        $events = $this->resolveGeofenceEvents($point, (float) $position->speed);

        return array_map(function (array $event) use ($position) {
            return $this->buildRow($position, $event['geofence'], $event['exceeded']);
        }, $events);
    }

    protected function generateDevice($device)
    {
        $this->onlyViolations = Arr::get($this->parameters, 'only_violations', 1) != 0;

        $rows = [];
        try {
            $device->positions()
                ->orderliness('asc')
                ->whereBetween('time', [$this->date_from, $this->date_to])
                ->where('speed', '>', 0)
                ->chunk(
                    2000,
                    function ($positions) use (&$rows) {
                        foreach ($positions as $position) {
                            array_push($rows, ...$this->processPosition($position));
                        }
                    }
                );
        } catch (QueryException $e) {
            return null;
        }

        if (empty($rows)) {
            return [
                'meta'  => $this->getDeviceMeta($device),
                'error' => trans('front.nothing_found_request'),
            ];
        }

        return [
            'meta'  => $this->getDeviceMeta($device),
            'table' => [
                'rows' => $rows,
            ],
        ];
    }

    protected function getTotals(Group $group, array $only = [])
    {
        return parent::getTotals($group, ['overspeed_count']);
    }
}
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `vendor/bin/phpunit tests/Unit/OverspeedsInGeofenceCustomReportTest.php`
Expected: `OK (5 tests, 10 assertions)`

- [ ] **Step 5: Commit**

```bash
git add Tobuli/Reports/Reports/OverspeedsInGeofenceCustomReport.php tests/Unit/OverspeedsInGeofenceCustomReportTest.php
git commit -m "Make report 904 reusable: only_violations toggle, one row per exceeded geofence, cached geofence lookup"
```

---

### Task 6: Verificación final

**Files:** ninguno (solo verificación)

- [ ] **Step 1: Correr todos los tests nuevos juntos**

Run:
```bash
vendor/bin/phpunit tests/Unit/PolygonHelperTest.php tests/Unit/GeofenceAreaPriorityTest.php tests/Unit/OverspeedsInGeofenceCustomReportTest.php
```
Expected: `OK (15 tests, 21 assertions)`

- [ ] **Step 2: Verificar sintaxis de todos los archivos tocados**

Run:
```bash
php -l Tobuli/Helpers/PolygonHelper.php
php -l Tobuli/Entities/Geofence.php
php -l Tobuli/History/Actions/AppendSpeedLimitGeofenceByPriority.php
php -l Tobuli/History/Actions/AppendOverspeeding.php
php -l Tobuli/Reports/Reports/OverspeedsInGeofenceCustomReport.php
php -l Tobuli/Reports/Reports/OverspeedsInGeofenceCustomReportV2.php
php -l Tobuli/Reports/Reports/OverspeedsInGeofenceCustomReportBuenaventura.php
```
Expected: `No syntax errors detected` en los siete.

- [ ] **Step 3: Revisión manual — confirmar que `RagGeofencesReport` no cambió**

Run: `grep -n "AppendSpeedLimitGeofence" Tobuli/Reports/Reports/RagGeofencesReport.php`
Expected: `AppendSpeedLimitGeofence::class,` (la acción original, sin cambios) — si apareciera `AppendSpeedLimitGeofenceByPriority`, algo se modificó por error en este reporte, que debe quedar fuera de alcance.

- [ ] **Step 4: Smoke test manual en el navegador (no automatizable sin fixtures de dispositivos/posiciones)**

Como no existen factories para `Device`/posiciones GPS en este proyecto, no es posible generar un reporte 904/905/906 de extremo a extremo en un test automatizado sin datos reales. Generar manualmente, contra datos de prueba reales, un reporte 904 con `only_violations` activado y desactivado, y un reporte 905 o 906 sobre un punto que esté dentro de una geocerca pequeña y una grande simultáneamente, para confirmar visualmente el comportamiento antes de dar el trabajo por terminado.
