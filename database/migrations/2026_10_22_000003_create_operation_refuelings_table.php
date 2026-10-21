<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operaciones · reporte de turno, fase B: abastecimientos detectados por
 * turno. Los entrega el pipeline de combustible (GroupFuelFilling) sobre el
 * rango del turno; el modulo solo los guarda y los enlaza con la parada con
 * motor apagado en la que ocurrieron (si la hay).
 *
 * Los niveles son el valor del sensor fuel_tank tal cual (los equipos del
 * cliente reportan % de tanque). Se borran y recrean con cada recalculo del
 * resumen (cascade por summary_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operation_refuelings')) {
            return;
        }

        Schema::create('operation_refuelings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('operation_id');
            $table->unsignedInteger('device_id');
            $table->unsignedBigInteger('summary_id')->nullable();
            $table->unsignedBigInteger('stop_id')->nullable();
            $table->unsignedInteger('sensor_id')->nullable();

            // UTC
            $table->dateTime('started_at');
            $table->dateTime('ended_at');

            $table->decimal('level_before_pct', 6, 2)->nullable();
            $table->decimal('level_after_pct', 6, 2)->nullable();
            $table->decimal('refuel_pct', 6, 2)->nullable();

            // true = ocurrio dentro de una parada con motor apagado
            $table->boolean('engine_off')->default(false);

            $table->dateTime('calculated_at');

            $table->foreign('operation_id')->references('id')->on('operations')->onDelete('cascade');
            $table->foreign('device_id')->references('id')->on('devices')->onDelete('cascade');
            $table->foreign('summary_id')->references('id')->on('operation_shift_summaries')->onDelete('cascade');
            $table->foreign('stop_id')->references('id')->on('operation_stops')->onDelete('set null');

            $table->index(['operation_id', 'device_id', 'started_at'], 'operation_refuelings_device_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_refuelings');
    }
};
