<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-send when online: cola de comandos GPRS para dispositivos
 * desconectados; commands:dispatch-pending los envía al reconectar.
 */
return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('pending_commands')) {
            return;
        }

        Schema::create('pending_commands', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('device_id');
            $table->string('connection', 10)->default('gprs');
            $table->string('command', 100);
            $table->text('parameters')->nullable();
            $table->string('status', 20)->default('pending'); // pending|sent|failed|expired
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'device_id']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('device_id')->references('id')->on('devices')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('pending_commands');
    }
};
