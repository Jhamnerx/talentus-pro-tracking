<?php

namespace Tobuli\Entities;

class AiConversation extends AbstractEntity
{
    protected $table = 'ai_conversations';

    protected $fillable = ['user_id', 'title'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function messages()
    {
        return $this->hasMany(AiMessage::class, 'conversation_id')->orderBy('id');
    }
}
