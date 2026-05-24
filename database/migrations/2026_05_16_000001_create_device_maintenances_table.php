<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_maintenances', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('device_id');
            $table->foreign('device_id')->references('id')->on('devices')->onDelete('cascade');
            $table->enum('tipo', ['mantenimiento', 'suspension', 'reactivacion'])->default('mantenimiento');
            $table->date('fecha');
            $table->string('motivo', 500)->nullable();
            $table->text('notas')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            // Fecha en que fue sincronizado con Talentus
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'tipo', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_maintenances');
    }
};
