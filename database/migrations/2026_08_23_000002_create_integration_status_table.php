<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resumen del estado de reenvío por vehículo e integración.
 *
 * Las tablas de reenvío son COLAS: la fila se borra al enviarse, y ninguna
 * (salvo mininter) guarda estado. Asi que el cumplimiento se deduce del atraso
 * de la cola, y eso son agregaciones sobre tablas que llegan a cientos de miles
 * de filas.
 *
 * Por eso se precalculan aqui con un comando periodico: el panel LEE este
 * resumen y nunca consulta las colas, para no competir con los jobs de envio.
 */
return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('integration_status')) {
            return;
        }

        Schema::create('integration_status', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('device_id');
            $table->string('integration', 32);

            // Filas esperando en la cola
            $table->unsignedInteger('pending')->default(0);
            // La mas antigua sin enviar: es la señal de "atascado"
            $table->dateTime('oldest_at')->nullable();
            // La ultima encolada: si es muy vieja, el equipo dejo de reportar
            $table->dateTime('last_at')->nullable();

            $table->dateTime('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'integration'], 'integration_status_device_integration');
            $table->index('integration');
        });
    }

    public function down()
    {
        Schema::dropIfExists('integration_status');
    }
};
