<?php

use App\Console\PositionsWriter;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Reglas del filtro GNSS glitch (PositionsWriter::evaluateGnssGlitch).
 * Prueba pura: no arranca la aplicación ni toca la base de datos.
 */
class GnssGlitchTest extends BaseTestCase
{
    const LAT = -13.5319;
    const LNG = -71.9675;

    // Valores por defecto de config('tobuli.gnss_glitch')
    private $cfg = [
        'enabled'             => true,
        'enforce'             => false,
        'max_age_seconds'     => 30,
        'alt_min_jump_m'      => 40,
        'alt_max_rate_ms'     => 20,
        'alt_stationary_speed_kph'  => 3,
        'alt_stationary_max_dist_m' => 10,
        'accel_max_kph_s'     => 10,
        'accel_min_speed_kph' => 25,
        'tele_min_distance_m' => 50,
        'tele_speed_factor'   => 2,
        'tele_speed_margin'   => 40,
    ];

    private function point($time, $speed, $altitude = 3658, $northMeters = 0)
    {
        return [
            'time'      => $time,
            'speed'     => $speed,
            'altitude'  => $altitude,
            'latitude'  => self::LAT + $northMeters / 111195,
            'longitude' => self::LNG,
        ];
    }

    private function evaluate(array $current, array $last, &$metrics = [])
    {
        return PositionsWriter::evaluateGnssGlitch($current, $last, $this->cfg, $metrics);
    }

    public function test_speed_spike_4_to_123_in_one_second_is_acc()
    {
        $reasons = $this->evaluate(
            $this->point('2026-09-25 16:20:00', 123),
            $this->point('2026-09-25 16:19:59', 4)
        );

        $this->assertSame(['acc'], $reasons);
    }

    public function test_altitude_jump_is_alt()
    {
        $reasons = $this->evaluate(
            $this->point('2026-09-25 16:20:02', 5, 4257),
            $this->point('2026-09-25 16:20:00', 5, 3658)
        );

        $this->assertContains('alt', $reasons);
    }

    public function test_657m_in_two_seconds_at_36kph_is_tele()
    {
        $reasons = $this->evaluate(
            $this->point('2026-09-25 16:20:02', 36, 3658, 657),
            $this->point('2026-09-25 16:20:00', 36)
        );

        $this->assertContains('tele', $reasons);
    }

    public function test_normal_acceleration_is_clean()
    {
        $reasons = $this->evaluate(
            $this->point('2026-09-25 16:20:02', 18, 3660, 10),
            $this->point('2026-09-25 16:20:00', 10, 3658)
        );

        $this->assertSame([], $reasons);
    }

    public function test_noisy_low_speed_is_below_floor()
    {
        $reasons = $this->evaluate(
            $this->point('2026-09-25 16:20:01', 16),
            $this->point('2026-09-25 16:20:00', 0)
        );

        $this->assertSame([], $reasons);
    }

    public function test_braking_is_not_acc()
    {
        $reasons = $this->evaluate(
            $this->point('2026-09-25 16:20:02', 20, 3658, 22),
            $this->point('2026-09-25 16:20:00', 60)
        );

        $this->assertSame([], $reasons);
    }

    public function test_last_valid_older_than_max_age_is_ignored()
    {
        $reasons = $this->evaluate(
            $this->point('2026-09-25 16:20:31', 123, 19489, 657),
            $this->point('2026-09-25 16:20:00', 4)
        );

        $this->assertSame([], $reasons);
    }

    public function test_out_of_order_history_is_ignored()
    {
        $reasons = $this->evaluate(
            $this->point('2026-09-25 16:19:58', 123, 19489, 657),
            $this->point('2026-09-25 16:20:00', 4)
        );

        $this->assertSame([], $reasons);
    }

    public function test_same_second_does_not_divide_by_zero()
    {
        $metrics = [];
        $reasons = $this->evaluate(
            $this->point('2026-09-25 16:20:00', 102, -1963, 100),
            $this->point('2026-09-25 16:20:00', 4),
            $metrics
        );

        $this->assertSame(1, $metrics['dt']);
        $this->assertSame(['alt', 'acc', 'tele'], $reasons);
    }

    // --- Corroboración de movimiento para la regla alt (vehículo detenido) ---

    public function test_stationary_altitude_drop_to_zero_is_not_alt()
    {
        // Caso real AUV-509: parado, altitud 112 → 0 (heartbeat sin fix de elevación)
        $metrics = [];
        $reasons = $this->evaluate(
            $this->point('2026-09-27 08:00:02', 0, 0.0),
            $this->point('2026-09-27 08:00:00', 0, 112.0),
            $metrics
        );

        $this->assertSame([], $reasons);
        $this->assertTrue($metrics['stationary']);
        $this->assertEquals(0.0, $metrics['distM']);
    }

    public function test_moving_altitude_jump_is_still_alt()
    {
        // En marcha: 45 km/h, 120 m en 2 s, salto de altitud grande (tipo CJN-824)
        $metrics = [];
        $reasons = $this->evaluate(
            $this->point('2026-09-25 16:20:02', 45, 4257, 120),
            $this->point('2026-09-25 16:20:00', 45, 3658),
            $metrics
        );

        $this->assertContains('alt', $reasons);
        $this->assertFalse($metrics['stationary']);
    }

    public function test_stationary_speed_threshold_boundary()
    {
        // Exactamente en el umbral (3 km/h, <=): se considera detenido, alt descartado
        $metrics = [];
        $reasons = $this->evaluate(
            $this->point('2026-09-27 08:00:02', 3, 0, 5),
            $this->point('2026-09-27 08:00:00', 3, 112),
            $metrics
        );
        $this->assertNotContains('alt', $reasons);
        $this->assertTrue($metrics['stationary']);

        // Por encima del umbral (5 km/h) con movimiento real: alt se evalúa y dispara
        $metrics = [];
        $reasons = $this->evaluate(
            $this->point('2026-09-27 08:00:02', 5, 0, 5),
            $this->point('2026-09-27 08:00:00', 5, 112),
            $metrics
        );
        $this->assertContains('alt', $reasons);
        $this->assertFalse($metrics['stationary']);
    }

    public function test_stationary_speed_but_real_displacement_is_still_alt()
    {
        // Velocidad 0 pero se desplazó más de alt_stationary_max_dist_m: no es "detenido"
        $reasons = $this->evaluate(
            $this->point('2026-09-27 08:00:10', 0, 0, 30),
            $this->point('2026-09-27 08:00:00', 0, 1112)
        );

        $this->assertContains('alt', $reasons);
    }

    public function test_zero_altitude_while_moving_is_still_alt()
    {
        // altitude = 0 en marcha (40 km/h, 150 m): no se oculta el glitch por coincidir en 0
        $metrics = [];
        $reasons = $this->evaluate(
            $this->point('2026-09-27 08:00:02', 40, 0, 150),
            $this->point('2026-09-27 08:00:00', 40, 3658),
            $metrics
        );

        $this->assertContains('alt', $reasons);
        $this->assertFalse($metrics['stationary']);
    }
}
