<?php

use App\Mcp\Servers\HandlelistaServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

/*
 * MCP server for AI agents. Authenticated with either a user-created API
 * token or OAuth (clients register themselves and the user approves them
 * for one household). Both pin the household; each tool checks the token's
 * read/write permission itself because every MCP call is a POST.
 */
Route::middleware('throttle:oauth-registration')->group(fn () => Mcp::oauthRoutes());

Mcp::web('/mcp', HandlelistaServer::class)
    ->middleware(['api.log:mcp', 'auth:api', 'active:api', 'throttle:public-api', 'api.transaction', 'api.token:tools']);
