<?php

namespace App\Controller\Admin;

use App\Service\LogFileService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Back-office log viewer.
 *
 * Reads the rotating files written by the business/app handlers (see
 * config/packages/monolog.yaml). These are a convenience copy — the primary
 * stream is still the container's stdout/stderr — so what is visible here is
 * limited to the current container's lifetime.
 */
#[Route('/admin/logs', name: 'admin_logs_')]
#[IsGranted('ROLE_ADMIN')]
class LogController extends AbstractController
{
    public function __construct(private readonly LogFileService $logFiles)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $files = $this->logFiles->listFiles();

        // Default to the newest file so the page is useful on arrival.
        $selected = $request->query->get('file') ?: ($files[0]['name'] ?? null);
        $level    = $request->query->get('level', '');
        $channel  = $request->query->get('channel', '');
        $limit    = min(max((int) $request->query->get('limit', 100), 10), 1000);

        $entries  = $selected ? $this->logFiles->tail($selected, $limit, $level, $channel) : [];
        $channels = $selected ? $this->logFiles->channelsIn($selected) : [];

        return $this->render('admin/logs/index.html.twig', [
            'files'    => $files,
            'selected' => $selected,
            'entries'  => $entries,
            'channels' => $channels,
            'level'    => $level,
            'channel'  => $channel,
            'limit'    => $limit,
        ]);
    }

    /**
     * The file name is validated inside the service against the files actually
     * present, so a crafted name cannot reach an arbitrary path.
     */
    #[Route('/download/{name}', name: 'download', methods: ['GET'], requirements: ['name' => '[A-Za-z0-9._-]+'])]
    public function download(string $name): Response
    {
        $response = $this->logFiles->download($name);

        if ($response === null) {
            throw $this->createNotFoundException('Log file not found.');
        }

        return $response;
    }
}
