<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hitos kilométricos de la red vial del Perú (Provías, ~31.7k puntos).
 *
 * DECISIÓN: sin columnas/índices espaciales (el plan original proponía
 * POINT SRID 4326 + SPATIAL INDEX, sintaxis solo-MySQL 8 que rompe en el
 * MariaDB 10.5 del dedicado). Con este volumen, lat/lon DECIMAL + BTREE
 * responde el bbox en milisegundos y es 100% portable.
 * Carga/actualización semestral: php artisan hitos:import (por versión).
 */
return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('hitos_km')) {
            return;
        }

        Schema::create('hitos_km', function (Blueprint $table) {
            $table->increments('id');
            $table->string('ruta', 10);
            $table->unsignedSmallInteger('km');
            $table->decimal('lat', 9, 6);
            $table->decimal('lon', 9, 6);
            $table->string('departamento', 40)->nullable();
            $table->string('estado', 20)->nullable();
            $table->string('superficie', 20)->nullable();
            $table->string('version', 10)->default('2025-07');

            $table->index(['lat', 'lon']);
            $table->index('km');
            $table->index(['ruta', 'km']);
            $table->index('version');
        });
    }

    public function down()
    {
        Schema::dropIfExists('hitos_km');
    }
};
