<?php

namespace Tobuli\Protocols\Video;

/**
 * Resuelve la clase VideoProtocol correspondiente al nombre de protocolo
 * del dispositivo (device->protocol, que proviene de traccar_devices.protocol).
 *
 * Convención de nombres:
 *   protocolo "howen"    → HowenVideoProtocol
 *   protocolo "jt1078"   → Jt1078VideoProtocol   (futura implementación)
 *   protocolo "teltonika" → TeltonikaVideoProtocol (futura implementación)
 *
 * Si no existe una clase para el protocolo se retorna BaseVideoProtocol,
 * cuyos métodos responden con ['status' => 0, 'message' => 'not supported'].
 *
 * Las callables $post y $get encapsulan el transporte HTTP hacia Traccar
 * (autenticación Basic, URL base, puerto) y son provistas por DeviceVideoService.
 */
class VideoManager
{
    /**
     * @param  string    $protocolName  Nombre del protocolo (p.ej. "howen")
     * @param  callable  $post          fn(string $endpoint, array $data): array
     * @param  callable  $get           fn(string $endpoint): array
     */
    public function protocol(string $protocolName, callable $post, callable $get): BaseVideoProtocol
    {
        $class = __NAMESPACE__ . '\\' . ucfirst(strtolower($protocolName)) . 'VideoProtocol';

        if (class_exists($class)) {
            return new $class($post, $get);
        }

        return new BaseVideoProtocol($post, $get);
    }
}
