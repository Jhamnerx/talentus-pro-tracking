<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de uso en el widget de bloqueo de motor para las plantillas de comando:
 *   lock_action = 'block'   → comando de CORTAR (bloquear)
 *   lock_action = 'unblock' → comando de RESTABLECER (desbloquear)
 *   null                    → plantilla normal (sin uso en el widget)
 * El widget elige la plantilla adaptada al TIPO de dispositivo (device_type),
 * porque modelos de distinta marca pueden compartir protocolo.
 */
return new class extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('command_templates', 'lock_action')) {
            return;
        }

        Schema::table('command_templates', function (Blueprint $table) {
            $table->string('lock_action', 16)->nullable()->after('adapted');
        });
    }

    public function down()
    {
        if (! Schema::hasColumn('command_templates', 'lock_action')) {
            return;
        }

        Schema::table('command_templates', function (Blueprint $table) {
            $table->dropColumn('lock_action');
        });
    }
};
