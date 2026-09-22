<?php

namespace App\Http\Middleware;

use App\Models\ApiRequest;
use App\Models\ApiTokenDetail;
use App\Models\Household;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Records every request to the mobile API, the public API and the MCP server
 * in `api_requests` so admins can inspect what a client sent and what it got
 * back when something fails. Runs first in the stack so rejected requests
 * (401, 403, 429) are logged too, and outside the write transaction so the
 * row survives a rollback. Logging must never break the request itself.
 */
class LogApiRequest
{
    /** Longest body kept per request or response. */
    public const MAX_BODY_BYTES = 16384;

    /** Body fields whose values are never stored. */
    private const SECRET_FIELDS = ['password', 'current_password', 'password_confirmation', 'token', 'access_token', 'refresh_token', 'client_secret', 'secret', 'code', 'authorization', 'plain_text_token'];

    /** Request attribute holding the id that ties this request to everything it logs. */
    public const REQUEST_ID = 'request_id';

    public function handle(Request $request, Closure $next, string $channel = ApiRequest::CHANNEL_APP): Response
    {
        $startedAt = hrtime(true);
        $requestId = (string) Str::uuid();
        $request->attributes->set(self::REQUEST_ID, $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        try {
            $this->record($request, $response, $channel, $startedAt);
        } catch (Throwable $e) {
            report($e);
        }

        return $response;
    }

    private function record(Request $request, Response $response, string $channel, int $startedAt): void
    {
        $household = $request->attributes->get('current_household');
        $apiToken = $request->attributes->get('api_token');
        $exception = $response instanceof HttpResponse || $response instanceof JsonResponse ? $response->exception : null;

        ApiRequest::query()->create([
            'request_id' => $request->attributes->get(self::REQUEST_ID),
            'channel' => $channel,
            'user_id' => $request->user('api')?->id,
            'household_id' => $household instanceof Household ? $household->id : null,
            'api_token_detail_id' => $apiToken instanceof ApiTokenDetail ? $apiToken->id : null,
            'method' => $request->getMethod(),
            'path' => mb_substr($request->path(), 0, 255),
            'route' => $request->route()?->getName() ? mb_substr((string) $request->route()->getName(), 0, 120) : null,
            'status' => $response->getStatusCode(),
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent() ? mb_substr($request->userAgent(), 0, 255) : null,
            'request_body' => $this->requestBody($request),
            'response_body' => $this->truncate($this->responseContent($response)),
            'error' => $exception instanceof Throwable ? $this->describe($exception) : null,
            'created_at' => now(),
        ]);
    }

    private function requestBody(Request $request): ?string
    {
        $body = $request->isJson() ? $request->json()->all() : $request->request->all();
        $input = $body;
        if ($request->query->count() > 0) {
            $input = ['query' => $request->query->all()] + ($body === [] ? [] : ['body' => $body]);
        }
        if ($input === []) {
            $raw = $request->getContent();

            return $raw === '' ? null : $this->truncate($raw);
        }

        return $this->truncate(json_encode($this->redact($input), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: null);
    }

    private function responseContent(Response $response): ?string
    {
        if (! $response instanceof HttpResponse && ! $response instanceof JsonResponse) {
            // Streamed and binary responses cannot be read back.
            return null;
        }
        $content = $response->getContent();
        if ($content === false || $content === '') {
            return null;
        }
        $decoded = json_decode($content, true);

        return is_array($decoded)
            ? (json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: $content)
            : $content;
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, mixed>
     */
    private function redact(array $input): array
    {
        foreach ($input as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SECRET_FIELDS, true)) {
                $input[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $input[$key] = $this->redact($value);
            }
        }

        return $input;
    }

    private function truncate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (strlen($value) <= self::MAX_BODY_BYTES) {
            return $value;
        }

        return mb_strcut($value, 0, self::MAX_BODY_BYTES)."\n… [truncated, ".number_format(strlen($value)).' bytes]';
    }

    private function describe(Throwable $exception): string
    {
        $message = $exception::class.': '.$exception->getMessage()
            ."\n".$exception->getFile().':'.$exception->getLine()
            ."\n".$exception->getTraceAsString();

        return mb_substr($message, 0, 8000);
    }
}
