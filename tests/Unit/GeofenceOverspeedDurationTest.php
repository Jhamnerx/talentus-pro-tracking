<?php

use Illuminate\Support\Facades\Cache;
use Tobuli\Entities\Alert;
use Tobuli\Entities\Device;
use Tobuli\Entities\Geofence;
use Tobuli\Entities\TraccarPosition;
use Tobuli\Helpers\Alerts\Check\GeofenceAlertCheck;

/**
 * Doble de prueba: aísla check() de horarios, polígonos y límites por tipo de vehículo.
 * Geocerca siempre "dentro", horario siempre activo, límite fijo de 50 km/h.
 */
class TestableGeofenceAlertCheck extends GeofenceAlertCheck
{
    public function __construct(Device $device, Alert $alert)
    {
        $this->device = $device;
        $this->alert = $alert;
    }

    public function runCheck($position, $geofence)
    {
        return $this->check($position, null, $geofence);
    }

    public function trackingKey($geofence)
    {
        return $this->getOverspeedTrackingKey($geofence);
    }

    protected function checkSchedules($time)
    {
        return true;
    }

    protected function checkGeofence($position, $geofence)
    {
        return (bool) $position;
    }

    protected function resolveSpeedLimit($geofence): ?float
    {
        return 50.0;
    }
}

/**
 * GeofenceAlertCheck::checkOverspeedDuration() mide la duración con la hora de la
 * posición, no con time() del servidor. Caché en store 'array', sin base de datos.
 */
class GeofenceOverspeedDurationTest extends TestCase
{
    /** @var TestableGeofenceAlertCheck */
    private $checker;

    private $geofence;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::flush();

        $alert = $this->getMockBuilder(Alert::class)
            ->onlyMethods(['getMinOverspeedDuration', 'getSpeedTolerance'])
            ->getMock();
        $alert->method('getMinOverspeedDuration')->willReturn(5);
        $alert->method('getSpeedTolerance')->willReturn(0);
        $alert->id = 7;
        $alert->type = 'geofence_overspeed';

        $device = new Device();
        $device->id = 219;

        $this->geofence = new Geofence();
        $this->geofence->id = 31;

        $this->checker = new TestableGeofenceAlertCheck($device, $alert);
    }

    private function position($time, $speed)
    {
        $position = new TraccarPosition();
        $position->time = $time;
        $position->speed = $speed;
        $position->valid = 1;
        $position->setParameter('sat', 20);

        return $position;
    }

    private function evaluate($time, $speed)
    {
        return $this->checker->runCheck($this->position($time, $speed), $this->geofence);
    }

    private function mark()
    {
        return Cache::get($this->checker->trackingKey($this->geofence));
    }

    public function test_isolated_reading_does_not_fire_and_clears_cache()
    {
        $this->assertFalse($this->evaluate('2026-09-25 03:05:36', 60));
        $this->assertNotNull($this->mark());

        $this->assertFalse($this->evaluate('2026-09-25 03:05:36', 0));
        $this->assertNull($this->mark());
    }

    public function test_sustained_overspeed_fires_at_six_seconds()
    {
        $this->assertFalse($this->evaluate('2026-09-26 10:00:00', 70));
        $this->assertFalse($this->evaluate('2026-09-26 10:00:02', 70));
        $this->assertFalse($this->evaluate('2026-09-26 10:00:04', 70));
        $this->assertSame('geofence_overspeed', $this->evaluate('2026-09-26 10:00:06', 70));
        $this->assertNull($this->mark());
    }

    public function test_reprocessed_history_does_not_depend_on_server_clock()
    {
        // Posiciones de hace dos días procesadas de golpe (mismo segundo de servidor)
        $start = time();

        $results = [
            $this->evaluate('2026-09-25 03:05:30', 70),
            $this->evaluate('2026-09-25 03:05:32', 70),
            $this->evaluate('2026-09-25 03:05:34', 70),
            $this->evaluate('2026-09-25 03:05:36', 70),
        ];

        $this->assertLessThanOrEqual(1, time() - $start);
        $this->assertSame([false, false, false, 'geofence_overspeed'], $results);
    }

    public function test_out_of_order_position_restarts_mark()
    {
        $this->assertFalse($this->evaluate('2026-09-26 10:00:10', 70));
        $this->assertSame(strtotime('2026-09-26 10:00:10'), $this->mark());

        $this->assertFalse($this->evaluate('2026-09-26 10:00:04', 70));
        $this->assertSame(strtotime('2026-09-26 10:00:04'), $this->mark());
    }

    public function test_stale_mark_restarts_instead_of_firing()
    {
        $this->assertFalse($this->evaluate('2026-09-26 10:00:00', 70));

        $this->assertFalse($this->evaluate('2026-09-26 10:06:40', 70));
        $this->assertSame(strtotime('2026-09-26 10:06:40'), $this->mark());
    }
}
