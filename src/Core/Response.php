<?php
declare(strict_types=1);

namespace Picklers\Core;

// ==============================================================================
// PICKLERS — Unified HTTP Response Renderer
// ==============================================================================

class Response {
    public static function view(string $template, array $data = []): void {
        extract($data, EXTR_SKIP);
        $file = VIEWS_PATH . '/' . str_replace('.', '/', $template) . '.php';
        if (!file_exists($file)) {
            throw new \RuntimeException("View [{$template}] not found at {$file}");
        }
        require $file;
        exit;
    }

    public static function json(array $data, int $status = 200): void {
        http_response_code($status);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($data);
        if (defined('TESTING_MODE') && TESTING_MODE) {
            return;
        }
        exit;
    }

    public static function redirect(string $url, int $status = 302): void {
        http_response_code($status);
        if (!preg_match('#^https?://#i', $url) && class_exists('\Picklers\Helpers\Url')) {
            $url = \Picklers\Helpers\Url::to($url);
        }
        if (!headers_sent()) {
            header("Location: {$url}");
        }
        if (defined('TESTING_MODE') && TESTING_MODE) {
            return;
        }
        exit;
    }
}
