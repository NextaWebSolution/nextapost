<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<int, array{0:string,1:string,2:array}> */
    private array $routes = [];

    /** @param bool $browser true = cookie session routes (CSRF + write rate limit); false = token-authenticated API routes */
    public function __construct(private bool $browser = true)
    {
    }

    public function get(string $path, array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function patch(string $path, array $handler): void
    {
        $this->add('PATCH', $path, $handler);
    }

    public function put(string $path, array $handler): void
    {
        $this->add('PUT', $path, $handler);
    }

    public function delete(string $path, array $handler): void
    {
        $this->add('DELETE', $path, $handler);
    }

    private function add(string $method, string $path, array $handler): void
    {
        $parts = preg_split('#(\{\w+\})#', $path, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $regex = '';
        foreach ($parts as $part) {
            if (preg_match('#^\{(\w+)\}$#', $part, $m)) {
                $regex .= '(?P<' . $m[1] . '>[^/]+)';
            } else {
                $regex .= preg_quote($part, '#');
            }
        }
        $this->routes[] = [$method, '#^' . $regex . '$#', $handler];
    }

    public function dispatch(Request $req, App $app): Response
    {
        $path = $req->path !== '/' ? rtrim($req->path, '/') : '/';
        $methodMismatch = false;
        foreach ($this->routes as [$method, $regex, $handler]) {
            if (!preg_match($regex, $path, $m)) {
                continue;
            }
            if ($method !== $req->method && !($method === 'GET' && $req->method === 'HEAD')) {
                $methodMismatch = true;
                continue;
            }
            if ($this->browser && !in_array($req->method, ['GET', 'HEAD'], true)) {
                if (!Csrf::check($req)) {
                    throw new HttpException(419, __('Your session expired. Refresh the page and try again.'));
                }
                // Abuse protection: cap state-changing requests per user (or IP when signed out).
                $who = isset($_SESSION['uid']) ? 'u' . (int) $_SESSION['uid'] : 'ip' . $req->ip();
                if (!RateLimiter::hit($app->db, 'write:' . $who, (int) Env::get('RATE_LIMIT_WRITES', 300), 300)) {
                    throw new HttpException(429, __('You’re doing that too fast. Wait a few minutes and try again.'));
                }
            }
            $params = array_values(array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY));
            [$class, $action] = $handler;
            $controller = new $class($app, $req);
            $result = $controller->$action(...$params);
            return $result instanceof Response ? $result : Response::html((string) $result);
        }
        throw new HttpException($methodMismatch ? 405 : 404, $methodMismatch ? __('That action isn’t allowed here.') : __('We couldn’t find that page.'));
    }
}
