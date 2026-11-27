<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operaciones · reporte de turno, fase B: estadisticas por hora local.
 *
 * Una fila por operacion, vehiculo y hora (`hour_at` = hora local del dueño
 * con minuto 0, guardada tal cual, NO en UTC) y una fila de flota por hora
 * con `device_id` NULL que agrega las de los vehiculos.
 *
 * `max_speed_at` / `min_speed_at` si van en UTC (son instantes exactos que
 * luego abren el historial). Velocidades en la unidad base del sistema (km/h).
 *
 * Sobre el unico (operation_id, device_id, hour_at): con `device_id` NULL
 * MySQL/MariaDB no lo hacen cumplir (varios NULL conviven), asi que la fila
 * de flota se escribe siempre con updateOrCreate (where device_id IS NULL) y
 * nunca por insert directo. Se opto por NULL y no por 0 porque la entidad y
 * el plan hablan de "device_id nulo = flota" y un 0 obligaria a saltarse la
 * FK a devices.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operation_hour_stats')) {
            return;
        }

        Schema::create('operation_hour_stats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('operation_id');
            // NULL = toda la flota
            $table->unsignedInteger('device_id')->nullable();
            // Hora local del dueño, minuto 0
            $table->dateTime('hour_at');

            $table->unsignedInteger('moving_seconds')->default(0);
            $table->unsignedInteger('loaded_distance_m')->default(0);
            $table->unsignedInteger('loaded_moving_s')->default(0);
            $table->unsignedInteger('empty_distance_m')->default(0);
            $table->unsignedInteger('empty_moving_s')->default(0);

            $table->decimal('max_speed', 6, 1)->nullable();
            $table->decimal('min_speed', 6, 1)->nullable();
            $table->decimal('avg_speed', 6, 1)->nullable();
            $table->unsignedInteger('max_speed_device_id')->nullable();
            $table->unsignedInteger('min_speed_device_id')->nullable();
            $table->dateTime('max_speed_at')->nullable();
            $table->dateTime('min_speed_at')->nullable();

            $table->dateTime('calculated_at');

            $table->foreign('operation_id')->references('id')->on('operations')->onDelete('cascade');
            $table->foreign('device_id')->references('id')->on('devices')->onDelete('cascade');

            $table->index(['operation_id', 'hour_at'], 'operation_hour_stats_hour_index');
            $table->unique(['operation_id', 'device_id', 'hour_at'], 'operation_hour_stats_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_hour_stats');
    }
};
