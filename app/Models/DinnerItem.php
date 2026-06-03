<?php

namespace App\Models;

use App\Models\Concerns\HasSyncIdentity;
use Database\Factories\DinnerItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DinnerItem extends Model
{
    /** @use HasFactory<DinnerItemFactory> */
    use HasFactory, HasSyncIdentity;

    /** @var list<string> */
    protected $fillable = [
        'dinner_id',
        'ingredient_id',
        'quantity',
        'unit',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Dinner, $this> */
    public function dinner(): BelongsTo
    {
        return $this->belongsTo(Dinner::class);
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
