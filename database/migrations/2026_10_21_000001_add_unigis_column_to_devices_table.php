<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bandera por dispositivo para la integracion UNIGIS (Pacasmayo / Geojavar).
 * Cuando esta en 1, PositionsWriter encola cada posicion en la tabla `unigis`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('devices', 'unigis')) {
            Schema::table('devices', function (Blueprint $table) {
                $table->boolean('unigis')->default(false)->after('epscenter');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('devices', 'unigis')) {
            Schema::table('devices', function (Blueprint $table) {
                $table->dropColumn('unigis');
            });
        }
    }
};
