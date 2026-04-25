<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ChangeEpscenterIdTransporteToString extends Migration
{
    public function up()
    {
        Schema::table('epscenter', function (Blueprint $table) {
            $table->string('id_transporte', 15)->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('epscenter', function (Blueprint $table) {
            $table->unsignedInteger('id_transporte')->nullable()->change();
        });
    }
}
