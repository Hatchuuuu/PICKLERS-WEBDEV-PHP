<?php
declare(strict_types=1);

namespace Picklers\Core;

// ==============================================================================
// PICKLERS — Unified HTTP Request Parser
// ==============================================================================

class Request {
    private array $get;
    private array $post;
    private array $json;
    private array $server;

    public function __construct() {
        $this->get = $_GET;
        $this->post = $_POST;
        $this->server = $_SERVER;
        
        $raw = file_get_contents('php://input');
        $contentType = $this->server['CONTENT_TYPE'] ?? '';
        if (!empty($raw) && stripos($contentType, 'application/json') !== false) {
            $decoded = json_decode($raw, true);
            $this->json = is_array($decoded) ? $decoded : [];
        } else {
            $this->json = [];
        }
    }

    public static function createFromGlobals(): self {
        return new self();
    }

    public function getMethod(): string {
        return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    public function getUri(): string {
        $uri = $this->server['REQUEST_URI'] ?? '/';
        $pos = strpos($uri, '?');
        if ($pos !== false) {
            $uri = substr($uri, 0, $pos);
        }
        return rtrim($uri, '/') ?: '/';
    }

    public function input(string $key, mixed $default = null): mixed {
        if (isset($this->json[$key])) return $this->json[$key];
        if (isset($this->post[$key])) return $this->post[$key];
        if (isset($this->get[$key])) return $this->get[$key];
        return $default;
    }

    public function all(): array {
        return array_merge($this->get, $this->post, $this->json);
    }

    public function query(string $key, mixed $default = null): mixed {
        return $this->get[$key] ?? $default;
    }

    public function header(string $key, ?string $default = null): ?string {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
        return $this->server[$serverKey] ?? $default;
    }
}
