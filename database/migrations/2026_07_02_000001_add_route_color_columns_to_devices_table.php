<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colores del recorrido (historial) como PROPIEDAD del vehículo, estilo
 * Wialon: by trips | single | by speed | by sensor.
 *  - route_color_type: modo activo.
 *  - route_color: hex para el modo "single".
 *  - route_speed_ranges: JSON [{from,to,color}, …] para "speed".
 *  - route_sensor_id: sensor con rangos de color (modo "sensor", futuro).
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('route_color_type', 10)->default('trips')->after('tail_color');
            $table->string('route_color', 10)->nullable()->after('route_color_type');
            $table->text('route_speed_ranges')->nullable()->after('route_color');
            $table->integer('route_sensor_id')->nullable()->after('route_speed_ranges');
        });
    }

    public function down()
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['route_color_type', 'route_color', 'route_speed_ranges', 'route_sensor_id']);
        });
    }
};
