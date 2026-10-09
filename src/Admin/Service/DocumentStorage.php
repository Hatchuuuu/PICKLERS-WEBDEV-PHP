<?php
declare(strict_types=1);

namespace Picklers\Admin\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Locates private owner-application documents (permits, government IDs).
 *
 * A file is only ever served when (1) its name is a plain basename with an
 * allowed extension, (2) an application on file references it, (3) it resolves
 * — after realpath — inside one of the configured private directories, and
 * (4) its sniffed MIME type is an allowed document type. Anything else is
 * reported as "not found" without saying which check failed.
 */
final class DocumentStorage
{
    public const MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
    private const NAME_PATTERN = '/^[A-Za-z0-9_\-]{1,150}\.(pdf|jpe?g|png|webp)$/i';

    /** @var list<string> */
    private array $dirs;

    public function __construct(
        #[Autowire('%env(default:picklers.default_document_dir:PRIVATE_DOCUMENT_DIR)%')] string $primaryDir,
        #[Autowire('%picklers.legacy_document_dir%')] string $legacyDir,
    ) {
        $this->dirs = array_values(array_filter([$primaryDir, $legacyDir], static fn(string $d) => $d !== ''));
    }

    /** At least one private document directory exists and is readable. */
    public function isConfigured(): bool
    {
        foreach ($this->dirs as $dir) {
            if (is_dir($dir) && is_readable($dir)) {
                return true;
            }
        }

        return false;
    }

    public static function isAcceptableName(string $file): bool
    {
        return $file === basename($file) && preg_match(self::NAME_PATTERN, $file) === 1;
    }

    /** Absolute path of an existing, contained document, or null. */
    public function locate(string $file): ?string
    {
        if (!self::isAcceptableName($file)) {
            return null;
        }
        foreach ($this->dirs as $dir) {
            $base = realpath($dir);
            if ($base === false) {
                continue;
            }
            $candidate = realpath($base . DIRECTORY_SEPARATOR . $file);
            if ($candidate !== false && is_file($candidate) && str_starts_with($candidate, $base . DIRECTORY_SEPARATOR)) {
                return $candidate;
            }
        }

        return null;
    }

    public function mimeType(string $path): ?string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: null;

        return in_array($mime, self::MIME_TYPES, true) ? $mime : null;
    }

    /** "attached", "missing" (referenced but not on disk) or "none". */
    public function state(?string $file): string
    {
        if ($file === null || trim($file) === '') {
            return 'none';
        }

        return $this->locate(trim($file)) !== null ? 'attached' : 'missing';
    }
}
