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
