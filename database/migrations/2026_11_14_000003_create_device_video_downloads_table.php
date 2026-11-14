<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Descargas de grabaciones JT1078 (portado de master-tracking, sus 2026_10_29_00000{1,3}):
 * - method = playback: el job reproduce la grabacion en el equipo y graba el stream;
 * - method = ftp: el equipo sube el archivo original por FTP (0x9206), upload_path es la carpeta de la tarea.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('device_video_downloads')) {
            return;
        }

        Schema::create('device_video_downloads', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('device_id')->index();
            $table->unsignedInteger('user_id')->index();
            $table->unsignedTinyInteger('channel');
            $table->string('method', 16)->default('playback');
            $table->string('upload_path')->nullable();
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->string('status', 16)->default('pending');
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('duration')->default(0);
            $table->string('error')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->foreign('device_id')->references('id')->on('devices')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_video_downloads');
    }
};
