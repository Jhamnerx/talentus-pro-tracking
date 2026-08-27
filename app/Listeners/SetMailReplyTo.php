<?php namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Arr;

/**
 * Aplica el Reply-To configurado en /admin/email_settings a TODO el correo
 * saliente.
 *
 * Va sobre el mensaje y no sobre el transporte, que es lo que lo hace
 * independiente del proveedor: smtp, sendgrid, postmark, mailgun y
 * gpswoxmailer pasan todos por Mail::send(), y este evento se dispara justo
 * antes del envio en los cinco casos. Asi no hay que tocar ni un Mailable.
 */
class SetMailReplyTo
{
    public function handle(MessageSending $event)
    {
        try {
            $replyTo = Arr::get(settings('email'), 'reply_to_email');
        } catch (\Exception $e) {
            // Mismo criterio defensivo que ConfigurableMailServiceProvider al
            // leer settings('email'): si los ajustes no se pueden leer (por
            // ejemplo durante una migracion o sin BD), el correo sale igual.
            return;
        }

        if (empty($replyTo)) {
            return;
        }

        // Si un Mailable concreto ya puso el suyo, manda el suyo: esto es el
        // valor por defecto de la instalacion, no una imposicion.
        if (! empty($event->message->getReplyTo())) {
            return;
        }

        $event->message->setReplyTo($replyTo);
    }
}
