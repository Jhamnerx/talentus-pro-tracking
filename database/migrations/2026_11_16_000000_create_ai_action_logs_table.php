<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoria de acciones propuestas por AirBot (crear alerta, enviar comando...).
 * Una fila por accion confirmada o cancelada por el usuario.
 */
class CreateAiActionLogsTable extends Migration
{
    public function up()
    {
        Schema::create('ai_action_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->index();
            $table->unsignedInteger('conversation_id')->nullable()->index();
            $table->string('action', 64);
            $table->string('title', 255);
            $table->text('params')->nullable();
            $table->string('status', 16); // done | failed | cancelled
            $table->text('result')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down()
    {
        Schema::dropIfExists('ai_action_logs');
    }
}
