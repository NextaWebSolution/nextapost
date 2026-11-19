<?php

declare(strict_types=1);

namespace App\Core;

final class App
{
    private static ?self $instance = null;
    public DB $db;
    public Router $router;
    public ?Request $request = null;

    public static function boot(string $root): self
    {
        Env::load($root . '/.env');
        date_default_timezone_set('UTC');
        mb_internal_encoding('UTF-8');
        $debug = (bool) Env::get('APP_DEBUG', false);
        ini_set('display_errors', $debug ? '1' : '0');
        error_reporting(E_ALL);
        // Never record function arguments (passwords, tokens) in exception traces / logs.
        ini_set('zend.exception_ignore_args', '1');
        set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
            if (!(error_reporting() & $no) || ($no & (E_DEPRECATED | E_USER_DEPRECATED))) {
                return false;
            }
            throw new \ErrorException($str, 0, $no, $file, $line);
        });
        $app = new self();
        $app->db = DB::fromEnv();
        $app->router = new Router();
        self::$instance = $app;
        Migrator::ensureCurrent($app->db);
        return $app;
    }

    public static function get(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Application has not been booted.');
        }
        return self::$instance;
    }

    public function run(): void
    {
        Session::start();
        $req = Request::capture();
        $this->request = $req;
        // Enforce HTTPS when the site is configured for it.
        if (str_starts_with((string) Env::get('APP_URL', ''), 'https://') && !is_https() && PHP_SAPI !== 'cli') {
            $u = parse_url((string) Env::get('APP_URL'));
            $origin = 'https://' . ($u['host'] ?? 'localhost') . (isset($u['port']) ? ':' . (int) $u['port'] : '');
            Response::redirect($origin . ($_SERVER['REQUEST_URI'] ?? '/'), 301)->send();
            return;
        }
        $this->loadRoutes();
        $key = (string) Env::get('APP_KEY', '');
        if (!str_starts_with($key, 'base64:') || strlen((string) base64_decode(substr($key, 7), true)) !== 32 || !filter_var((string) Env::get('APP_URL', ''), FILTER_VALIDATE_URL)) {
            Logger::error('Configuration error: APP_KEY or APP_URL in .env is missing or invalid.');
            $this->error($req, 500, 'The site is misconfigured (reference: ' . request_id() . '). The administrator should check APP_KEY and APP_URL in .env.')->send();
            return;
        }
        try {
            $res = $this->router->dispatch($req, $this);
        } catch (RedirectException $e) {
            $res = Response::redirect($e->url);
        } catch (HttpException $e) {
            $res = $this->error($req, $e->status, $e->getMessage());
        } catch (\Throwable $e) {
            Logger::error($e, ['request_id' => request_id(), 'path' => $req->path]);
            $msg = Env::get('APP_DEBUG')
                ? $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'
                : 'Something went wrong on our side. Please try again. If it keeps happening, give your administrator this reference: ' . request_id();
            $res = $this->error($req, 500, $msg);
        }
        $res->send();
    }

    public function loadRoutes(): void
    {
        $r = $this->router;
        require APP_ROOT . '/routes.php';
    }

    public function error(Request $req, int $status, string $message): Response
    {
        if ($req->wantsJson()) {
            return Response::json(['error' => ['message' => $message]], $status);
        }
        $titles = [401 => 'Please sign in', 403 => 'Access denied', 404 => 'Page not found', 405 => 'Not allowed', 419 => 'Session expired', 429 => 'Too many attempts', 500 => 'Server error'];
        try {
            $html = View::render('errors/error', ['status' => $status, 'heading' => $titles[$status] ?? 'Error', 'message' => $message], 'auth');
        } catch (\Throwable) {
            $html = '<h1>' . e((string) $status) . '</h1><p>' . e($message) . '</p>';
        }
        return Response::html($html, $status);
    }
}
