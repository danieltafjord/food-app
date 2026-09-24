<?php

namespace App\Actions\DinnerImages;

use App\Models\Dinner;
use App\Models\DinnerImage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes pictures no dinner points at: replaced pictures, AI previews that
 * were never used, pictures of dinners deleted a while ago (kept for a grace
 * period so an offline undo still finds its files), and everything from a
 * deleted household.
 */
class PruneDinnerImages
{
    /** Fresh images may still be on their way to a dinner through sync. */
    public const UNATTACHED_GRACE_HOURS = 24;

    public const DELETED_DINNER_GRACE_DAYS = 30;

    /** @return int The number of images removed. */
    public function handle(): int
    {
        $disk = Storage::disk(DinnerImage::disk());
        $removed = 0;

        DinnerImage::query()
            ->where('created_at', '<', now()->subHours(self::UNATTACHED_GRACE_HOURS))
            ->where(fn (Builder $query) => $query->whereNull('household_id')->orWhereNotExists(
                Dinner::withTrashed()
                    ->whereColumn('dinners.household_id', 'dinner_images.household_id')
                    ->whereColumn('dinners.image_path', 'dinner_images.path')
                    ->where(fn ($dinner) => $dinner->whereNull('dinners.deleted_at')
                        ->orWhere('dinners.deleted_at', '>', now()->subDays(self::DELETED_DINNER_GRACE_DAYS)))
                    ->toBase()
            ))
            ->lazyById()
            ->each(function (DinnerImage $image) use ($disk, &$removed): void {
                // The disks don't throw: keep the row when the files could not be
                // removed, so the next run retries instead of orphaning them.
                if (! $disk->delete(DinnerImage::files($image->path))) {
                    return;
                }
                $image->delete();
                $removed++;
            });

        return $removed;
    }
}
