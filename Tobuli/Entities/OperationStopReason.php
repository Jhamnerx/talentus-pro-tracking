<?php namespace Tobuli\Entities;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Motivo de parada del catalogo de un usuario (Operaciones, fase 2).
 * `authorizes`: el motivo convierte la parada en autorizada; si no, solo la
 * explica. "Otro" no esta aqui: es reason_id nulo con nota obligatoria.
 */
class OperationStopReason extends AbstractEntity
{
    protected $table = 'operation_stop_reasons';

    protected $fillable = [
        'user_id',
        'name',
        'authorizes',
        'position',
    ];

    protected $casts = [
        'authorizes' => 'bool',
        'position'   => 'int',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUserOwned(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    /** Lista inicial que se crea la primera vez que un usuario abre el catalogo. */
    public static function defaults(): array
    {
        return [
            ['name' => trans('front.operation_reason_traffic'),     'authorizes' => true],
            ['name' => trans('front.operation_reason_queue'),       'authorizes' => true],
            ['name' => trans('front.operation_reason_mechanical'),  'authorizes' => true],
            ['name' => trans('front.operation_reason_tire'),        'authorizes' => true],
            ['name' => trans('front.operation_reason_supervision'), 'authorizes' => true],
            ['name' => trans('front.operation_reason_meal'),        'authorizes' => true],
            ['name' => trans('front.operation_reason_weather'),     'authorizes' => true],
        ];
    }

    public static function seedFor(User $user): void
    {
        if (static::userOwned($user)->exists()) {
            return;
        }

        foreach (static::defaults() as $i => $row) {
            static::create($row + ['user_id' => $user->id, 'position' => $i]);
        }
    }
}
