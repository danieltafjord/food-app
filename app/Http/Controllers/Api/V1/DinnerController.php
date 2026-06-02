<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Dinners\CreateDinner;
use App\Actions\Dinners\UpdateDinner;
use App\Data\DinnerData;
use App\Data\DinnerInputData;
use App\Models\Dinner;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\LaravelData\DataCollection;

class DinnerController extends ApiController
{
    public function index(Request $request): DataCollection
    {
        $dinners = $this->currentHousehold($request)->dinners()
            ->with('items.ingredient')
            ->orderBy('name')
            ->get()
            ->map(fn (Dinner $dinner) => DinnerData::fromDinner($dinner));

        return DinnerData::collect($dinners, DataCollection::class);
    }

    public function store(DinnerInputData $data, Request $request, CreateDinner $action): DinnerData
    {
        return DinnerData::fromDinner($action->handle($this->currentHousehold($request), $data));
    }

    public function show(Request $request, Dinner $dinner): DinnerData
    {
        $this->ensureBelongsToHousehold($request, $dinner);

        return DinnerData::fromDinner($dinner->load('items.ingredient'));
    }

    public function update(DinnerInputData $data, Request $request, Dinner $dinner, UpdateDinner $action): DinnerData
    {
        $this->ensureBelongsToHousehold($request, $dinner);

        return DinnerData::fromDinner($action->handle($dinner, $data));
    }

    public function destroy(Request $request, Dinner $dinner): Response
    {
        $this->ensureBelongsToHousehold($request, $dinner);

        $dinner->delete();

        return response()->noContent();
    }
}
