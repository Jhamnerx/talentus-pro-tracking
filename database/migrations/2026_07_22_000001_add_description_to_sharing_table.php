<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('sharing', function (Blueprint $table) {
            $table->string('description', 500)->nullable()->after('name');
        });
    }

    public function down()
    {
        Schema::table('sharing', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
