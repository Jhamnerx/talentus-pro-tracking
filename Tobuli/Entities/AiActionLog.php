<?php

namespace Tobuli\Entities;

class AiActionLog extends AbstractEntity
{
    const UPDATED_AT = null;

    protected $table = 'ai_action_logs';

    protected $fillable = ['user_id', 'conversation_id', 'action', 'title', 'params', 'status', 'result', 'ip'];

    protected $casts = [
        'params' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
