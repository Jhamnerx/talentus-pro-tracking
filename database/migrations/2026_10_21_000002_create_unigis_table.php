<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cola de posiciones pendientes de enviar a UNIGIS (SOAP LoginYInsertarEvento).
 * Cada fila se borra una vez que UNIGIS responde (positivo, cero o negativo);
 * solo se conserva cuando falla el transporte, para reintentar.
 * dateTime (no timestamp) por compatibilidad con MariaDB 10.5 en produccion.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('unigis')) {
            return;
        }

        Schema::create('unigis', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('device_id');
            $table->string('dominio', 20);                 // placa normalizada (sin guiones ni espacios)
            $table->string('codigo', 20);                  // codigo de evento UNIGIS (POS, IGN_ON, ...)
            $table->decimal('latitud', 10, 7);
            $table->decimal('longitud', 10, 7);
            $table->integer('altitud')->default(0);
            $table->unsignedSmallInteger('velocidad')->default(0); // km/h
            $table->unsignedSmallInteger('rumbo')->default(0);     // 0-359
            $table->dateTime('fecha_hora_evento');         // UTC, device_time
            $table->dateTime('fecha_hora_recepcion');      // UTC, server_time
            $table->text('crudo')->nullable();             // diagnostico (event/ignition/motion/io)
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index('device_id', 'unigis_device_id_index');
            $table->index('created_at', 'unigis_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unigis');
    }
};
