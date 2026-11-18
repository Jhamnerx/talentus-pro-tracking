<?php

namespace Tobuli\Entities;

class AiUsageLog extends AbstractEntity
{
    const UPDATED_AT = null;

    protected $table = 'ai_usage_logs';

    protected $fillable = [
        'user_id', 'conversation_id', 'feature', 'model', 'input_tokens', 'output_tokens',
        'cache_read_tokens', 'cache_write_tokens', 'api_calls', 'cost_usd', 'tools', 'status',
    ];

    protected $casts = [
        'tools' => 'array',
    ];

    public function scopeThisMonth($query)
    {
        return $query->where('ai_usage_logs.created_at', '>=', date('Y-m-01 00:00:00'));
    }
}
