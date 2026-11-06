<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cola de eventos pendientes de enviar a Securitas GlobalAVL (POST JSON por lotes).
 * `payload` guarda el evento ya armado (formato GlobalAVL v2.0); el job solo lo
 * agrupa en {"version": "2.0", "events": [...]}. Cada fila se borra en cuanto
 * GlobalAVL responde 200 (no deduplican: no se debe reenviar lo ya aceptado).
 * dateTime (no timestamp) por compatibilidad con MariaDB 10.5 en produccion.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('securitas')) {
            return;
        }

        Schema::create('securitas', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('device_id');
            $table->unsignedSmallInteger('event_type')->default(0); // tipo de evento GlobalAVL
            $table->dateTime('event_time');                           // UTC, device_time
            $table->text('payload');                                  // JSON del evento
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index('device_id', 'securitas_device_id_index');
            $table->index('created_at', 'securitas_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('securitas');
    }
};
