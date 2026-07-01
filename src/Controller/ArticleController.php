<?php

namespace App\Controller;

use App\Entity\Article;
use App\Repository\ArticleRepository;
use App\Repository\CategoryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

final class ArticleController extends AbstractController
{
    private const PAGE_SIZE = 12;

    public function __construct(
        private ArticleRepository $articleRepository,
        private CategoryRepository $categoryRepository,
        private TagAwareCacheInterface $cache
    ) {}

    #[Route('/{locale}/articles/', name: 'app_article_index_locale', requirements: ['_locale' => 'fr|en'], defaults: ['locale' => 'fr'])]
    public function index(): Response
    {
        return $this->render('article/index.html.twig');
    }

    #[Route('/articles', name: 'app_article_list')]
    public function list(Request $request): Response
    {
        $categorySlug = $request->query->get('category') ?: null;
        $offset       = max(0, (int) $request->query->get('offset', 0));

        $cacheKeyTotal    = 'articles_total_cat_' . ($categorySlug ?? 'all');
        $cacheKeyArticles = 'articles_offset_' . $offset . '_size_' . self::PAGE_SIZE . '_cat_' . ($categorySlug ?? 'all');

        $total = $this->cache->get($cacheKeyTotal, function (ItemInterface $item) use ($categorySlug) {
            $item->expiresAfter(3600);
            $item->tag(['articles']);
            return $this->articleRepository->countAll($categorySlug);
        });

        $articles = $this->cache->get($cacheKeyArticles, function (ItemInterface $item) use ($offset, $categorySlug) {
            $item->expiresAfter(3600);
            $item->tag(['articles']);
            return $this->articleRepository->findPaginated(self::PAGE_SIZE, $offset, $categorySlug);
        });

        // Categories are not cached: they're a small list and their `articles`
        // lazy-load collection breaks when Doctrine entities are serialized/cached.
        $categories = $this->categoryRepository->findBy([], ['name' => 'ASC']);

        // Counts are a plain scalar array — safely cacheable.
        $categoryCounts = $this->cache->get('categories_article_counts', function (ItemInterface $item) {
            $item->expiresAfter(3600);
            $item->tag(['categories', 'articles']);
            return $this->categoryRepository->findAllWithArticleCounts();
        });

        $nextOffset = $offset + self::PAGE_SIZE;
        $hasMore    = $nextOffset < $total;

        if ($request->isXmlHttpRequest()) {
            $html = $this->renderView('article/_cards.html.twig', ['articles' => $articles]);
            return new JsonResponse([
                'html'       => $html,
                'hasMore'    => $hasMore,
                'nextOffset' => $nextOffset,
            ]);
        }

        return $this->render('article/list.html.twig', [
            'articles'       => $articles,
            'categories'     => $categories,
            'categoryCounts' => $categoryCounts,
            'activeCategory' => $categorySlug,
            'total'          => $total,
            'hasMore'        => $hasMore,
            'nextOffset'     => $nextOffset,
        ]);
    }

    #[Route('/article/{id}', name: 'app_article_show', requirements: ['id' => '\d+'])]
    public function show(Article $article): Response
    {
        return $this->render('article/show.html.twig', [
            'article' => $article,
        ]);
    }
}