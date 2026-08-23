<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plantillas de alerta.
 *
 * Nacen de un problema medible: 773 usuarios repiten media docena de alertas
 * ("Vehículo apagado" 807 veces, "Exceso de velocidad" 796...), creadas a mano
 * entrando como cada cliente.
 *
 * Guarda el CONTENIDO de una alerta sin dueño ni vehículos. Al dar de alta un
 * usuario se le crean las alertas de las plantillas que le tocan, y a partir de
 * ahí son suyas: lo que edite después no se toca ni se sobreescribe.
 */
return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('alert_templates')) {
            return;
        }

        Schema::create('alert_templates', function (Blueprint $table) {
            $table->increments('id');
            $table->boolean('active')->default(true);

            $table->string('name');
            $table->string('type', 64)->index();

            // Mismo formato que la alerta, para poder copiarlo tal cual
            $table->text('data')->nullable();
            $table->text('notifications')->nullable();
            $table->text('schedules')->nullable();

            // Los vehículos no se listan: la alerta se crea con
            // for_all_user_devices, así que los que se asignen despues al
            // usuario entran solos.
            $table->boolean('for_all_user_devices')->default(true);

            // Null = se aplica a los usuarios de cualquier plan
            $table->unsignedInteger('billing_plan_id')->nullable()->index();

            // Eventos personalizados GLOBALES a los que apunta (tipo custom).
            // Solo se copian los del admin (user_id null): los de un cliente
            // no existen en los demas.
            $table->text('events_custom')->nullable();

            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('alert_templates');
    }
};
