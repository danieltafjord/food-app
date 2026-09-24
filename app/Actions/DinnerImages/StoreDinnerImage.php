<?php

namespace App\Actions\DinnerImages;

use App\Models\DinnerImage;
use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use RuntimeException;
use Throwable;
use Thumbhash\Thumbhash;

use function Thumbhash\extract_size_and_pixels_with_gd;

/**
 * Turns one picture (a phone upload or an AI result) into the square WebP
 * variants every client renders, plus a thumbhash placeholder. Files are
 * immutable: a new picture always gets a new random path.
 */
class StoreDinnerImage
{
    public function handle(string $binary, Household $household, User $user, string $source): DinnerImage
    {
        try {
            $image = $this->manager()->read($binary);
        } catch (Throwable) {
            throw ValidationException::withMessages(['image' => 'The image could not be read.']);
        }
        if (min($image->width(), $image->height()) < 64) {
            throw ValidationException::withMessages(['image' => 'The image is too small.']);
        }
        // Crop straight to the largest variant: the smaller ones and the
        // thumbhash are then scaled from that instead of the full-size photo.
        $side = min($image->width(), $image->height(), max(DinnerImage::SIZES));
        $image->cover($side, $side);

        $path = 'dinner-images/'.Str::random(32);
        $disk = Storage::disk(DinnerImage::disk());
        $bytes = 0;
        $written = [];
        try {
            foreach (array_reverse(DinnerImage::SIZES) as $size) {
                $encoded = (clone $image)->resize($size, $size)->toWebp(quality: $size <= 160 ? 75 : 80)->toString();
                $file = $path.'/'.$size.'.webp';
                // No per-file visibility: the local `public` disk sets it in its
                // config, and a Laravel Cloud (Cloudflare R2) bucket is public as
                // a whole and rejects the per-object ACL with NotImplemented.
                $stored = $disk->put($file, $encoded, [
                    'ContentType' => 'image/webp',
                    'CacheControl' => 'public, max-age=31536000, immutable',
                ]);
                if (! $stored) {
                    throw new RuntimeException('Could not store '.$file);
                }
                $written[] = $file;
                $bytes += strlen($encoded);
            }

            return DinnerImage::query()->create([
                'household_id' => $household->id,
                'user_id' => $user->id,
                'path' => $path,
                'source' => $source,
                'thumbhash' => $this->thumbhash($image),
                'bytes' => $bytes,
            ]);
        } catch (Throwable $e) {
            $disk->delete($written);

            throw $e;
        }
    }

    private function thumbhash(ImageInterface $image): string
    {
        $png = (clone $image)->resize(100, 100)->toPng()->toString();
        [$width, $height, $pixels] = extract_size_and_pixels_with_gd($png);

        return Thumbhash::convertHashToString(Thumbhash::RGBAToHash($width, $height, $pixels));
    }

    private function manager(): ImageManager
    {
        // Files are public: never publish the camera's EXIF/XMP (GPS position,
        // device) along with the picture. GD drops it anyway; Imagick keeps it.
        return extension_loaded('imagick') ? ImageManager::imagick(strip: true) : ImageManager::gd(strip: true);
    }
}
