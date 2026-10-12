<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tipo de operacion: 'haul' (acarreo: carga/descarga) o 'route' (ruta:
 * inicio/fin). La maquina de estados es la misma; el tipo decide que
 * categorias de geocerca se ofrecen como puntos y como se rotulan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('operations', 'kind')) {
            return;
        }

        Schema::table('operations', function (Blueprint $table) {
            $table->string('kind', 20)->default('haul')->after('name');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('operations', 'kind')) {
            return;
        }

        Schema::table('operations', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
