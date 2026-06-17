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
            'center' => ['lat' => 10, 'lng' => 10],
            'radius' => 1000,
            'speed_limit' => 40,
        ]);
    }

    private function zoneGeofence(): Geofence
    {
        return new Geofence([
            'type' => 'circle',
            'center' => ['lat' => 10, 'lng' => 10],
            'radius' => 5000,
            'speed_limit' => 55,
        ]);
    }

    public function testReturnsOneRowPerExceededGeofence()
    {
        $report = new TestableOverspeedsInGeofenceCustomReport();
        $report->setTestGeofences([$this->curveGeofence(), $this->zoneGeofence()]);
        $report->setTestOnlyViolations(true);

        $events = $report->callResolveGeofenceEvents(['latitude' => 10, 'longitude' => 10], 60);

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
        $events = $report->callResolveGeofenceEvents(['latitude' => 10, 'longitude' => 10], 45);

        $this->assertCount(1, $events);
        $this->assertSame(40, $events[0]['geofence']->speed_limit);
    }

    public function testReturnsNoRowsWhenNoExceedAndOnlyViolationsEnabled()
    {
        $report = new TestableOverspeedsInGeofenceCustomReport();
        $report->setTestGeofences([$this->curveGeofence(), $this->zoneGeofence()]);
        $report->setTestOnlyViolations(true);

        $events = $report->callResolveGeofenceEvents(['latitude' => 10, 'longitude' => 10], 30);

        $this->assertCount(0, $events);
    }

    public function testReturnsOneRowByPriorityWhenNoExceedAndOnlyViolationsDisabled()
    {
        $report = new TestableOverspeedsInGeofenceCustomReport();
        // Orden intencional: la zona grande primero, para probar que el orden de
        // entrada no determina el resultado -- el área sí.
        $report->setTestGeofences([$this->zoneGeofence(), $this->curveGeofence()]);
        $report->setTestOnlyViolations(false);

        $events = $report->callResolveGeofenceEvents(['latitude' => 10, 'longitude' => 10], 30);

        $this->assertCount(1, $events);
        $this->assertFalse($events[0]['exceeded']);
        $this->assertSame(40, $events[0]['geofence']->speed_limit);
    }

    public function testReturnsNoRowsWhenPointOutsideAllGeofences()
    {
        $report = new TestableOverspeedsInGeofenceCustomReport();
        $report->setTestGeofences([$this->curveGeofence(), $this->zoneGeofence()]);
        $report->setTestOnlyViolations(false);

        // (0,0) se evita a propósito: el helper global getDistance() trata
        // (0,0) como "sin GPS fix" y retorna distancia 0 si cualquiera de los
        // dos puntos comparados es exactamente (0,0), lo que haría que este
        // punto pareciera estar dentro de cualquier geocerca. Por eso tanto
        // los centros de las geocercas como este punto usan coordenadas no
        // nulas.
        $events = $report->callResolveGeofenceEvents(['latitude' => 50, 'longitude' => 50], 30);

        $this->assertCount(0, $events);
    }
}
