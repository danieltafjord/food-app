<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sync\ApplySyncBatch;
use App\Data\Sync\SyncRequestData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SyncController extends ApiController
{
    /**
     * Apply the client's offline changes and return everything in the active
     * household that has changed since the client's cursor.
     */
    public function store(SyncRequestData $data, Request $request, ApplySyncBatch $action): JsonResponse
    {
        $household = $this->currentHousehold($request);

        if ($data->householdId !== null && $data->householdId !== $household->id) {
            return response()->json([
                'message' => 'The active household changed; re-link this device before syncing.',
                'code' => 'household_mismatch',
                'household_id' => $household->id,
            ], Response::HTTP_CONFLICT);
        }

        return response()->json($action->handle($household, $request->user(), $data->cursor, $data->changes));
    }
}
