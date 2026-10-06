<?php

namespace App\Controller\Admin;

use App\Service\LogFileService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Back-office log viewer, one section per log type.
 *
 * Each type is a Monolog channel with its own rotating file (see
 * config/packages/monolog.yaml). These files are a convenience copy — in
 * production the primary stream is still the container's stdout/stderr — so
 * what is visible here covers the current container's lifetime only.
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
        $grouped = $this->logFiles->groupedByType();

        $type = $request->query->get('type');

        if (!is_string($type) || !in_array($type, LogFileService::TYPES, true)) {
            // Open on the type that has the most recent activity, so the page
            // is useful on arrival rather than showing an arbitrary section.
            $type = $this->mostRecentlyWrittenType($grouped) ?? LogFileService::TYPES[0];
        }

        $files = $grouped[$type]['files'];

        // A specific file may be requested (an older rotation); otherwise show
        // the newest one of this type.
        $selected = $request->query->get('file');

        if (!is_string($selected) || !in_array($selected, array_column($files, 'name'), true)) {
            $selected = $files[0]['name'] ?? null;
        }

        $level = $request->query->get('level', '');
        $limit = min(max((int) $request->query->get('limit', 100), 10), 1000);

        return $this->render('admin/logs/index.html.twig', [
            'grouped'  => $grouped,
            'type'     => $type,
            'files'    => $files,
            'selected' => $selected,
            'entries'  => $selected ? $this->logFiles->tail($selected, $limit, $level) : [],
            'level'    => $level,
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

    /** @param array<string, array{latest: ?string, files: array}> $grouped */
    private function mostRecentlyWrittenType(array $grouped): ?string
    {
        $best = null;
        $bestTime = null;

        foreach ($grouped as $type => $data) {
            $modified = $data['files'][0]['modified'] ?? null;

            if ($modified !== null && ($bestTime === null || $modified > $bestTime)) {
                $best = $type;
                $bestTime = $modified;
            }
        }

        return $best;
    }
}
