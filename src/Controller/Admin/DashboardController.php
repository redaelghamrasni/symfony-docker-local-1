<?php

namespace App\Controller\Admin;

use App\Repository\ArticleRepository;
use App\Repository\UserRepository;
use App\Repository\OrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin', name: 'admin_')]
class DashboardController extends AbstractController
{
    public function __construct(
        private ArticleRepository $articleRepository,
        private UserRepository $userRepository,
        private OrderRepository $orderRepository
    ) {}

    #[Route('/', name: 'dashboard')]
    public function index(): Response
    {
        $totalArticles = count($this->articleRepository->findAll());
        $totalUsers = count($this->userRepository->findAll());
        $totalOrders = count($this->orderRepository->findAll());
        $recentArticles = $this->articleRepository->findBy([], ['createdAt' => 'DESC'], 5);
        $recentUsers = $this->userRepository->findBy([], ['createdAt' => 'DESC'], 5);

        return $this->render('admin/dashboard.html.twig', [
            'totalArticles' => $totalArticles,
            'totalUsers' => $totalUsers,
            'totalOrders' => $totalOrders,
            'recentArticles' => $recentArticles,
            'recentUsers' => $recentUsers,
        ]);
    }
}
