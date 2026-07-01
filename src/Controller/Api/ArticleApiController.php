<?php

namespace App\Controller\Api;

use App\Repository\ArticleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/articles', name: 'api_articles_')]
class ArticleApiController extends AbstractController
{
    public function __construct(
        private ArticleRepository $articleRepository
    ) {}

    // GET /api/articles
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $limit  = min(50, max(1, (int) $request->query->get('limit', 10)));
        $offset = max(0, (int) $request->query->get('offset', 0));

        $articles = $this->articleRepository->findPaginated($limit, $offset, null);
        $total    = $this->articleRepository->countAll(null);

        $data = array_map(fn($article) => [
            'id'          => $article->getId(),
            'title'       => $article->getTitle(),
            'slug'        => $article->getSlug(),
            'description' => $article->getDescription(),
            'price'       => $article->getPrice(),
            'category'    => $article->getCategory()?->getName(),
            'createdAt'   => $article->getCreatedAt()?->format('Y-m-d H:i:s'),
        ], $articles);

        return $this->json([
            'data'   => $data,
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
        ]);
    }

    // GET /api/articles/{id}
    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $article = $this->articleRepository->find($id);

        if (!$article) {
            return $this->json(['error' => 'Article introuvable.'], 404);
        }

        return $this->json([
            'id'          => $article->getId(),
            'title'       => $article->getTitle(),
            'slug'        => $article->getSlug(),
            'description' => $article->getDescription(),
            'price'       => $article->getPrice(),
            'category'    => $article->getCategory()?->getName(),
            'createdAt'   => $article->getCreatedAt()?->format('Y-m-d H:i:s'),
        ]);
    }
}