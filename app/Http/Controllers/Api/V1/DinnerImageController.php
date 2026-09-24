<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\DinnerImages\GenerateDinnerImage;
use App\Actions\DinnerImages\StoreDinnerImage;
use App\Models\DinnerImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stores dinner pictures. The response only describes the stored image; the
 * app attaches it by syncing the dinner with the returned path and thumbhash.
 */
class DinnerImageController extends ApiController
{
    public function store(Request $request, StoreDinnerImage $store): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'mimes:jpeg,png,webp', 'max:10240', 'dimensions:min_width=64,min_height=64,max_width=8000,max_height=8000'],
        ]);
        $image = $store->handle($request->file('image')->getContent(), $this->currentHousehold($request),
            $request->user(), DinnerImage::SOURCE_PHOTO);

        return $this->respond($image, 201);
    }

    public function generate(Request $request, GenerateDinnerImage $generator): JsonResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:120', 'not_regex:/[\r\n<>]/'],
            'ingredients' => ['present', 'array', 'max:40'],
            'ingredients.*' => ['required', 'string', 'max:120', 'not_regex:/[\r\n<>]/'],
            'category' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);
        $image = $generator->handle($request->user(), $this->currentHousehold($request), [
            'name' => trim($input['name']),
            'ingredients' => array_values(array_unique(array_map(fn (string $name) => mb_strtolower(trim($name)), $input['ingredients']))),
            'category' => $input['category'] ?? null,
        ]);

        return $this->respond($image, 201);
    }

    private function respond(DinnerImage $image, int $status): JsonResponse
    {
        return response()->json(['data' => [
            'path' => $image->path,
            'thumbhash' => $image->thumbhash,
            'url' => DinnerImage::url($image->path),
        ]], $status);
    }
}
