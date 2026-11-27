<?php

namespace Tobuli\Services;

use Tobuli\Entities\Alert;
use Tobuli\Entities\AlertTemplate;
use Tobuli\Entities\EventCustom;
use Tobuli\Entities\User;

/**
 * Plantillas de alerta: capturarlas de una alerta existente y sembrarlas a un
 * usuario.
 *
 * Regla acordada: es una COPIA, no un vínculo vivo. Una vez creada la alerta en
 * el cliente, lo que él edite manda; volver a sembrar no la pisa.
 */
class AlertTemplateService
{
    /** Crea una plantilla a partir de una alerta ya configurada. */
    public function captureFrom(Alert $alert, array $extra = []): AlertTemplate
    {
        // Solo los eventos personalizados GLOBALES (los del admin): los de un
        // cliente no existen en los demas usuarios.
        $eventos = [];
        try {
            $eventos = $alert->events_custom()
                ->whereNull('events_custom.user_id')
                ->pluck('events_custom.id')
                ->all();
        } catch (\Throwable $e) {
            $eventos = [];
        }

        return AlertTemplate::create(array_merge([
            'active'               => true,
            'name'                 => $alert->name,
            'type'                 => $alert->type,
            'data'                 => $alert->data,
            'notifications'        => $alert->notifications,
            'schedules'            => $alert->schedules,
            // Por defecto TODOS los vehiculos: es lo que hace que un vehiculo
            // asignado despues entre solo en la alerta.
            'for_all_user_devices' => true,
            'events_custom'        => $eventos,
        ], $extra));
    }

    /**
     * Siembra a un usuario las plantillas que le tocan.
     *
     * @return int cuántas alertas se crearon
     */
    public function applyToUser(User $user): int
    {
        $creadas = 0;

        foreach (AlertTemplate::forUser($user)->get() as $plantilla) {
            if ($this->yaLaTiene($user, $plantilla)) {
                continue;
            }

            if ($this->createFor($user, $plantilla)) {
                $creadas++;
            }
        }

        return $creadas;
    }

    /**
     * Ya existe una alerta del mismo tipo y nombre: no se duplica ni se pisa.
     * Es la regla de "copia": si el cliente la borró o la renombró, es cosa
     * suya y no se recrea.
     */
    private function yaLaTiene(User $user, AlertTemplate $plantilla): bool
    {
        return $user->alerts()
            ->where('alerts.type', $plantilla->type)
            ->where('alerts.name', $plantilla->name)
            ->exists();
    }

    private function createFor(User $user, AlertTemplate $plantilla): ?Alert
    {
        try {
            $alert = new Alert();
            $alert->user_id = $user->id;
            $alert->active = 1;
            $alert->name = $plantilla->name;
            $alert->type = $plantilla->type;
            $alert->data = $plantilla->data ?: [];
            $alert->notifications = $plantilla->notifications ?: [];
            $alert->schedules = $plantilla->schedules ?: [];

            // Se asigna directo, no por el formulario: la bandera la decide
            // normalmente el selector "todos" del picker de vehiculos, que
            // aqui no interviene.
            $alert->for_all_user_devices = $plantilla->for_all_user_devices ? 1 : 0;

            $alert->save();

            // El usuario, para que la vea
            $alert->users()->syncWithoutDetaching([$user->id]);

            if ($plantilla->for_all_user_devices) {
                $this->attachAllDevices($user, $alert);
            }

            $this->attachEventsCustom($alert, $plantilla);

            return $alert;
        } catch (\Throwable $e) {
            // Un fallo sembrando NO puede tumbar el alta del usuario.
            report($e);

            return null;
        }
    }

    /** Todos los vehiculos que el usuario tenga AHORA; los futuros los mete el sync. */
    private function attachAllDevices(User $user, Alert $alert): void
    {
        $ids = $user->devices()->pluck('devices.id')->all();

        if ($ids) {
            $alert->devices()->syncWithoutDetaching($ids);
        }
    }

    /** Eventos personalizados globales que la plantilla arrastra (tipo custom). */
    private function attachEventsCustom(Alert $alert, AlertTemplate $plantilla): void
    {
        $ids = (array) $plantilla->events_custom;

        if (! $ids) {
            return;
        }

        // Solo los que sigan existiendo y sigan siendo globales
        $vivos = EventCustom::whereIn('id', $ids)->whereNull('user_id')->pluck('id')->all();

        if ($vivos) {
            $alert->events_custom()->syncWithoutDetaching($vivos);
        }
    }
}
