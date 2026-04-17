<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSidersalesTableAndAddDeviceColumn extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sidersales', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('device_id')->index();
            $table->string('placa', 20)->nullable()->index();
            $table->double('latitud')->nullable();
            $table->double('longitud')->nullable();
            $table->double('velocidadKPH', 8, 2)->nullable();
            $table->string('fechaHoraGPS', 30)->nullable();
            $table->string('rucTransportista', 20)->nullable();
            $table->string('ProveedorGPS', 100)->nullable()->default('TALENTUS TECHNOLOGY SAC');
            $table->timestamps();

            $table->foreign('device_id')->references('id')->on('devices')->onDelete('cascade');
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->boolean('sidersales')->default(false)->after('wisetrack');
        });
    }

    public function down()
    {
        Schema::dropIfExists('sidersales');

        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('sidersales');
        });
    }
}
