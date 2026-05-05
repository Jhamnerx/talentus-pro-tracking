<?php

namespace Tobuli\Protocols\Protocols;

use Tobuli\Protocols\Commands;
use CustomFacades\Field;

/**
 * Protocolo Howen MDVR (H-Protocol V3.9.5).
 *
 * Dispositivos compatibles: Howen Hero-A, Hero-B, Hero-C y derivados.
 *
 * Las operaciones de video (liveStart, liveStop, snapshot, audio,
 * filesSearch, playback, recording) se gestionan vía DeviceVideoService
 * y HowenVideoProtocol, que llaman a los endpoints REST de HowenResource.java
 * en Traccar (api/howen/{traccarId}/...).
 */
class HowenProtocol extends BaseProtocol implements \Tobuli\Protocols\Protocol
{
    protected function commands()
    {
        return [
            $this->initCommand(Commands::TYPE_CUSTOM),
            $this->initCommand(Commands::TYPE_REBOOT_DEVICE),
            $this->initCommand(Commands::TYPE_VIDEO_START, [
                Field::select('channel', trans('front.channel'), 1)
                    ->setRequired()
                    ->setOptions([
                        1 => trans('front.channel') . ' 1',
                        2 => trans('front.channel') . ' 2',
                        3 => trans('front.channel') . ' 3',
                        4 => trans('front.channel') . ' 4',
                        5 => trans('front.channel') . ' 5',
                        6 => trans('front.channel') . ' 6',
                        7 => trans('front.channel') . ' 7',
                        8 => trans('front.channel') . ' 8',
                    ]),
                Field::select('stream', trans('front.stream_type'), 1)
                    ->setRequired()
                    ->setOptions([
                        0 => trans('front.stream_sub'),
                        1 => trans('front.stream_main'),
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
                        5 => trans('front.channel') . ' 5',
                        6 => trans('front.channel') . ' 6',
                        7 => trans('front.channel') . ' 7',
                        8 => trans('front.channel') . ' 8',
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

    protected function buildCommandvideoStart($device, $data)
    {
        return $data;
    }

    protected function buildCommandvideoStop($device, $data)
    {
        return $data;
    }

    protected function buildCommandaudioListen($device, $data)
    {
        return $data;
    }
}
