<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rediseño del catálogo de iconos de POI (oct-2026).
 *
 * Los 103 iconos POI_NN.png se regeneraron con un estilo moderno (círculo de
 * color + glifo blanco, 64 px) y se movieron a images/map_icons/poi/. El
 * cambio de ruta es deliberado: el servidor sirve las imágenes con un mes de
 * caché, así que reescribir los ficheros con el mismo nombre dejaba a los
 * navegadores mostrando los iconos viejos. Los POI existentes conservan su
 * map_icon_id; solo cambia el path de la fila.
 */
return new class extends Migration
{
    private const OLD = 'images/map_icons/POI_';
    private const NEW = 'images/map_icons/poi/POI_';

    public function up(): void
    {
        DB::table('map_icons')
            ->where('path', 'like', self::OLD . '%')
            ->update(['path' => DB::raw("REPLACE(path, '" . self::OLD . "', '" . self::NEW . "')")]);
    }

    public function down(): void
    {
        DB::table('map_icons')
            ->where('path', 'like', self::NEW . '%')
            ->update(['path' => DB::raw("REPLACE(path, '" . self::NEW . "', '" . self::OLD . "')")]);
    }
};
