<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bandera por dispositivo para la integracion Securitas GlobalAVL.
 * Cuando esta en 1, PositionsWriter encola cada posicion en la tabla `securitas`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('devices', 'securitas')) {
            Schema::table('devices', function (Blueprint $table) {
                $table->boolean('securitas')->default(false)->after('unigis');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('devices', 'securitas')) {
            Schema::table('devices', function (Blueprint $table) {
                $table->dropColumn('securitas');
            });
        }
    }
};
