<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class WidenReportsTypeColumns extends Migration
{
    /**
     * reports.type and report_logs.type were TINYINT (UNSIGNED) — max 255 — but
     * ReportManager::$types defines custom report type IDs in the 900s (902-906).
     * MySQL silently clips out-of-range values to 255 on insert/update under
     * non-strict sql_mode, corrupting the stored type. Widen both columns to
     * SMALLINT UNSIGNED, matching user_report_type_pivot.report_type_id.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->smallInteger('type')->unsigned()->nullable()->change();
        });

        Schema::table('report_logs', function (Blueprint $table) {
            $table->smallInteger('type')->unsigned()->change();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->tinyInteger('type')->unsigned()->nullable()->change();
        });

        Schema::table('report_logs', function (Blueprint $table) {
            $table->tinyInteger('type')->change();
        });
    }
}
