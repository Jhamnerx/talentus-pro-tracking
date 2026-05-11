<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddStatusToMininterTable extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('mininter', 'status')) {
            return;
        }

        Schema::table('mininter', function (Blueprint $table) {
            $table->string('status', 20)->nullable()->default(null)->index()->after('other');
        });
    }

    public function down()
    {
        if (!Schema::hasColumn('mininter', 'status')) {
            return;
        }

        Schema::table('mininter', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
}
