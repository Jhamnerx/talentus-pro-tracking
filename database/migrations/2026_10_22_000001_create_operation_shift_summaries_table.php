<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operaciones · reporte de turno, fase B: resumen por operacion, vehiculo y
 * turno-fecha. Lo escribe OperationShiftSummaryService al cerrar cada turno
 * (operations:process) y en los recalculos; la pantalla y el Excel solo leen.
 *
 * Fechas-hora en UTC (dateTime, no timestamp); `shift_date` es la fecha local
 * en que empezo el turno. Horometro y odometro son el valor crudo del sensor
 * (NULL sin sensor o sin tramas).
 *
 * El unico (operation_id, device_id, shift_date, shift_id) incluye un
 * `shift_id` nullable (set null al borrar el turno): MySQL/MariaDB permiten
 * varias filas con NULL en un unico, asi que para turnos borrados no hay
 * garantia de unicidad; el servicio siempre escribe con updateOrCreate y
 * siempre con un turno existente, de modo que en la practica no se duplica.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operation_shift_summaries')) {
            return;
        }

        Schema::create('operation_shift_summaries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('operation_id');
            $table->unsignedInteger('device_id');
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->string('shift_name', 60);
            $table->date('shift_date');

            // Primera y ultima trama en movimiento del turno (UTC).
            $table->dateTime('first_move_at')->nullable();
            $table->dateTime('last_move_at')->nullable();

            // Descanso principal (primer descanso programado del turno), UTC.
            $table->dateTime('break_start_at')->nullable();
            $table->dateTime('break_end_at')->nullable();
            // scheduled | none
            $table->string('break_source', 12)->nullable();

            // Horometro (sensor engine_hours, valor crudo en horas).
            $table->decimal('engine_hours_start', 12, 2)->nullable();
            $table->decimal('engine_hours_before_break', 12, 2)->nullable();
            $table->decimal('engine_hours_after_break', 12, 2)->nullable();
            $table->decimal('engine_hours_end', 12, 2)->nullable();

            // Odometro (sensor odometer, valor crudo).
            $table->decimal('odometer_start', 14, 3)->nullable();
            $table->decimal('odometer_end', 14, 3)->nullable();

            // Tiempos de estado dentro del turno, en segundos.
            $table->unsignedInteger('moving_seconds')->default(0);
            $table->unsignedInteger('idle_seconds')->default(0);
            $table->unsignedInteger('stop_engine_off_seconds')->default(0);
            $table->unsignedInteger('break_seconds')->default(0);
            $table->unsignedInteger('gap_seconds')->default(0);
            $table->unsignedInteger('workshop_seconds')->default(0);
            $table->unsignedInteger('rest_seconds')->default(0);
            $table->unsignedInteger('maint_seconds')->default(0);
            $table->unsignedInteger('load_seconds')->default(0);
            $table->unsignedInteger('unload_seconds')->default(0);

            $table->unsignedSmallInteger('cycles_count')->default(0);

            // Distancias en metros y tiempo en movimiento por sentido.
            $table->unsignedInteger('distance_meters')->default(0);
            $table->unsignedInteger('loaded_distance_m')->default(0);
            $table->unsignedInteger('loaded_moving_s')->default(0);
            $table->unsignedInteger('empty_distance_m')->default(0);
            $table->unsignedInteger('empty_moving_s')->default(0);

            $table->dateTime('calculated_at');

            $table->foreign('operation_id')->references('id')->on('operations')->onDelete('cascade');
            $table->foreign('device_id')->references('id')->on('devices')->onDelete('cascade');
            $table->foreign('shift_id')->references('id')->on('operation_shifts')->onDelete('set null');

            $table->unique(['operation_id', 'device_id', 'shift_date', 'shift_id'], 'operation_shift_summaries_unique');
            $table->index(['operation_id', 'shift_date'], 'operation_shift_summaries_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_shift_summaries');
    }
};
