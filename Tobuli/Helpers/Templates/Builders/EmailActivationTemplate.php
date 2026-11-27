<?php

namespace Tobuli\Helpers\Templates\Builders;

/**
 * Código único de activación/confirmación de email (users.email_activation).
 * El flujo legacy referenciaba este builder pero nunca existió en el fork
 * (TemplateManager::loadTemplateBuilder('email_activation') fatales) — se
 * crea con el mismo patrón que ResetPasswordCodeTemplate.
 */
class EmailActivationTemplate extends TemplateBuilder
{
    protected function variables($item): array
    {
        return [
            '[code]' => $item['code'],
        ];
    }

    protected function placeholders(): array
    {
        return [
            '[code]' => 'Code',
        ];
    }
}
