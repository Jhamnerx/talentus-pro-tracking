<?php

namespace Tobuli\Services\Securitas;

/**
 * No hubo respuesta de GlobalAVL (red, DNS, timeout). Las filas se conservan
 * para reintentar.
 */
class SecuritasTransportException extends \RuntimeException
{
}
