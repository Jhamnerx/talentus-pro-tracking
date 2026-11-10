<?php

namespace Tobuli\Services\Operations;

/**
 * Graficos del reporte de turno como SVG en linea, para el PDF (wkhtmltopdf
 * no ejecuta el canvas de la pantalla de forma fiable). Mismo dibujo que
 * tp-operations-shift.js: lineas por hora con la etiqueta del valor sobre
 * cada punto, y barras apiladas de 24 h por equipo.
 *
 * Todo sale de los arrays de OperationShiftReport::build(); aqui no se
 * consulta nada.
 */
class ShiftReportSvg
{
    const FONT = 'Helvetica, Arial, sans-serif';
    const GRID = '#e5e7eb';
    const AXIS = '#94a3b8';

    /**
     * @param string[] $labels   etiquetas del eje X
     * @param array    $series   [['name' => , 'color' => '#hex', 'values' => [float|null]]]
     * @param int      $decimals decimales de las etiquetas
     */
    public static function lineChart(array $labels, array $series, int $decimals = 0, int $width = 520, int $height = 190): string
    {
        $n = count($labels);

        if (! $n) {
            return '';
        }

        $padL = 36;
        $padR = 10;
        $padT = 16;
        $padB = 20;
        $gw = $width - $padL - $padR;
        $gh = $height - $padT - $padB;

        $vmax = 0;
        foreach ($series as $s) {
            foreach ($s['values'] as $v) {
                if (! is_null($v) && $v > $vmax) {
                    $vmax = $v;
                }
            }
        }
        $vmax = self::niceMax($vmax, $decimals);

        $xAt = fn (int $i) => $padL + ($n === 1 ? $gw / 2 : $gw * $i / ($n - 1));
        $yAt = fn ($v) => $padT + $gh * (1 - min($v, $vmax) / $vmax);
        $fmt = fn ($v) => number_format((float) $v, $decimals, ',', '.');

        $out = [];
        $out[] = sprintf('<svg xmlns="http://www.w3.org/2000/svg" width="100%%" height="%d" viewBox="0 0 %d %d" preserveAspectRatio="xMidYMin meet" font-family="%s">', $height, $width, $height, self::FONT);

        // Rejilla y eje Y
        for ($g = 0; $g <= 4; $g++) {
            $y = round($padT + $gh * $g / 4, 1);
            $out[] = sprintf('<line x1="%d" y1="%s" x2="%d" y2="%s" stroke="%s" stroke-width="1"/>', $padL, $y, $width - $padR, $y, self::GRID);
            $out[] = sprintf('<text x="%d" y="%s" font-size="8" fill="%s" text-anchor="end">%s</text>', $padL - 4, $y + 3, self::AXIS, $fmt($vmax * (1 - $g / 4)));
        }

        // Eje X
        $every = $gw / $n < 18 ? 2 : 1;
        for ($i = 0; $i < $n; $i++) {
            $x = round($xAt($i), 1);
            $out[] = sprintf('<line x1="%s" y1="%d" x2="%s" y2="%d" stroke="%s" stroke-width="0.5"/>', $x, $padT, $x, $padT + $gh, self::GRID);
            if ($i % $every === 0) {
                $out[] = sprintf('<text x="%s" y="%d" font-size="8" fill="%s" text-anchor="middle">%s</text>', $x, $height - 6, self::AXIS, self::e($labels[$i]));
            }
        }

        // Series: linea (cortada en los huecos), puntos y valor
        $multi = count($series) > 1;

        foreach (array_values($series) as $si => $s) {
            $color = self::e($s['color']);
            $path = '';
            $open = false;

            foreach ($s['values'] as $i => $v) {
                if (is_null($v)) {
                    $open = false;
                    continue;
                }
                $path .= ($open ? ' L ' : ' M ') . round($xAt($i), 1) . ' ' . round($yAt($v), 1);
                $open = true;
            }

            if ($path) {
                $out[] = sprintf('<path d="%s" fill="none" stroke="%s" stroke-width="1.6" stroke-linejoin="round"/>', trim($path), $color);
            }

            foreach ($s['values'] as $i => $v) {
                if (is_null($v)) {
                    continue;
                }
                $cx = round($xAt($i), 1);
                $cy = round($yAt($v), 1);
                $below = $multi && $si % 2 === 1;
                $out[] = sprintf('<circle cx="%s" cy="%s" r="2.2" fill="%s"/>', $cx, $cy, $color);
                $out[] = sprintf('<text x="%s" y="%s" font-size="%s" fill="%s" text-anchor="middle">%s</text>', $cx, $below ? $cy + 10 : $cy - 5, $multi ? 6.5 : 7.5, $color, $fmt($v));
            }
        }

        $out[] = '</svg>';

        return implode('', $out);
    }

