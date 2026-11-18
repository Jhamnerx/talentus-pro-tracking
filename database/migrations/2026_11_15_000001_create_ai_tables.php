<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAiTables extends Migration
{
    public function up()
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->index();
            $table->string('title', 255)->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        // Cada fila es un mensaje en formato de la API de Claude (content = JSON).
        // "display" guarda lo que se muestra en el panel (texto, fuentes, botones).
        Schema::create('ai_messages', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('conversation_id')->index();
            $table->string('role', 16); // user | assistant
            $table->longText('content');
            $table->text('display')->nullable();
            $table->boolean('visible')->default(true); // false = resultados de herramientas
            $table->timestamp('created_at')->nullable();

            $table->foreign('conversation_id')->references('id')->on('ai_conversations')->onDelete('cascade');
        });

        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->index();
            $table->unsignedInteger('conversation_id')->nullable()->index();
            $table->string('model', 64);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedSmallInteger('api_calls')->default(0);
            $table->decimal('cost_usd', 10, 5)->default(0);
            $table->text('tools')->nullable();
            $table->string('status', 32)->default('ok'); // ok | error | refusal
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down()
    {
        Schema::dropIfExists('ai_usage_logs');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
    }
}
