<?php

namespace App\Http\Controllers\Api\Public\V1;

use App\Data\ApiTokenData;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /**
     * Describe the calling token: its household, permissions and expiry.
     */
    public function __invoke(Request $request): ApiTokenData
    {
        return ApiTokenData::fromApiToken($request->attributes->get('api_token')->load('token', 'household'));
    }
}
