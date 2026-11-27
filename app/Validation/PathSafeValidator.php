<?php

namespace App\Validation;

use Illuminate\Validation\Validator;

/**
 * Validator con `dimensions` a prueba de PHP 8 en Windows.
 *
 * En este entorno (Apache + mod_fcgid + tmp en C:\Windows\Temp),
 * SplFileInfo::getRealPath() puede devolver false para un upload
 * recién subido aunque el archivo exista y sea legible por su
 * pathname (getMimeType() lo lee sin problema). El validateDimensions
 * de Laravel 8 hace getimagesize($value->getRealPath()) y en PHP 8
 * getimagesize('') lanza ValueError ("Path cannot be empty") en vez
 * de devolver false como en PHP 7 — crasheaba p.ej. el guardado de
 * logos en admin/main_server_settings.
 *
 * Aquí se replica la lógica del padre usando el pathname como
 * fallback y sin llamar jamás a getimagesize con ruta vacía.
 */
class PathSafeValidator extends Validator
{
    public function validateDimensions($attribute, $value, $parameters)
    {
        if (! $this->isValidFileInstance($value)) {
            return false;
        }

        if (in_array($value->getMimeType(), ['image/svg+xml', 'image/svg'])) {
            return true;
        }

        $path = $value->getRealPath();
        if (! is_string($path) || $path === '') {
            $path = $value->getPathname();
        }
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            return false;
        }

        $sizeDetails = @getimagesize($path);
        if (! $sizeDetails) {
            return false;
        }

        $this->requireParameterCount(1, $parameters, 'dimensions');

        [$width, $height] = $sizeDetails;

        $parameters = $this->parseNamedParameters($parameters);

        if ($this->failsBasicDimensionChecks($parameters, $width, $height) ||
            $this->failsRatioCheck($parameters, $width, $height)) {
            return false;
        }

        return true;
    }
}
