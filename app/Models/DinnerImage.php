<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One uploaded or generated dinner picture. The files live under an
 * unguessable `path` as square WebP variants (`{path}/{size}.webp`); a dinner
 * points at an image by copying its `path` and `thumbhash`.
 */
#[Fillable(['household_id', 'user_id', 'path', 'source', 'thumbhash', 'bytes'])]
class DinnerImage extends Model
{
    public const SOURCE_PHOTO = 'photo';

    public const SOURCE_AI = 'ai';

    /** Square edge lengths in pixels, smallest first. */
    public const SIZES = [160, 480, 1024];

    public const PATH_PATTERN = '/^dinner-images\/[A-Za-z0-9]{32}$/';

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public static function disk(): string
    {
        return (string) config('filesystems.media_disk');
    }

    public static function url(string $path, int $size = 1024): string
    {
        return Storage::disk(self::disk())->url($path.'/'.$size.'.webp');
    }

    /** @return list<string> */
    public static function files(string $path): array
    {
        return array_map(fn (int $size): string => $path.'/'.$size.'.webp', self::SIZES);
    }
}
