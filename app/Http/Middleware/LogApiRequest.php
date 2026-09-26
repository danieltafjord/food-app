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
 * row survives a rollback. The row is written after the response has been
 * sent, so logging never delays a client, and must never break the request.
 */
class LogApiRequest
{
    /** Longest body kept per request or response. */
    public const MAX_BODY_BYTES = 16384;

    /** Unauthenticated callers can send anything, so keep only enough to see what they tried. */
    public const MAX_ANONYMOUS_BODY_BYTES = 1024;

    /** Body fields whose values are never stored. */
    private const SECRET_FIELDS = ['password', 'current_password', 'password_confirmation', 'token', 'access_token', 'refresh_token', 'client_secret', 'secret', 'code', 'authorization', 'plain_text_token', 'identity_token', 'authorization_code', 'nonce'];

    /** Request attribute holding the id that ties this request to everything it logs. */
    public const REQUEST_ID = 'request_id';

    public function handle(Request $request, Closure $next, string $channel = ApiRequest::CHANNEL_APP): Response
    {
        $startedAt = hrtime(true);
        $requestId = (string) Str::uuid();
        $request->attributes->set(self::REQUEST_ID, $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);
        $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        // `always`: deferred work is otherwise skipped for 4xx and 5xx responses,
        // the ones most worth logging.
        defer(function () use ($request, $response, $channel, $durationMs): void {
            try {
                $this->record($request, $response, $channel, $durationMs);
            } catch (Throwable $e) {
                report($e);
            }
        }, always: true);

        return $response;
    }

    private function record(Request $request, Response $response, string $channel, int $durationMs): void
    {
        $userId = $request->user('api')?->id;
        $maxBytes = $userId === null ? self::MAX_ANONYMOUS_BODY_BYTES : self::MAX_BODY_BYTES;
        $household = $request->attributes->get('current_household');
        $apiToken = $request->attributes->get('api_token');
        $exception = $response instanceof HttpResponse || $response instanceof JsonResponse ? $response->exception : null;

        ApiRequest::query()->create([
            'request_id' => $request->attributes->get(self::REQUEST_ID),
            'channel' => $channel,
            'user_id' => $userId,
            'household_id' => $household instanceof Household ? $household->id : null,
            'api_token_detail_id' => $apiToken instanceof ApiTokenDetail ? $apiToken->id : null,
            'method' => $request->getMethod(),
            'path' => mb_substr($request->path(), 0, 255),
            'route' => $request->route()?->getName() ? mb_substr((string) $request->route()->getName(), 0, 120) : null,
            'status' => $response->getStatusCode(),
            'duration_ms' => $durationMs,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent() ? mb_substr($request->userAgent(), 0, 255) : null,
            'request_body' => $this->requestBody($request, $maxBytes),
            'response_body' => $this->responseContent($response, $maxBytes),
            'error' => $exception instanceof Throwable ? $this->describe($exception, withTrace: $response->getStatusCode() >= 500) : null,
            'created_at' => now(),
        ]);
    }

    private function requestBody(Request $request, int $maxBytes): ?string
    {
        $body = $request->isJson() ? $request->json()->all() : $request->request->all();
        $input = $body;
        if ($request->query->count() > 0) {
            $input = ['query' => $request->query->all()] + ($body === [] ? [] : ['body' => $body]);
        }
        if ($input === []) {
            $raw = $request->getContent();

            return $raw === '' ? null : $this->truncate($raw, $maxBytes);
        }

        return $this->readable($this->redact($input), $maxBytes);
    }

    private function responseContent(Response $response, int $maxBytes): ?string
    {
        if (! $response instanceof HttpResponse && ! $response instanceof JsonResponse) {
            // Streamed and binary responses cannot be read back.
            return null;
        }
        $content = $response->getContent();
        if ($content === false || $content === '') {
            return null;
        }
        // Only a body that is kept whole is worth decoding to pretty-print: a
        // first sync can answer with megabytes of JSON.
        if (strlen($content) > $maxBytes) {
            return $this->truncate($content, $maxBytes);
        }
        $decoded = json_decode($content, true);

        return is_array($decoded) ? $this->readable($decoded, $maxBytes) ?? $content : $content;
    }

    /**
     * Pretty-printed JSON when it fits, else the compact encoding cut to size.
     *
     * @param  array<array-key, mixed>  $value
     */
    private function readable(array $value, int $maxBytes): ?string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $compact = json_encode($value, $flags);
        if ($compact === false) {
            return null;
        }
        if (strlen($compact) > $maxBytes) {
            return $this->truncate($compact, $maxBytes);
        }

        return $this->truncate(json_encode($value, $flags | JSON_PRETTY_PRINT) ?: $compact, $maxBytes);
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

    private function truncate(string $value, int $maxBytes): string
    {
        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        return mb_strcut($value, 0, $maxBytes)."\n… [truncated, ".number_format(strlen($value)).' bytes]';
    }

    /**
     * Client errors (401, 404, 422, 429…) are expected: their class and message
     * say everything, so only server errors keep the location and trace.
     */
    private function describe(Throwable $exception, bool $withTrace): string
    {
        $message = $exception::class.': '.$exception->getMessage();
        if ($withTrace) {
            $message .= "\n".$exception->getFile().':'.$exception->getLine()
                ."\n".$exception->getTraceAsString();
        }

        return mb_substr($message, 0, 8000);
    }
}
