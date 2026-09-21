<?php

namespace MonitorTrack\Support;

use Illuminate\Container\Container;

/**
 * Reads trace id, user id and route from the current request without causing
 * side effects: nothing is resolved from the container that wasn't already,
 * and the user is only read when the guard has already loaded it (no query).
 *
 * The container is looked up on every call. Octane serves each request from
 * its own clone of the application (Container::getInstance() while the
 * request runs), so the application the SDK booted with is not the request's.
 */
final class RequestScope
{
    /**
     * @param  Container|null  $container  a fixed container; null = the current one
     */
    public function __construct(private ?Container $container = null)
    {
    }

    /**
     * @return array{trace_id?:string, user_id?:string, route?:string}
     */
    public function __invoke(): array
    {
        $out = [];

        try {
            $app = $this->container ?? Container::getInstance();
        } catch (\Throwable) {
            return $out;
        }

        try {
            if ($app->resolved('request')) {
                $request = $app->make('request');

                $trace = self::traceId(
                    (string) $request->headers->get('traceparent', ''),
                    (string) $request->headers->get('x-request-id', ''),
                );
                if ($trace !== '') {
                    $out['trace_id'] = $trace;
                }

                $route = $request->route();
                if (is_object($route) && method_exists($route, 'uri')) {
                    $out['route'] = strtoupper($request->getMethod()).' /'.ltrim((string) $route->uri(), '/');
                }
            }
        } catch (\Throwable) {
            // no request context
        }

        try {
            if ($app->resolved('auth')) {
                $auth = $app->make('auth');
                if (! method_exists($auth, 'hasResolvedGuards') || $auth->hasResolvedGuards()) {
                    $guard = $auth->guard();
                    if (method_exists($guard, 'hasUser') && $guard->hasUser()) {
                        $id = $guard->user()?->getAuthIdentifier();
                        if (is_scalar($id) && (string) $id !== '') {
                            $out['user_id'] = (string) $id;
                        }
                    }
                }
            }
        } catch (\Throwable) {
            // no user
        }

        return $out;
    }

    /**
     * W3C traceparent "00-<32 hex trace id>-<16 hex span id>-<flags>", else
     * X-Request-Id.
     */
    public static function traceId(string $traceparent, string $requestId): string
    {
        if ($traceparent !== '' && preg_match('/^[0-9a-f]{2}-([0-9a-f]{32})-[0-9a-f]{16}-[0-9a-f]{2}/i', trim($traceparent), $m)
            && $m[1] !== str_repeat('0', 32)) {
            return strtolower($m[1]);
        }

        $requestId = trim($requestId);

        return $requestId === '' ? '' : EnvelopeEncoder::head($requestId, 128);
    }
}
