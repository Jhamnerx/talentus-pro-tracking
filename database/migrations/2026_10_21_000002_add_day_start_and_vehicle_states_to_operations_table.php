<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operaciones · reporte de turno, fase A.
 *
 * - day_start: hora local a la que empieza el dia operativo (06:30 -> 06:30
 *   del dia siguiente). Nulo = inicio del primer turno configurado.
 * - vehicle_type_id / loaded_state_id / empty_state_id: tipo de vehiculo del
 *   catalogo del usuario (Seguridad) y que estado corresponde a "cargado" y a
 *   "vacio", para que OperationChecker cambie el estado solo. Opcionales.
 *
 * vehicle_types y vehicle_states se crearon con id() (BIGINT UNSIGNED), asi
 * que las FK van como unsignedBigInteger. SET NULL: borrar el tipo o el
 * estado en Seguridad no borra la operacion, solo apaga el automatismo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('operations', 'day_start')) {
            return;
        }

        Schema::table('operations', function (Blueprint $table) {
            $table->time('day_start')->nullable()->after('max_leg_hours');
            $table->unsignedBigInteger('vehicle_type_id')->nullable()->after('day_start');
            $table->unsignedBigInteger('loaded_state_id')->nullable()->after('vehicle_type_id');
            $table->unsignedBigInteger('empty_state_id')->nullable()->after('loaded_state_id');

            $table->foreign('vehicle_type_id')->references('id')->on('vehicle_types')->onDelete('set null');
            $table->foreign('loaded_state_id')->references('id')->on('vehicle_states')->onDelete('set null');
            $table->foreign('empty_state_id')->references('id')->on('vehicle_states')->onDelete('set null');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('operations', 'day_start')) {
            return;
        }

        Schema::table('operations', function (Blueprint $table) {
            $table->dropForeign(['vehicle_type_id']);
            $table->dropForeign(['loaded_state_id']);
            $table->dropForeign(['empty_state_id']);
            $table->dropColumn(['day_start', 'vehicle_type_id', 'loaded_state_id', 'empty_state_id']);
        });
    }
};
