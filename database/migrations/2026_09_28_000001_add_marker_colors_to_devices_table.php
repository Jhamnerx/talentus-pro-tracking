<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Color del marcador en el mapa segun la velocidad — propiedad del vehiculo.
 *
 * Mismo patron que los colores del recorrido (route_color_*): se activa por
 * unidad y, si no se define nada, valen los rangos por defecto de
 * config('tobuli.marker_speed_colors').
 *
 *  - marker_colors_enabled     : el interruptor por unidad.
 *  - marker_speed_ranges       : JSON [{from,to,color}, …]; null = por defecto.
 *  - marker_geofence_margin    : km/h por debajo del limite de la geocerca a
 *                                partir de los cuales se avisa. null = por defecto.
 *  - marker_geofence_near_color: color al acercarse al limite.
 *  - marker_geofence_over_color: color al superarlo.
 *
 * Los dos ultimos solo aplican DENTRO de una geocerca con limite; fuera de
 * ella mandan los rangos de velocidad normales.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->boolean('marker_colors_enabled')->default(0)->after('route_sensor_id');
            $table->text('marker_speed_ranges')->nullable()->after('marker_colors_enabled');
            $table->smallInteger('marker_geofence_margin')->nullable()->after('marker_speed_ranges');
            $table->string('marker_geofence_near_color', 10)->nullable()->after('marker_geofence_margin');
            $table->string('marker_geofence_over_color', 10)->nullable()->after('marker_geofence_near_color');
        });
    }

    public function down()
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn([
                'marker_colors_enabled',
                'marker_speed_ranges',
                'marker_geofence_margin',
                'marker_geofence_near_color',
                'marker_geofence_over_color',
            ]);
        });
    }
};
