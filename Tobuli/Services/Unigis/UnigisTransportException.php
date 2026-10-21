<?php

namespace Tobuli\Services\Unigis;

use RuntimeException;

/**
 * Fallo de transporte con UNIGIS (red, timeout, SoapFault, WSDL inaccesible).
 *
 * Distinto de una respuesta negativa del servicio: aqui la posicion NO llego,
 * asi que el job debe conservar la fila y reintentar.
 */
class UnigisTransportException extends RuntimeException
{
}
