<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Funcion que genero el consumo: chat, explain_report, report_email,
 * dashboard, alerts_digest. La cuota de preguntas cuenta solo chat y
 * explain_report (lo que el usuario pide); el resto es automatico.
 */
class AddFeatureToAiUsageLogs extends Migration
{
    public function up()
    {
        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->string('feature', 32)->default('chat')->after('conversation_id')->index();
        });
    }

    public function down()
    {
        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->dropColumn('feature');
        });
    }
}
