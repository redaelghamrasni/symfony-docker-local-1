<?php

namespace App\Controller;

use App\Repository\ArticleRepository;
use App\Repository\CategoryRepository;
use App\Repository\OrderRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    public function __construct(
        private ArticleRepository $articleRepository,
        private CategoryRepository $categoryRepository,
        private OrderRepository $orderRepository,
        private UserRepository $userRepository
    ) {}

    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        // Get featured articles (latest 6)
        $featuredArticles = $this->articleRepository->findBy([], ['createdAt' => 'DESC'], 6);

        // Get some stats for the homepage
        $totalArticles = $this->articleRepository->count([]);
        $totalUsers = $this->userRepository->count([]);
        $totalOrders = $this->orderRepository->count([]);

        $categories     = $this->categoryRepository->findBy([], ['name' => 'ASC']);
        $categoryCounts = $this->categoryRepository->findAllWithArticleCounts();

        return $this->render('home/index.html.twig', [
            'featured_articles' => $featuredArticles,
            'categories'        => $categories,
            'categoryCounts'    => $categoryCounts,
            'total_articles'    => $totalArticles,
            'total_users'       => $totalUsers,
            'total_orders'      => $totalOrders,
        ]);
    }

    #[Route('/', name: 'app_home_locale', requirements: ['_locale' => 'en|fr'])]
    public function indexWithLocale(): Response
    {
        return $this->redirectToRoute('app_home');
    }
}
