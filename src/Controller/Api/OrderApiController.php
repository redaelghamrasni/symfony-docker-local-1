<?php
// src/Controller/Api/OrderApiController.php

namespace App\Controller\Api;

use App\Repository\OrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/orders', name: 'api_orders_')]
#[IsGranted('ROLE_USER')]  // ← toutes les routes nécessitent un token JWT
class OrderApiController extends AbstractController
{
    public function __construct(
        private OrderRepository $orderRepository
    ) {}

    // GET /api/orders
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user   = $this->getUser();
        $orders = $this->orderRepository->findBy(['user' => $user]);

        $data = array_map(fn($order) => [
            'id'        => $order->getId(),
            'status'    => $order->getStatus(),
            'total'     => $order->getTotal(),
            'createdAt' => $order->getCreatedAt()?->format('Y-m-d H:i:s'),
            'items'     => array_map(fn($item) => [
                'article'   => $item->getArticle()?->getTitle(),
                'quantity'  => $item->getQuantity(),
                'unitPrice' => $item->getUnitPrice(),
                'subtotal'  => $item->getSubtotal(),
            ], $order->getItems()->toArray()),
        ], $orders);

        return $this->json(['data' => $data]);
    }

    // GET /api/orders/{id}
    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $order = $this->orderRepository->find($id);

        if (!$order) {
            return $this->json(['error' => 'Commande introuvable.'], 404);
        }

        // Vérifier que la commande appartient à l'utilisateur connecté
        if ($order->getUser() !== $this->getUser()) {
            return $this->json(['error' => 'Accès refusé.'], 403);
        }

        return $this->json([
            'id'        => $order->getId(),
            'status'    => $order->getStatus(),
            'total'     => $order->getTotal(),
            'createdAt' => $order->getCreatedAt()?->format('Y-m-d H:i:s'),
        ]);
    }
}