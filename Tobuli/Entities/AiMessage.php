<?php

namespace Tobuli\Entities;

class AiMessage extends AbstractEntity
{
    const UPDATED_AT = null;

    protected $table = 'ai_messages';

    protected $fillable = ['conversation_id', 'role', 'content', 'display', 'visible'];

    // "content" se guarda como JSON crudo (sin cast) para conservar objetos
    // vacios ({}) tal como los devuelve la API de Claude.
    protected $casts = [
        'display' => 'array',
        'visible' => 'boolean',
    ];

    /** Mensaje en formato de la API (objetos, no arrays). */
    public function toApiMessage()
    {
        return (object) [
            'role'    => $this->role,
            'content' => json_decode($this->content),
        ];
    }

    public function conversation()
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }
}
