<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Tobuli\Entities\MapIcon;
use Tobuli\Entities\Poi;

/**
 * Catálogo de iconos de POI (tabla map_icons).
 *
 *  - Quita las filas cuyo fichero ya no existe en public/ (salían como
 *    icono roto en el selector y en el mapa) y reasigna los POIs que las
 *    usaban al primer icono válido.
 *  - Con --generate añade un juego de marcadores planos con el estilo de
 *    la UI v2 (pin redondeado con punto blanco, paleta de estados) para que
 *    el usuario tenga puntos con el estilo nuevo sin subir imágenes a mano.
 *
 *   php artisan map-icons:refresh            # solo limpiar
 *   php artisan map-icons:refresh --generate # limpiar + generar 12 pines v2
 */
class MapIconsRefreshCommand extends Command
{
    protected $signature = 'map-icons:refresh
                            {--generate : Genera los pines con estilo v2 en images/map_icons/v2_*.png}
                            {--dry-run : Solo muestra lo que haría}';

    protected $description = 'Quita los iconos de POI sin fichero (reasignando sus POIs) y, opcionalmente, genera pines con el estilo v2';

    /** Paleta v2 (nombre => color). */
    private const PALETTE = [
        'blue'   => '#2e63d9',
        'green'  => '#16a34a',
        'orange' => '#f59e0b',
        'red'    => '#dc2626',
        'purple' => '#7c3aed',
        'cyan'   => '#0891b2',
        'pink'   => '#db2777',
        'teal'   => '#0d9488',
        'yellow' => '#eab308',
        'slate'  => '#475569',
        'indigo' => '#4f46e5',
        'lime'   => '#65a30d',
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $this->prune($dry);

        if ($this->option('generate')) {
            $this->generate($dry);
        }

        return self::SUCCESS;
    }

    private function prune(bool $dry): void
    {
        $broken = MapIcon::orderBy('id')->get()->filter(function (MapIcon $icon) {
            return ! is_file(public_path($icon->path));
        });

        if ($broken->isEmpty()) {
            $this->info('Todos los iconos tienen su fichero.');
            return;
        }

        $fallback = MapIcon::orderBy('id')->get()->first(function (MapIcon $icon) {
            return is_file(public_path($icon->path));
        });

        foreach ($broken as $icon) {
            $pois = Poi::where('map_icon_id', $icon->id)->count();
            $this->line(sprintf('#%d %s: falta el fichero (%d POI)', $icon->id, $icon->path, $pois));

            if ($dry) {
                continue;
            }

            if ($pois && $fallback) {
                Poi::where('map_icon_id', $icon->id)->update(['map_icon_id' => $fallback->id]);
            }

            $icon->delete();
        }

        if (! $dry) {
            $this->info(sprintf('%d icono(s) retirado(s)%s.', $broken->count(), $fallback ? ", POIs reasignados a #{$fallback->id}" : ''));
        }
    }

    private function generate(bool $dry): void
    {
        if (! class_exists(\Imagick::class)) {
            $this->error('Imagick no está disponible: no se pueden generar los pines.');
            return;
        }

        $dir = public_path('images/map_icons');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $added = 0;

        foreach (self::PALETTE as $name => $hex) {
            $file = "v2_pin_{$name}.png";
            $path = "images/map_icons/{$file}";

            if (MapIcon::where('path', $path)->exists()) {
                continue;
            }

            $this->line("Generando {$path}");
            if ($dry) {
                continue;
            }

            $this->drawPin($dir . DIRECTORY_SEPARATOR . $file, $hex);

            MapIcon::create(['path' => $path, 'width' => 32, 'height' => 40]);
            $added++;
        }

        if (! $dry) {
            $this->info("{$added} pin(es) añadido(s) al catálogo.");
        }
    }

    /**
     * Pin estilo v2: gota redondeada del color, borde blanco fino y punto
     * blanco en el centro, 32x40 px con fondo transparente.
     */
    private function drawPin(string $file, string $hex): void
    {
        $w = 64; $h = 80; // se dibuja al doble y se reduce (bordes suaves)
        $img = new \Imagick();
        $img->newImage($w, $h, new \ImagickPixel('transparent'), 'png');

        $draw = new \ImagickDraw();
        $draw->setStrokeAntialias(true);

        // Cuerpo: círculo + triángulo hacia abajo (gota)
        $draw->setFillColor(new \ImagickPixel($hex));
        $draw->setStrokeColor(new \ImagickPixel('#ffffff'));
        $draw->setStrokeWidth(3);
        $draw->circle(32, 28, 32, 4);                 // radio 24
        $draw->polygon([
            ['x' => 11, 'y' => 40],
            ['x' => 53, 'y' => 40],
            ['x' => 32, 'y' => 76],
        ]);
        $img->drawImage($draw);

        // Tapar la unión círculo/triángulo con el mismo color (sin borde)
        $cover = new \ImagickDraw();
        $cover->setFillColor(new \ImagickPixel($hex));
        $cover->circle(32, 28, 32, 7);                 // radio 21
        $img->drawImage($cover);

        // Punto blanco
        $dot = new \ImagickDraw();
        $dot->setFillColor(new \ImagickPixel('#ffffff'));
        $dot->circle(32, 28, 32, 18);                  // radio 10
        $img->drawImage($dot);

        $img->resizeImage(32, 40, \Imagick::FILTER_LANCZOS, 1);
        $img->setImageFormat('png');
        $img->writeImage($file);
        $img->destroy();
    }
}