    /**
     * Barras apiladas de 24 h por equipo (estados por equipo).
     *
     * @param array $states OperationShiftReport::build()['states']
     */
    public static function statesChart(array $states, string $hoursShort = 'h', int $width = 1000): string
    {
        $legend = $states['legend'] ?? [];
        $rows = $states['rows'] ?? [];
        $total = $states['total_seconds'] ?? 86400;

        if (! $rows) {
            return '';
        }

        $nameW = 90;
        $rowH = 20;
        $gap = 6;
        $trackX = $nameW + 6;
        $trackW = $width - $trackX - 8;
        $height = count($rows) * ($rowH + $gap) + 18;

        $out = [];
        $out[] = sprintf('<svg xmlns="http://www.w3.org/2000/svg" width="100%%" height="%d" viewBox="0 0 %d %d" preserveAspectRatio="xMidYMin meet" font-family="%s">', $height, $width, $height, self::FONT);

        foreach (array_values($rows) as $r => $row) {
            $y = $r * ($rowH + $gap);
            $out[] = sprintf('<text x="0" y="%s" font-size="10" font-weight="bold" fill="#1f2937">%s</text>', $y + $rowH / 2 + 3.5, self::e(mb_strimwidth((string) $row['name'], 0, 16, '…')));
            $out[] = sprintf('<rect x="%d" y="%d" width="%d" height="%d" fill="#f1f5f9"/>', $trackX, $y, $trackW, $rowH);

            $x = $trackX;
            foreach ($legend as $l) {
                $sec = (int) ($row[$l['key']] ?? 0);
                if ($sec <= 0) {
                    continue;
                }
                $w = $trackW * $sec / $total;
                $out[] = sprintf('<rect x="%s" y="%d" width="%s" height="%d" fill="%s"/>', round($x, 2), $y, round($w, 2), $rowH, self::e($l['color']));
                if ($w / $trackW >= 0.04) {
                    $text = number_format($sec / 3600, 1, ',', '.');
                    $fill = $l['key'] === 'off' ? '#475569' : '#ffffff';
                    $out[] = sprintf('<text x="%s" y="%s" font-size="9" font-weight="bold" fill="%s" text-anchor="middle">%s</text>', round($x + $w / 2, 1), $y + $rowH / 2 + 3, $fill, $text);
                }
                $x += $w;
            }
        }

        // Escala 0-24 h
        $sy = count($rows) * ($rowH + $gap) + 10;
        foreach ([0, 4, 8, 12, 16, 20, 24] as $h) {
            $x = $trackX + $trackW * $h / 24;
            $anchor = $h === 0 ? 'start' : ($h === 24 ? 'end' : 'middle');
            $out[] = sprintf('<text x="%s" y="%d" font-size="8" fill="%s" text-anchor="%s">%d %s</text>', round($x, 1), $sy, self::AXIS, $anchor, $h, self::e($hoursShort));
        }

        $out[] = '</svg>';

        return implode('', $out);
    }

    protected static function niceMax(float $v, int $decimals): float
    {
        if ($v <= 0) {
            return $decimals ? 10 : 4;
        }

        if (! $decimals) {
            return max(4, ceil($v * 1.15 / 4) * 4);
        }

        $mag = pow(10, floor(log10($v)));
        $r = $v / $mag;
        $step = $r <= 1 ? 1 : ($r <= 2 ? 2 : ($r <= 2.5 ? 2.5 : ($r <= 5 ? 5 : 10)));
        $top = $step * $mag;

        if ($top <= $v) {
            $top *= 2;
        }

        return $top == $v ? $top * 1.1 : $top;
    }

    protected static function e($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}
