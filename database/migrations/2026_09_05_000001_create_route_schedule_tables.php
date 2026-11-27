<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rutas con puntos de control, programaciones y adherencia.
 *
 * Los puntos de control son geocercas: no se inventa una geometria nueva ni
 * un editor nuevo, se reutiliza lo que el cliente ya dibuja y ya sabe usar.
 *
 * Una programacion es una vuelta diaria de una ruta para unos vehiculos, con
 * hora de salida de ida y (opcional) de vuelta. El cumplimiento no se guarda:
 * se calcula al vuelo desde las posiciones cuando se pide el reporte, igual
 * que el resto de reportes, para no tocar la ruta caliente de ingesta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('route_id');
            $table->unsignedInteger('geofence_id');

            // Sentido de la vuelta al que pertenece el punto. Una misma
            // geocerca puede estar en los dos, con distinto orden y horario.
            $table->enum('direction', ['outbound', 'inbound'])->default('outbound');

            $table->unsignedSmallInteger('position')->default(0);

            // Etiqueta opcional; si va vacia se muestra el nombre de la geocerca.
            $table->string('name')->nullable();

            // Minutos previstos desde la salida de la vuelta.
            $table->unsignedSmallInteger('planned_offset')->default(0);

            // Tolerancias propias del punto. Nulas = se usan las de la
            // programacion, que es el caso normal.
            $table->unsignedSmallInteger('tolerance_early')->nullable();
            $table->unsignedSmallInteger('tolerance_late')->nullable();

            $table->timestamps();

            $table->foreign('route_id')->references('id')->on('routes')->onDelete('cascade');
            $table->foreign('geofence_id')->references('id')->on('geofences')->onDelete('cascade');

            $table->index(['route_id', 'direction', 'position'], 'route_checkpoints_route_direction_position_index');
            $table->index('geofence_id');
        });

        Schema::create('route_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('route_id');
            $table->string('name');

            // Dias en formato ISO-8601 (1 lunes ... 7 domingo), separados por
            // coma. Es el mismo criterio que usa el filtro de dias/horas de los
            // reportes, que compara contra date('N').
            $table->string('weekdays', 20)->default('1,2,3,4,5,6,7');

            $table->time('start_time');

            // Nula = la programacion es solo de ida.
            $table->time('return_start_time')->nullable();

            $table->unsignedSmallInteger('tolerance_early')->default(5);
            $table->unsignedSmallInteger('tolerance_late')->default(10);

            $table->boolean('active')->default(1);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('route_id')->references('id')->on('routes')->onDelete('cascade');

            $table->index(['user_id', 'active']);
            $table->index('route_id');
        });

        Schema::create('device_route_schedule', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('route_schedule_id');
            $table->unsignedInteger('device_id');

            $table->foreign('route_schedule_id', 'device_route_schedule_schedule_fk')
                ->references('id')->on('route_schedules')->onDelete('cascade');
            $table->foreign('device_id')->references('id')->on('devices')->onDelete('cascade');

            $table->unique(['route_schedule_id', 'device_id'], 'device_route_schedule_unique');
            $table->index('device_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_route_schedule');
        Schema::dropIfExists('route_schedules');
        Schema::dropIfExists('route_checkpoints');
    }
};
