<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The single row holding the published theme, the unpublished draft, and a
 * version that rejects saves made from a stale editor tab.
 *
 * @property int $id
 * @property array<string, mixed> $theme
 * @property array<string, mixed>|null $draft
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['id', 'theme', 'draft', 'version'])]
class SiteTheme extends Model
{
    protected function casts(): array
    {
        return [
            'theme' => 'array',
            'draft' => 'array',
            'version' => 'integer',
        ];
    }
}
