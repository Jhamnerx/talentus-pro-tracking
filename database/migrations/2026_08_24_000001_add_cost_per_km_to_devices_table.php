<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Costo por kilómetro del vehículo.
 *
 * Es el costo ESTIMADO que el cliente ya tiene calculado para su flota
 * (combustible + mantenimiento + neumáticos…), y sirve para valorar un
 * recorrido cuando todavía no hay gastos cargados. El costo REAL sale de
 * Gastos (device_expenses); los dos conviven y el reporte los distingue,
 * porque uno es un supuesto y el otro es dinero gastado.
 *
 * Va junto a fuel_price, con su misma precisión de 4 decimales: hay flotas
 * que lo llevan en céntimos por kilómetro.
 */
return new class extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('devices', 'cost_per_km')) {
            return;
        }

        Schema::table('devices', function (Blueprint $table) {
            $table->decimal('cost_per_km', 10, 4)->nullable()->after('fuel_price');
        });
    }

    public function down()
    {
        if (! Schema::hasColumn('devices', 'cost_per_km')) {
            return;
        }

        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('cost_per_km');
        });
    }
};
