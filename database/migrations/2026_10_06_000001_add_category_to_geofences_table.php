<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uso de la geocerca dentro de una operacion de acarreo: carga, descarga,
 * grifo, taller o descanso (ver Geofence::CATEGORIES).
 *
 * Va en columna propia y no dentro de `additional`: ese JSON se reemplaza
 * entero cada vez que el formulario legacy o el modal de la v2 lo envian, asi
 * que cualquier cosa guardada ahi se perderia al editar; y una columna TEXT
 * no se puede indexar para filtrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('geofences', 'category')) {
            return;
        }

        Schema::table('geofences', function (Blueprint $table) {
            $table->string('category', 20)->nullable()->after('type');
            $table->index('category');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('geofences', 'category')) {
            return;
        }

        Schema::table('geofences', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropColumn('category');
        });
    }
};
