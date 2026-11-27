<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('backups', function (Blueprint $table) {
            if (!Schema::hasColumn('backups', 'destinations')) {
                $table->text('destinations')->nullable()->after('details');
            }

            if (!Schema::hasColumn('backups', 'database_bytes')) {
                $table->unsignedBigInteger('database_bytes')->nullable()->after('details');
            }

            if (!Schema::hasColumn('backups', 'protected')) {
                $table->boolean('protected')->default(false)->after('details');
            }
        });

        // Los detalles antiguos contienen el comando completo, con claves de la base y del FTP.
        DB::table('backups')->whereNotNull('details')->update(['details' => null]);
    }

    public function down()
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropColumn(['destinations', 'database_bytes', 'protected']);
        });
    }
};
