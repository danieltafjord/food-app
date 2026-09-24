<?php

namespace App\Data;

use App\Data\OpenApi\ItemsOf;
use App\Models\Dinner;
use App\Models\DinnerImage;
use App\Models\DinnerItem;
use Spatie\LaravelData\Data;

class DinnerData extends Data
{
    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function __construct(
        public int $id,
        public string $name,
        public int $defaultServings,
        public ?string $notes,
        #[ItemsOf(DinnerItemData::class)]
        public array $items,
        public ?string $category = null,
        public ?string $emoji = null,
        /** Largest square WebP variant; smaller ones sit next to it as 480.webp and 160.webp. */
        public ?string $imageUrl = null,
        public ?string $imageThumbhash = null,
    ) {}

    public static function fromDinner(Dinner $dinner): self
    {
        return new self(
            id: $dinner->id,
            name: $dinner->name,
            defaultServings: $dinner->default_servings,
            notes: $dinner->notes,
            category: $dinner->category,
            emoji: $dinner->emoji,
            imageUrl: $dinner->image_path ? DinnerImage::url($dinner->image_path) : null,
            imageThumbhash: $dinner->image_thumbhash,
            items: $dinner->items->map(fn (DinnerItem $item) => DinnerItemData::fromItem($item)->toArray())->all(),
        );
    }
}
