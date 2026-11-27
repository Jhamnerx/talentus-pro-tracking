<?php

namespace App\Services\Ai;

/**
 * Error esperado de una herramienta (parametro faltante, rango muy largo...).
 * Su mensaje se devuelve a Claude como tool_result con is_error=true.
 */
class ToolException extends \Exception
{
}
