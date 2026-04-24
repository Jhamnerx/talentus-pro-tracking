<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEpscenterTableAndAddDeviceColumns extends Migration
{
    public function up()
    {
        Schema::create('epscenter', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('device_id')->index();
            $table->unsignedSmallInteger('id_empresa')->default(22);
            $table->unsignedInteger('id_transporte')->nullable();
            $table->string('telefono', 12)->nullable();
            $table->double('latitud', 15, 8)->nullable();
            $table->double('longitud', 15, 8)->nullable();
            $table->string('fecha', 10)->nullable();  // yyyy-mm-dd
            $table->string('hora', 8)->nullable();    // hh:mm:ss
            $table->decimal('velocidad', 6, 2)->nullable();
            $table->unsignedTinyInteger('bateria')->default(5); // 1-5
            $table->string('mensaje', 200)->default('Ok');
            $table->timestamps();

            $table->foreign('device_id')->references('id')->on('devices')->onDelete('cascade');
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->boolean('epscenter')->default(false)->after('sidersales');
        });
    }

    public function down()
    {
        Schema::dropIfExists('epscenter');

        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['epscenter']);
        });
    }
}
