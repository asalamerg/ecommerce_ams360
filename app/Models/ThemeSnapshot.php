<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stored copy of a theme: a previously published one, one saved by name, or one
 * scheduled for a date range.
 *
 * @property int $id
 * @property string $kind
 * @property string|null $name
 * @property array<string, mixed> $theme
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property int|null $user_id
 * @property CarbonImmutable|null $created_at
 * @property-read User|null $user
 */
#[Fillable(['kind', 'name', 'theme', 'starts_at', 'ends_at', 'user_id', 'created_at'])]
class ThemeSnapshot extends Model
{
    use Prunable;

    public const string HISTORY = 'history';

    public const string SAVED = 'saved';

    public const string SCHEDULE = 'schedule';

    public $timestamps = false;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Ended schedules stay visible for a month, then clear themselves.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()
            ->where('kind', self::SCHEDULE)
            ->where('ends_at', '<', now()->subDays(30));
    }

    protected function casts(): array
    {
        return [
            'theme' => 'array',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
