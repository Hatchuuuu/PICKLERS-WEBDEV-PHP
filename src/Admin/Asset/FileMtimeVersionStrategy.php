<?php
declare(strict_types=1);

namespace Picklers\Admin\Asset;

use Symfony\Component\Asset\VersionStrategy\VersionStrategyInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * `?v=<mtime>` cache busting: stable between requests (the old `time()` query
 * string defeated browser caching on every page view) and changes when the file does.
 */
final class FileMtimeVersionStrategy implements VersionStrategyInterface
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/public')] private readonly string $publicDir,
    ) {
    }

    public function getVersion(string $path): string
    {
        $file = $this->publicDir . '/' . ltrim(strtok($path, '?') ?: $path, '/');

        return is_file($file) ? (string)filemtime($file) : '';
    }

    public function applyVersion(string $path): string
    {
        $version = $this->getVersion($path);

        return $version === '' ? $path : $path . '?v=' . $version;
    }
}
