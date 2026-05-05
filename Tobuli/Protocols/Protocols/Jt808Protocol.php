<?php

namespace Tobuli\Protocols\Protocols;

use Tobuli\Protocols\Commands;
use CustomFacades\Field;

/**
 * Protocolo JT808/JT1078.
 *
 * Dispositivos compatibles: Jimi JC371, JC181, JC182, JC450, JC451,
 * y cualquier equipo que implemente el estándar chino JT/T 808-2019
 * con extensión de video JT/T 1078.
 *
 * El envío de comandos de video (videoStart, videoStop, audioListen)
 * se tramita a través de Traccar usando la API REST de JT1078Resource.java,
 * gestionada por DeviceVideoService / Jt1078VideoProtocol.
 */
class Jt808Protocol extends BaseProtocol implements \Tobuli\Protocols\Protocol
{
    protected function commands()
    {
        return [
            $this->initCommand(Commands::TYPE_CUSTOM),
            $this->initCommand(Commands::TYPE_ENGINE_STOP),
            $this->initCommand(Commands::TYPE_ENGINE_RESUME),
            $this->initCommand(Commands::TYPE_REBOOT_DEVICE),
            $this->initCommand(Commands::TYPE_VIDEO_START, [
                Field::select('channel', trans('front.channel'), 1)
                    ->setRequired()
                    ->setOptions([
                        1 => trans('front.channel') . ' 1',
                        2 => trans('front.channel') . ' 2',
                        3 => trans('front.channel') . ' 3',
                        4 => trans('front.channel') . ' 4',
                    ]),
                Field::select('stream', trans('front.stream_type'), 0)
                    ->setRequired()
                    ->setOptions([
                        0 => trans('front.stream_main'),
                        1 => trans('front.stream_sub'),
                    ]),
            ]),
            $this->initCommand(Commands::TYPE_VIDEO_STOP, [
                Field::select('channel', trans('front.channel'), 1)
                    ->setRequired()
                    ->setOptions([
                        1 => trans('front.channel') . ' 1',
                        2 => trans('front.channel') . ' 2',
                        3 => trans('front.channel') . ' 3',
                        4 => trans('front.channel') . ' 4',
                    ]),
            ]),
            $this->initCommand(Commands::TYPE_AUDIO_LISTEN, [
                Field::select('channel', trans('front.channel'), 1)
                    ->setRequired()
                    ->setOptions([
                        1 => trans('front.channel') . ' 1',
                        2 => trans('front.channel') . ' 2',
                    ]),
                Field::select('mode', trans('front.audio_mode'), 0)
                    ->setRequired()
                    ->setOptions([
                        0 => trans('front.audio_mode_listen'),
                        1 => trans('front.audio_mode_intercom'),
                        2 => trans('front.audio_mode_broadcast'),
                    ]),
            ]),
        ];
    }

    /**
     * videoStart — inicia stream JT1078 vía DeviceVideoService::startLive()
     * El controlador DeviceVideoController@liveStart consume este comando.
     */
    protected function buildCommandvideoStart($device, $data)
    {
        // Los atributos se pasan directo; el controlador los lee con $this->data
        return $data;
    }

    /**
     * videoStop — detiene stream JT1078 vía DeviceVideoService::stopLive()
     */
    protected function buildCommandvideoStop($device, $data)
    {
        return $data;
    }

    /**
     * audioListen — inicia audio bidireccional JT1078 (0x9101 dataType=1/4)
     */
    protected function buildCommandaudioListen($device, $data)
    {
        return $data;
    }
}
