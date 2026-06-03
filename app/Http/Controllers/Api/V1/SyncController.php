<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sync\ApplySyncBatch;
use App\Data\Sync\SyncRequestData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends ApiController
{
    /**
     * Apply the client's offline changes and return everything in the active
     * household that has changed since the client's cursor.
     */
    public function store(SyncRequestData $data, Request $request, ApplySyncBatch $action): JsonResponse
    {
        $result = $action->handle(
            $this->currentHousehold($request),
            $data->lastSync,
            $data->changes,
        );

        return response()->json($result);
    }
}
