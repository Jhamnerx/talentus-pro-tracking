<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operaciones · reporte de turno, fase A: marca de estado en el motivo de
 * parada. Una parada justificada con un motivo `maint` cuenta como
 * mantenimiento (MAINT) aunque no haya geocerca de taller; con `standby`
 * cuenta como descanso. Nulo = no cambia el estado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('operation_stop_reasons', 'state')) {
            return;
        }

        Schema::table('operation_stop_reasons', function (Blueprint $table) {
            // null | maint | standby
            $table->string('state', 16)->nullable()->after('authorizes');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('operation_stop_reasons', 'state')) {
            return;
        }

        Schema::table('operation_stop_reasons', function (Blueprint $table) {
            $table->dropColumn('state');
        });
    }
};
