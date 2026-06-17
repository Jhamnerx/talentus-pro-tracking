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
