<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plantilla 'email_activation' (código único de confirmación de correo).
 * El flujo legacy la referenciaba pero nunca existió en esta BD (el reenvío
 * fatales por triple rotura: args invertidos + sin fila + sin builder).
 * Additiva e idempotente: solo inserta si no existe.
 */
return new class extends Migration
{
    public function up()
    {
        $exists = DB::table('email_templates')
            ->where('name', 'email_activation')
            ->whereNull('user_id')
            ->exists();

        if (! $exists) {
            DB::table('email_templates')->insert([
                'user_id'    => null,
                'name'       => 'email_activation',
                'title'      => 'Confirma tu correo — Código: [code]',
                // El cuerpo real lo renderiza resources/views/emails/email_activation.blade.php
                'note'       => 'Tu código de confirmación es: [code]',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down()
    {
        DB::table('email_templates')
            ->where('name', 'email_activation')
            ->whereNull('user_id')
            ->delete();
    }
};
