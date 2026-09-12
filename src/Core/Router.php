<?php
declare(strict_types=1);

namespace Picklers\Core;

// ==============================================================================
// PICKLERS — Front-Controller Router
// ==============================================================================

class Router {
    private array $routes = [];

    public function get(string $path, array $handler): void {
        $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, array $handler): void {
        $this->addRoute('POST', $path, $handler);
    }

    public function any(string $path, array $handler): void {
        $this->addRoute('ANY', $path, $handler);
    }

    private function addRoute(string $method, string $path, array $handler): void {
        $this->routes[] = [
            'method' => $method,
            'path' => rtrim($path, '/') ?: '/',
            'handler' => $handler
        ];
    }

    public function dispatch(?Request $request = null): void {
        $req = $request ?? Request::createFromGlobals();
        $method = $req->getMethod();
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        
        // Strip query string
        if (($pos = strpos($uri, '?')) !== false) {
            $uri = substr($uri, 0, $pos);
        }
        $uri = urldecode($uri);

        // Dynamic base folder normalization
        $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        $scriptDir = str_replace('\\', '/', $scriptDir);
        if ($scriptDir !== '/' && $scriptDir !== '' && str_starts_with($uri, $scriptDir)) {
            $uri = substr($uri, strlen($scriptDir));
        }

        // Strip /public prefix if explicitly present
        if (str_starts_with($uri, '/public')) {
            $uri = substr($uri, 7);
        }

        // Backward compatibility for project folder name
        $projectBase = '/PICKLERS WEBDEV PROJECT';
        if (str_starts_with($uri, $projectBase)) {
            $uri = substr($uri, strlen($projectBase));
        }
        if (str_starts_with($uri, '/public')) {
            $uri = substr($uri, 7);
        }

        $uri = rtrim($uri, '/') ?: '/';

        // Match routes. Static paths win outright; a pattern route such as
        // '/app/owner/tournaments/{id}' is only consulted when no literal path
        // matched, so a future static '/app/owner/tournaments/new' would never
        // be swallowed by the pattern.
        $patternRoutes = [];

        foreach ($this->routes as $route) {
            if ($route['method'] !== 'ANY' && $route['method'] !== $method) {
                continue;
            }

            if (str_contains($route['path'], '{')) {
                $patternRoutes[] = $route;
                continue;
            }

            if ($route['path'] === $uri) {
                $this->invoke($route['handler'], $req);
                return;
            }
        }

        foreach ($patternRoutes as $route) {
            $params = $this->matchPattern($route['path'], $uri);
            if ($params !== null) {
                $this->invoke($route['handler'], $req, $params);
                return;
            }
        }

        // 404 Fallback
        http_response_code(404);
        if (str_starts_with($uri, '/api')) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => "Endpoint [".htmlspecialchars($uri)."] not found."], JSON_PRETTY_PRINT);
            exit;
        }

        $appUrl = class_exists('\Picklers\Helpers\Url') ? \Picklers\Helpers\Url::to('/app') : '/app';
        echo "<!DOCTYPE html><html lang='en'><head><title>404 Not Found</title></head><body style='font-family:sans-serif; background:#0A121F; color:#fff; text-align:center; padding:100px 20px;'><h1>404 — Page Not Found</h1><p>The requested page [".htmlspecialchars($uri)."] does not exist.</p><a href='".htmlspecialchars($appUrl)."' style='color:#10B981; font-weight:700;'>Return to Picklers App</a></body></html>";
        exit;
    }

    /**
     * Compare a '{placeholder}' pattern against a concrete URI.
     *
     * @return array<string,string>|null Captured params, or null when it does not match
     */
    private function matchPattern(string $pattern, string $uri): ?array {
        $patternSegments = explode('/', trim($pattern, '/'));
        $uriSegments = explode('/', trim($uri, '/'));

        if (count($patternSegments) !== count($uriSegments)) {
            return null;
        }

        $params = [];
        foreach ($patternSegments as $i => $segment) {
            $value = $uriSegments[$i];
            if (strlen($segment) > 2 && $segment[0] === '{' && $segment[-1] === '}') {
                if ($value === '') {
                    return null;
                }
                $params[substr($segment, 1, -1)] = $value;
                continue;
            }
            if ($segment !== $value) {
                return null;
            }
        }

        return $params;
    }

    /** @param array<string,string> $params */
    private function invoke(array $handler, Request $request, array $params = []): void {
        [$class, $action] = $handler;
        if (!class_exists($class)) {
            throw new \RuntimeException("Controller class [{$class}] not found.");
        }
        $controller = new $class();
        if (!method_exists($controller, $action)) {
            throw new \RuntimeException("Action [{$action}] not found in [{$class}].");
        }
        $controller->$action($request, $params);
    }
}
