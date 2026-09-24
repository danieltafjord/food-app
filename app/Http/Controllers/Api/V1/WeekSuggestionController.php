<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Ai\GenerateWeekDinners;
use App\Actions\Ai\RunWeekPlanning;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuggestWeekRequest;
use Illuminate\Http\JsonResponse;

class WeekSuggestionController extends Controller
{
    public function __invoke(SuggestWeekRequest $request, RunWeekPlanning $runner, GenerateWeekDinners $agent): JsonResponse
    {
        return response()->json(['data' => $runner->handle($request->ip() ?? '', $request->validated(), $agent)]);
    }
}
