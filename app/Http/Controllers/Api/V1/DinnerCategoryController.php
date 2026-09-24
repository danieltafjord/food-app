<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DinnerCategoryController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->currentHousehold($request)->dinnerCategories()->orderBy('name')->get()->map(
            fn ($category): array => ['id' => $category->uuid, 'name' => $category->name],
        )]);
    }

    public function store(Request $request): JsonResponse
    {
        $category = $this->currentHousehold($request)->dinnerCategories()->create($this->input($request));

        return response()->json(['data' => ['id' => $category->uuid, 'name' => $category->name]], 201);
    }

    public function update(Request $request, string $dinnerCategory): JsonResponse
    {
        $category = $this->currentHousehold($request)->dinnerCategories()->where('uuid', $dinnerCategory)->firstOrFail();
        $category->update($this->input($request));

        return response()->json(['data' => ['id' => $category->uuid, 'name' => $category->name]]);
    }

    public function destroy(Request $request, string $dinnerCategory): Response
    {
        $this->currentHousehold($request)->dinnerCategories()->where('uuid', $dinnerCategory)->firstOrFail()->delete();

        return response()->noContent();
    }

    private function input(Request $request): array
    {
        $input = $request->validate(['name' => ['required', 'string', 'max:80', 'regex:/\S/u']]);
        $input['name'] = preg_replace('/\s+/u', ' ', trim($input['name']));

        return $input;
    }
}
