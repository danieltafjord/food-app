<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Household;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

abstract class ApiController extends Controller
{
    /**
     * The active household resolved by the EnsureActiveHousehold middleware.
     */
    protected function currentHousehold(Request $request): Household
    {
        return $request->attributes->get('current_household');
    }

    /**
     * Abort with 404 unless the given model belongs to the active household.
     * Prevents leaking or mutating another household's records via its id.
     */
    protected function ensureBelongsToHousehold(Request $request, Model $model): void
    {
        abort_unless(
            $model->getAttribute('household_id') === $this->currentHousehold($request)->id,
            404,
        );
    }
}
