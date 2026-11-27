<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extensión de schedules de reportes (ui/v2):
 *  - weekly_day (ISO 1-7) / monthly_day (1-31): día concreto de envío.
 *    Null = comportamiento clásico por ventana (+7 días / mes calendario).
 *  - period_weekdays / period_time_from / period_time_to: persisten el
 *    filtro "Controlar días y horas" en la definición guardada, para que
 *    el cron reports:daily lo aplique (fromEntity → toArray → from()).
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->unsignedTinyInteger('weekly_day')->nullable()->after('weekly_time');
            $table->unsignedTinyInteger('monthly_day')->nullable()->after('monthly_time');
            $table->string('period_weekdays', 32)->nullable()->after('monthly_day');
            $table->string('period_time_from', 5)->nullable()->after('period_weekdays');
            $table->string('period_time_to', 5)->nullable()->after('period_time_from');
        });
    }

    public function down()
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn(['weekly_day', 'monthly_day', 'period_weekdays', 'period_time_from', 'period_time_to']);
        });
    }
};
