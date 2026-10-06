<?php

namespace App\Controller;

use App\Service\MeilisearchService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class SearchApiController extends AbstractController
{
    // Catalogue indexes: readable by anyone (public autocomplete, search page).
    private const PUBLIC_INDEXES = ['articles', 'categories'];

    // Back-office indexes: they hold personal data (names, emails), so they
    // require ROLE_ADMIN. This route lives under /{_locale}/api/search, which
    // does NOT match the ^/api firewall — it runs on the session firewall, so
    // the check has to happen here rather than in security.yaml.
    private const ADMIN_INDEXES = ['users', 'orders'];

    public function __construct(
        private MeilisearchService $meilisearch,
    ) {}

    #[Route('/api/search', name: 'api_search', methods: ['GET'], requirements: ['_locale' => 'en|fr'])]
    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->query->get('q', ''));
        if ($query === '') {
            return new JsonResponse(['hits' => [], 'totalHits' => 0, 'processingTimeMs' => 0]);
        }

        $limit = min(max((int) $request->query->get('limit', 6), 1), 50);

        // Whitelist to prevent arbitrary access to Meilisearch indexes.
        $index = (string) $request->query->get('index', 'articles');

        if (in_array($index, self::ADMIN_INDEXES, true)) {
            if (!$this->isGranted('ROLE_ADMIN')) {
                return new JsonResponse(['error' => 'Forbidden'], 403);
            }
        } elseif (!in_array($index, self::PUBLIC_INDEXES, true)) {
            $index = 'articles';
        }

        $result = $this->meilisearch->search($index, $query, ['limit' => $limit]);

        return new JsonResponse([
            'hits' => $result['hits'],
            'totalHits' => $result['totalHits'],
            'processingTimeMs' => $result['processingTimeMs'],
        ]);
    }
}