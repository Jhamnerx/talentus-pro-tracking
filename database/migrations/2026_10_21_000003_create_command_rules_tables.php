<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Normas de comando: consulta periodica a vehiculos -> evaluacion de la
 * respuesta -> comando de accion. Reemplaza el caso DOUT1 hardcodeado
 * (SendGetioProbe + AutoDisableDoutListener).
 * dateTime (no timestamp) por MariaDB 10.5 en produccion.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('command_rules')) {
            Schema::create('command_rules', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('user_id');                       // admin dueño: con su usuario se envian los comandos
                $table->string('name', 100);
                $table->string('description', 255)->nullable();
                $table->boolean('active')->default(true);
                $table->string('query_command', 255);                     // ej. getio
                $table->unsignedInteger('interval_minutes')->default(5);
                $table->boolean('only_connected')->default(true);         // solo equipos con GPRS abierto
                $table->boolean('evaluate_any_response')->default(false); // false = solo respuestas tras la consulta
                $table->unsignedInteger('cooldown_minutes')->default(0);  // no repetir la accion en N min
                $table->dateTime('last_run_at')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();

                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->index(['active', 'last_run_at'], 'command_rules_active_last_run_index');
            });
        }

        if (!Schema::hasTable('command_rule_devices')) {
            Schema::create('command_rule_devices', function (Blueprint $table) {
                $table->unsignedBigInteger('command_rule_id');
                $table->unsignedInteger('device_id');

                $table->primary(['command_rule_id', 'device_id'], 'command_rule_devices_primary');
                $table->index('device_id', 'command_rule_devices_device_index');
                $table->foreign('command_rule_id')->references('id')->on('command_rules')->onDelete('cascade');
                $table->foreign('device_id')->references('id')->on('devices')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('command_rule_conditions')) {
            Schema::create('command_rule_conditions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('command_rule_id');
                $table->unsignedSmallInteger('position')->default(0);
                $table->string('match_type', 20);                // contains | regex | compare
                $table->string('pattern', 255)->nullable();      // contains / regex
                $table->string('field', 50)->nullable();         // compare: clave en la respuesta (AIN1)
                $table->string('operator', 2)->nullable();       // compare: = != > >= < <=
                $table->string('value', 50)->nullable();         // compare: valor numerico
                $table->string('action_command', 255);           // ej. setdigout 0
                $table->boolean('stop_on_match')->default(true);
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();

                $table->index(['command_rule_id', 'position'], 'command_rule_conditions_rule_pos_index');
                $table->foreign('command_rule_id')->references('id')->on('command_rules')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('command_rule_logs')) {
            Schema::create('command_rule_logs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('command_rule_id');
                $table->unsignedBigInteger('command_rule_condition_id')->nullable();
                $table->unsignedInteger('device_id');
                $table->string('phase', 30);                     // probe_sent, probe_failed, probe_skipped, response, matched, action_sent, action_failed
                $table->text('message')->nullable();             // respuesta cruda, comando enviado o error
                $table->dateTime('created_at')->nullable();

                $table->index(['command_rule_id', 'created_at'], 'command_rule_logs_rule_created_index');
                $table->index(['device_id', 'created_at'], 'command_rule_logs_device_created_index');
                $table->foreign('command_rule_id')->references('id')->on('command_rules')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('command_rule_logs');
        Schema::dropIfExists('command_rule_conditions');
        Schema::dropIfExists('command_rule_devices');
        Schema::dropIfExists('command_rules');
    }
};
