<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canales de video a bordo por vehiculo.
 *
 * Hasta ahora el video solo dependia del protocolo, asi que el widget "Video en
 * vivo" salia en TODOS los equipos aunque ninguno tuviera camaras. Este campo
 * es el interruptor explicito: 0 = el vehiculo no lleva video; 1..6 = cuantos
 * canales tiene, que es lo que se pinta en el visor.
 *
 * El tope de 6 es del equipo (los DVR de flota habituales llegan a 4 u 8, y 6
 * es lo que se ha fijado como maximo soportado aqui); se valida ademas en el
 * formulario y en el servicio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->unsignedTinyInteger('video_channels')->default(0)->after('device_model');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('video_channels');
        });
    }
};
