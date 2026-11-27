<?php namespace Tobuli\Entities;

/**
 * Plantilla de alerta: el contenido de una alerta, sin dueño ni vehículos.
 *
 * Se crea a partir de una alerta ya existente (snapshot) en vez de con un
 * formulario propio: así se reutiliza el editor de alertas completo —tipos,
 * parámetros, canales, horarios— en lugar de mantener dos.
 */
class AlertTemplate extends AbstractEntity
{
    protected $table = 'alert_templates';

    protected $fillable = [
        'active',
        'name',
        'type',
        'data',
        'notifications',
        'schedules',
        'for_all_user_devices',
        'billing_plan_id',
        'events_custom',
        'description',
    ];

    protected $casts = [
        'active'               => 'boolean',
        'for_all_user_devices' => 'boolean',
        'data'                 => 'array',
        'notifications'        => 'array',
        'schedules'            => 'array',
        'events_custom'        => 'array',
    ];

    public function billingPlan()
    {
        return $this->belongsTo(BillingPlan::class, 'billing_plan_id');
    }

    /** Plantillas activas que le corresponden a un usuario por su plan. */
    public function scopeForUser($query, User $user)
    {
        return $query->where('active', 1)
            ->where(function ($q) use ($user) {
                $q->whereNull('billing_plan_id');

                if ($user->billing_plan_id) {
                    $q->orWhere('billing_plan_id', $user->billing_plan_id);
                }
            });
    }
}
