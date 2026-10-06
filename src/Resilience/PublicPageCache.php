<?php

declare(strict_types=1);

namespace Fnlla\Php\Resilience;

use Closure;
use Fnlla\Php\Http\Request;
use Fnlla\Php\Http\Response;
use Fnlla\Php\Middleware\MiddlewareInterface;

/** Install after trust/access middleware. Explicitly public, stateless HTML only. */
final class PublicPageCache implements MiddlewareInterface
{
    private Closure $clock;

    public function __construct(private DisposableCache $cache, private ResilienceEvents $events,
        private array $settings, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function handle(Request $request, callable $next): mixed
    {
        if (!$this->eligible($request)) { return $next($request); }
        $key = $this->key($request->path());
        $entry = $this->snapshot($request->path());
        $age = is_array($entry) ? max(0, ($this->clock)() - $entry['stored_at']) : 0;
        $this->events->emit($entry === null ? 'cache_miss' : 'cache_hit', ['cache' => 'public_page'], false);
        if ($entry !== null && $age < max(0, (int) ($this->settings['fresh_ttl'] ?? 300))) {
            return $this->restore($entry, $age, false);
        }
        try { $response = $next($request); } catch (\Throwable $exception) {
            $failure = DependencyFailure::classify($exception);
            if ($failure === null) { throw $exception; }
            $this->events->emit('dependency_failed', ['dependency' => $failure->dependency]);
            if ($failure->dependency === 'database') { $this->events->emit('database_unavailable'); }
            if ($entry !== null && $this->eligible($request) && !$this->nativeCookie()) { return $this->restore($entry, $age, true); }
            throw $failure;
        }
        if (!$response instanceof Response) { return $response; }
        if (in_array($response->status(), [502, 503, 504], true) && $entry !== null && $this->eligible($request)
            && !$this->nativeCookie() && !array_key_exists('set-cookie', array_change_key_case($response->headers(), CASE_LOWER))) {
            return $this->restore($entry, $age, true);
        }
        if ($request->method() === 'GET' && $this->safeResponse($response)) {
            $headers = array_change_key_case($response->headers(), CASE_LOWER);
            $safeHeaders = array_intersect_key($headers, array_flip(['content-type', 'content-language', 'content-security-policy',
                'x-content-type-options', 'referrer-policy', 'permissions-policy', 'x-frame-options']));
            $this->cache->put($key, ['stored_at' => ($this->clock)(), 'body' => $response->body(), 'headers' => $safeHeaders], $this->maxAge());
        }
        return $response;
    }

    public function snapshot(string $path): ?array
    {
        if (!in_array($path, (array) ($this->settings['paths'] ?? []), true)) { return null; }
        $entry = $this->cache->get($this->key($path));
        if (!is_array($entry) || !is_int($entry['stored_at'] ?? null) || !is_string($entry['body'] ?? null)
            || !is_array($entry['headers'] ?? null) || $entry['stored_at'] > ($this->clock)()
            || ($this->clock)() - $entry['stored_at'] >= $this->maxAge()) { return null; }
        $allowed = ['content-type', 'content-language', 'content-security-policy', 'x-content-type-options',
            'referrer-policy', 'permissions-policy', 'x-frame-options'];
        foreach ($entry['headers'] as $name => $value) {
            if (!in_array($name, $allowed, true) || !is_string($value) || preg_match('/[\r\n\x00]/', $value)) { return null; }
        }
        if (!str_starts_with(strtolower($entry['headers']['content-type'] ?? ''), 'text/html')
            || strlen($entry['body']) > 5242880 || preg_match('/\b(?:nonce\s*=|<form\b|csrf|_token)/i', $entry['body'])) { return null; }
        return $entry;
    }

    private function key(string $path): string
    {
        return 'public-page:' . hash('sha256', (string) ($this->settings['namespace'] ?? 'v1') . ':' . $path);
    }

    private function maxAge(): int { return max(1, min(604800, (int) ($this->settings['last_known_good_ttl'] ?? 86400))); }

    private function eligible(Request $request): bool
    {
        $uri = (string) $request->server('REQUEST_URI', $request->path());
        $canonicalHost = parse_url((string) config('app.base_url', ''), PHP_URL_HOST);
        $requestHost = parse_url('http://' . (string) $request->header('Host', ''), PHP_URL_HOST);
        return ($this->settings['enabled'] ?? false) === true
            && in_array($request->method(), ['GET', 'HEAD'], true)
            && in_array($request->originalMethod(), ['GET', 'HEAD'], true)
            && in_array($request->path(), (array) ($this->settings['paths'] ?? []), true)
            && $uri === $request->path() && $request->all() === [] && $request->rawBody() === ''
            && $request->cookies() === [] && $request->header('Cookie', '') === ''
            && $request->header('Authorization', '') === '' && $request->header('Range', '') === ''
            && $request->header('Accept-Language', '') === '' && !$request->expectsJson()
            && $request->header('X-Requested-With', '') === ''
            && (!is_string($canonicalHost) || (is_string($requestHost) && strcasecmp($canonicalHost, $requestHost) === 0))
            && session_status() !== PHP_SESSION_ACTIVE && empty($_SESSION)
            && !$this->nativeCookie()
            && !preg_match('~^/(?:api|account|login|logout|register|member-login|checkout|order|download|developer|maintenance)(?:/|$)~', $request->path());
    }

    private function safeResponse(Response $response): bool
    {
        $headers = array_change_key_case($response->headers(), CASE_LOWER);
        $control = strtolower((string) ($headers['cache-control'] ?? ''));
        $body = $response->body();
        return $response->status() === 200 && ($headers['x-fnlla-public-page'] ?? '') === '1'
            && str_starts_with(strtolower((string) ($headers['content-type'] ?? '')), 'text/html')
            && !array_key_exists('set-cookie', $headers) && !array_key_exists('vary', $headers)
            && !preg_match('/private|no-store|no-cache/', $control)
            && !str_contains(strtolower((string) ($headers['content-security-policy'] ?? '')), 'nonce-')
            && !preg_match('/\b(?:nonce\s*=|<form\b|csrf|_token)/i', $body)
            && strlen($body) <= max(1024, min(5242880, (int) ($this->settings['max_body_bytes'] ?? 1048576)))
            && session_status() !== PHP_SESSION_ACTIVE && empty($_SESSION)
            && !$this->nativeCookie();
    }

    private function nativeCookie(): bool
    {
        foreach (headers_list() as $header) { if (str_starts_with(strtolower($header), 'set-cookie:')) { return true; } }
        return false;
    }

    private function restore(array $entry, int $age, bool $stale): Response
    {
        if ($stale) {
            $this->events->emit('stale_page_served', ['status' => 'degraded']);
            $this->events->emit('fallback_used', ['fallback' => 'last_known_good']);
        }
        // Keep shared edge caches from extending our explicit maximum stale age.
        return Response::html($entry['body'], 200, $entry['headers'])->withHeaders([
            'Age' => (string) $age, 'Cache-Control' => 'no-store',
            'X-FNLLA-Status' => $stale ? 'degraded' : 'healthy',
            'X-FNLLA-Cache' => $stale ? 'last-known-good' : 'fresh',
        ]);
    }
}
