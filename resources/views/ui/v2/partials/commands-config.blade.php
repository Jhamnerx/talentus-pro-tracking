{{-- Config del modal compartido "Enviar comando" (tp-commands.js).
     Se incluye desde el layout: el modal se abre desde el rail y desde el
     kebab de Live. El backend re-verifica el permiso send_command. --}}
<script type="application/json" id="tp-commands-config">
{!! json_encode([
    'urls' => [
        'meta' => route('ui.v2.api.commands.meta'),
        'list' => route('ui.v2.api.commands.list'),
        'send' => route('ui.v2.api.commands.send'),
    ],
    'i18n' => [
        'send_command'   => trans('front.send_command'),
        'gprs'           => trans('front.gprs'),
        'sms'            => trans('front.sms'),
        'devices'        => trans('validation.attributes.devices'),
        'type'           => trans('validation.attributes.type'),
        'message'        => trans('validation.attributes.message'),
        'send'           => trans('front.send'),
        'cancel'         => trans('global.cancel'),
        'search'         => trans('front.search'),
        'select_device'  => trans('front.select_device'),
        'connection'     => trans('front.connection'),
        'select_all'     => trans('front.select_all'),
        'deselect_all'   => trans('front.deselect_all'),
        'command_sent'   => trans('front.command_sent'),
        'are_you_sure'   => trans('front.are_you_sure'),
        'sms_gateway_disabled' => trans('front.sms_gateway_disabled'),
        'no_devices_sim' => trans('front.no_devices_with_sim_number'),
        'loading'        => trans('front.loading'),
        'error'          => trans('front.unexpected_error'),
        'not_found'      => trans('global.not_found'),
        'auto_send_when_online' => trans('front.auto_send_when_online'),
        'queued_for_online'     => trans('front.queued_for_online'),
        'queued'                => trans('front.queued'),
        'results'               => trans('front.results'),
    ],
], JSON_UNESCAPED_UNICODE) !!}
</script>
