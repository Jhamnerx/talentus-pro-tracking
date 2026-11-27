<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Importa los hitos kilométricos del Perú desde el CSV de Provías
 * (storage/hitos_km_peru_2025.csv). Idempotente POR VERSIÓN: borra la
 * versión indicada y la recarga, así la actualización semestral es
 * `hitos:import --file=... --version=2026-01` sin downtime.
 */
class ImportHitosKmCommand extends Command
{
    // OJO: --version choca con la opción global de artisan → --dataset
    protected $signature = 'hitos:import
        {--file=storage/hitos_km_peru_2025.csv : Ruta del CSV (relativa al proyecto o absoluta)}
        {--names=storage/rutas_nombres.csv : CSV de nombres legibles de las vías (rutas_viales)}
        {--dataset=2025-07 : Versión del dataset (semestre)}';

    protected $description = 'Import Peru road KM markers (hitos) from the Provías CSV';

    public function handle()
    {
        $file = $this->option('file');
        if (! is_file($file)) {
            $file = base_path($this->option('file'));
        }
        if (! is_file($file)) {
            $this->error('CSV no encontrado: ' . $this->option('file'));
            return 1;
        }

        $version = (string) $this->option('dataset');
        $fh = fopen($file, 'r');
        if (! $fh) {
            $this->error('No se pudo abrir el CSV.');
            return 1;
        }

        // Cabecera esperada: ruta,km,lat,lon,departamento,estado,superficie
        fgetcsv($fh);

        DB::table('hitos_km')->where('version', $version)->delete();

        $batch = [];
        $count = 0;
        $skipped = 0;

        while (($row = fgetcsv($fh)) !== false) {
            if (count($row) < 4 || $row[0] === '' || ! is_numeric($row[1]) || ! is_numeric($row[2]) || ! is_numeric($row[3])) {
                $skipped++;
                continue;
            }

            $batch[] = [
                'ruta'         => mb_substr(trim($row[0]), 0, 10),
                'km'           => (int) $row[1],
                'lat'          => (float) $row[2],
                'lon'          => (float) $row[3],
                'departamento' => isset($row[4]) && $row[4] !== '' ? mb_substr(trim($row[4]), 0, 40) : null,
                'estado'       => isset($row[5]) && $row[5] !== '' ? mb_substr(trim($row[5]), 0, 20) : null,
                'superficie'   => isset($row[6]) && $row[6] !== '' ? mb_substr(trim($row[6]), 0, 20) : null,
                'version'      => $version,
            ];

            if (count($batch) >= 1000) {
                DB::table('hitos_km')->insert($batch);
                $count += count($batch);
                $batch = [];
            }
        }
        fclose($fh);

        if ($batch) {
            DB::table('hitos_km')->insert($batch);
            $count += count($batch);
        }

        $this->info("Hitos importados: {$count} (versión {$version}" . ($skipped ? ", {$skipped} filas omitidas" : '') . ')');

        $this->importNames();

        return 0;
    }

    /** Nombres legibles de las vías (rutas_viales) — reemplazo completo. */
    protected function importNames(): void
    {
        $file = $this->option('names');
        if (! is_file($file)) {
            $file = base_path($this->option('names'));
        }
        if (! is_file($file)) {
            $this->warn('CSV de nombres no encontrado (' . $this->option('names') . ') — se mantienen los actuales.');
            return;
        }

        $fh = fopen($file, 'r');
        if (! $fh) {
            return;
        }

        // Cabecera esperada: ruta,nombre_comun,trayecto_corto
        fgetcsv($fh);

        DB::table('rutas_viales')->delete();

        $rows = [];
        while (($r = fgetcsv($fh)) !== false) {
            if (! isset($r[0]) || trim($r[0]) === '') {
                continue;
            }
            $ruta = mb_substr(trim($r[0]), 0, 10);
            // Normalizar Unicode (Ñ compuesta vs N+tilde): claves PHP distintas
            // que MySQL colapsa en el PK → duplicado. NFC las unifica.
            if (class_exists('Normalizer')) {
                $ruta = \Normalizer::normalize($ruta, \Normalizer::FORM_C) ?: $ruta;
            }
            // El CSV trae algún código repetido: dedupe (gana la última fila)
            $rows[$ruta] = [
                'ruta'           => $ruta,
                'nombre_comun'   => isset($r[1]) && trim($r[1]) !== '' ? mb_substr(trim($r[1]), 0, 60) : null,
                'trayecto_corto' => isset($r[2]) && trim($r[2]) !== '' ? mb_substr(trim($r[2]), 0, 80) : null,
            ];
        }
        fclose($fh);
        $rows = array_values($rows);

        foreach (array_chunk($rows, 500) as $chunk) {
            // insertOrIgnore: por si la collation de MySQL colapsa variantes
            // que la normalización PHP no unificó.
            DB::table('rutas_viales')->insertOrIgnore($chunk);
        }

        $this->info('Nombres de vías importados: ' . count($rows));
    }
}
