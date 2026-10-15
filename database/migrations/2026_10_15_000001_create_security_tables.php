<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        // Ajustes por usuario: turno, limite manual, vigencia.
        Schema::create('security_settings', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->unique();
            $table->string('day_start', 5)->default('07:00');   // HH:MM local
            $table->string('day_end', 5)->default('19:00');     // la noche es el complemento
            $table->unsignedInteger('speed_limit')->nullable();  // km/h fuera de geocercas; null = no contar
            $table->unsignedInteger('validity_minutes')->default(10);
            $table->unsignedInteger('stop_minutes')->default(1);
            $table->unsignedInteger('gap_minutes')->default(10);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        // Un registro por unidad, dia local y turno. Es la hoja "Datos" del Excel.
        Schema::create('security_daily_stats', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('device_id');
            $table->date('work_date');                 // dia local al que se atribuye el turno
            $table->string('shift', 5);                // day | night
            $table->dateTime('range_from');            // UTC
            $table->dateTime('range_to');              // UTC
            $table->decimal('distance_km', 10, 3)->default(0);
            $table->unsignedInteger('engine_seconds')->default(0);
            $table->unsignedInteger('moving_seconds')->default(0);
            $table->unsignedInteger('stop_count')->default(0);
            $table->decimal('speed_max', 6, 1)->nullable();      // km/h GPS
            $table->unsignedInteger('overspeed_events')->default(0); // de la tabla events
            $table->unsignedInteger('overspeed_calc')->default(0);   // tramos calculados
            $table->unsignedInteger('overspeed_total')->default(0);  // combinados sin duplicar
            $table->unsignedInteger('zone_exits')->default(0);
            $table->unsignedInteger('disconnect_events')->default(0);
            $table->unsignedInteger('signal_gaps')->default(0);
            $table->unsignedInteger('seatbelt_alerts')->nullable(); // null = sin sensor
            $table->unsignedInteger('positions')->default(0);      // "Registros"
            $table->dateTime('calculated_at');
            $table->unique(['device_id', 'work_date', 'shift']);
            $table->index(['user_id', 'work_date']);
            $table->foreign('device_id')->references('id')->on('devices')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('security_daily_stats');
        Schema::dropIfExists('security_settings');
    }
};
