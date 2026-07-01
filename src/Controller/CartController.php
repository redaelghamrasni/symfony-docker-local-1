<?php

namespace App\Controller;

use App\Entity\Article;
use App\Service\CartService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/cart')]
class CartController extends AbstractController
{
    public function __construct(
        private CartService $cartService
    ) {}

    /**
     * Afficher le panier
     */
    #[Route('', name: 'app_cart_index', methods: ['GET'])]
    public function index(): Response
    {
        $cart = $this->cartService->getCurrentCart();

        return $this->render('cart/index.html.twig', [
            'cart' => $cart,
        ]);
    }

    /**
     * Ajouter un article au panier
     */
    #[Route('/add/{id}', name: 'app_cart_add', methods: ['POST'])]
    public function add(Article $article, Request $request, TranslatorInterface $translator): Response
    {
        $quantity = $request->request->getInt('quantity', 1);
        $this->cartService->addArticle($article, $quantity);

        if ($request->isXmlHttpRequest() || $request->headers->get('X-Requested-With') === 'XMLHttpRequest') {
            $cart = $this->cartService->getCurrentCart();
            return $this->json([
                'success' => true,
                'message' => $translator->trans('cart.added_article', [
                    '%name%' => $article->getName()
                ]),
                'itemCount' => $cart->getItems()->count(),
                'imageUrl' => $article->getImageUrl(),
            ]);
        }

        $this->addFlash('success', $translator->trans('cart.added_article', [
            '%name%' => $article->getName()
        ]));

        $referer = $request->headers->get('referer');
        if ($referer) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('app_cart_index');
    }

    /**
     * Retirer un item du panier
     */
    #[Route('/remove/{id}', name: 'app_cart_remove', methods: ['POST'])]
    public function remove(int $id): Response
    {
        $this->cartService->removeItem($id);

        $this->addFlash('success', 'cart.item_removed');

        return $this->redirectToRoute('app_cart_index');
    }

    /**
     * Mettre à jour la quantité d'un item
     */
    #[Route('/update/{id}', name: 'app_cart_update', methods: ['POST'])]
    public function update(int $id, Request $request): Response
    {
        $quantity = $request->request->getInt('quantity', 1);
        
        $this->cartService->updateQuantity($id, $quantity);
        $cart = $this->cartService->getCurrentCart();

        if ($request->isXmlHttpRequest() || str_contains($request->headers->get('accept', ''), 'application/json')) {
            $updatedItem = null;
            foreach ($cart->getItems() as $item) {
                if ($item->getId() === $id) {
                    $updatedItem = $item;
                    break;
                }
            }

            return $this->json([
                'itemId' => $id,
                'quantity' => $quantity,
                'subtotal' => $updatedItem ? number_format($updatedItem->getSubtotal(), 2, ',', ' ') : '0,00',
                'total' => number_format($cart->getTotal(), 2, ',', ' '),
                'success' => true,
            ]);
        }

        return $this->redirectToRoute('app_cart_index');
    }

    /**
     * Vider le panier
     */
    #[Route('/clear', name: 'app_cart_clear', methods: ['POST'])]
    public function clear(): Response
    {
        $this->cartService->clear();

        $this->addFlash('success', 'cart.cleared');

        return $this->redirectToRoute('app_cart_index');
    }
}