<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Talentus IA CHAT por reseller: cada reseller puede usar su propia clave de
 * la API (y sus modelos y cuotas) para el y sus usuarios. allow_main_key lo
 * decide el admin principal: si el reseller no tiene clave, sus usuarios
 * usan la clave principal solo cuando esta en 1.
 *
 * ai_usage_logs.key_owner_id: quien paga la llamada (id del reseller cuya
 * clave se uso; NULL = clave principal).
 */
return new class extends Migration
{
    public function up()
    {
        Schema::create('ai_reseller_settings', function (Blueprint $table) {
            $table->unsignedInteger('user_id')->primary();
            $table->text('api_key')->nullable();
            $table->string('model', 64)->nullable();
            $table->string('light_model', 64)->nullable();
            $table->string('effort', 16)->nullable();
            $table->unsignedInteger('default_quota')->nullable();
            $table->decimal('monthly_budget_usd', 10, 2)->nullable();
            $table->text('user_quotas')->nullable();
            $table->boolean('allow_main_key')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->unsignedInteger('key_owner_id')->nullable()->after('user_id')->index();
        });
    }

    public function down()
    {
        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->dropColumn('key_owner_id');
        });

        Schema::dropIfExists('ai_reseller_settings');
    }
};
