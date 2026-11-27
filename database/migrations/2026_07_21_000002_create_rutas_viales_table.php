<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nombres legibles de las rutas viales del Perú (join con hitos_km.ruta):
 * "PE-1N" → "Panamericana Norte". Se llama rutas_viales (no `rutas`) para
 * no confundir con la tabla `routes` de rutas de usuario.
 * Carga: storage/rutas_nombres.csv vía hitos:import (--names).
 */
return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('rutas_viales')) {
            return;
        }

        Schema::create('rutas_viales', function (Blueprint $table) {
            $table->string('ruta', 10)->primary();
            $table->string('nombre_comun', 60)->nullable();
            $table->string('trayecto_corto', 80)->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('rutas_viales');
    }
};
