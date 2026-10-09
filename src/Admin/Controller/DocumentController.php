<?php
declare(strict_types=1);

namespace Picklers\Admin\Controller;

use Picklers\Admin\Security\Capability;
use Picklers\Admin\Service\ApplicationReviewService;
use Picklers\Admin\Service\AuditLog;
use Picklers\Admin\Service\DocumentStorage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Streams a private application document (Mayor's permit, government ID) to an
 * administrator. Files live outside the web root and are never linked publicly.
 * Every refusal is the same 404 so the endpoint never confirms which files exist.
 * Each successful view is audited (these are identity documents).
 */
final class DocumentController extends AbstractAdminController
{
    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly ApplicationReviewService $applications,
    ) {
    }

    #[Route('/admin/document', name: 'admin_document', methods: ['GET'])]
    #[Route('/admin/document.php', name: 'admin_document_php', methods: ['GET'])]
    public function show(Request $request): Response
    {
        $this->requireCapability(Capability::VIEW_DOCUMENTS, 'document.view', 'document');
        $file = (string)$request->query->get('file', '');
        $notFound = static fn(): Response => new Response('Document not found.', 404, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store, private']);

        if (!DocumentStorage::isAcceptableName($file)) {
            return $notFound();
        }
        $applicationId = $this->applications->documentIsOnFile($file);
        if ($applicationId === null) {
            return $notFound();
        }
        $path = $this->storage->locate($file);
        $mime = $path !== null ? $this->storage->mimeType($path) : null;
        if ($path === null || $mime === null) {
            return $notFound();
        }
        $this->auditLog->record('document.view', 'application', $applicationId, ['file' => $file]);

        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ], false, null, false, false);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $file);

        return $response;
    }
}
