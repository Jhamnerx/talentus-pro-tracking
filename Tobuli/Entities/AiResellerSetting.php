<?php

namespace Tobuli\Entities;

/**
 * Ajustes de Talentus IA CHAT de un reseller (clave propia, modelos, cuotas).
 * La clave se guarda cifrada con encrypt(); leerla solo con AiSettings.
 */
class AiResellerSetting extends AbstractEntity
{
    protected $table = 'ai_reseller_settings';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = [
        'user_id', 'api_key', 'model', 'light_model', 'effort', 'default_quota',
        'monthly_budget_usd', 'user_quotas', 'allow_main_key',
    ];

    protected $hidden = ['api_key'];

    protected $casts = [
        'user_quotas' => 'array',
        'allow_main_key' => 'boolean',
        'default_quota' => 'integer',
        'monthly_budget_usd' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
