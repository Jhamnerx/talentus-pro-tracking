<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWisetrackTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('wisetrack', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('device_id');
            $table->string('patente');
            $table->string('fecha_hora');
            $table->string('latitud');
            $table->string('longitud');
            $table->string('direccion');
            $table->integer('velocidad');
            $table->boolean('estado_registro')->default(1);
            $table->boolean('estado_ignicion')->default(1);
            $table->integer('numero_evento');
            $table->decimal('odometro', 8, 2);
            $table->string('imei');
            $table->string('transportista');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('wisetrack');
    }
}
