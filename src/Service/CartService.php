<?php

namespace App\Service;

use App\Entity\Cart;
use App\Entity\CartItem;
use App\Entity\Article;
use App\Repository\CartRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class CartService
{
    public function __construct(
        private EntityManagerInterface $em,
        private CartRepository $cartRepository,
        private RequestStack $requestStack
    ) {}

    /**
     * Obtenir le panier courant (ou en créer un nouveau)
     */
    public function getCurrentCart(): Cart
    {
        $session = $this->requestStack->getSession();
        $cartId = $session->get('cart_id');

        if ($cartId) {
            $cart = $this->cartRepository->find($cartId);
            if ($cart) {
                return $cart;
            }
        }

        // Créer un nouveau panier
        $cart = new Cart();
        $this->em->persist($cart);
        $this->em->flush();

        $session->set('cart_id', $cart->getId());

        return $cart;
    }

    /**
     * Ajouter un article au panier
     */
    public function addArticle(Article $article, int $quantity = 1): void
    {
        $cart = $this->getCurrentCart();

        // Vérifier si l'article existe déjà dans le panier
        foreach ($cart->getItems() as $item) {
            if ($item->getArticle()->getId() === $article->getId()) {
                // Article déjà présent : augmenter la quantité
                $item->setQuantity($item->getQuantity() + $quantity);
                $cart->recalculateTotal();
                $this->em->flush();
                return;
            }
        }

        // Nouvel article : créer un CartItem
        $cartItem = new CartItem($article, $quantity);
        $cart->addItem($cartItem);

        $this->em->persist($cartItem);
        $this->em->flush();
    }

    /**
     * Retirer un item du panier
     */
    public function removeItem(int $itemId): void
    {
        $cart = $this->getCurrentCart();
        
        foreach ($cart->getItems() as $item) {
            if ($item->getId() === $itemId) {
                $cart->removeItem($item);
                $this->em->remove($item);
                break;
            }
        }

        $this->em->flush();
    }

    /**
     * Mettre à jour la quantité d'un item
     */
    public function updateQuantity(int $itemId, int $quantity): void
    {
        if ($quantity < 1) {
            $this->removeItem($itemId);
            return;
        }

        $cart = $this->getCurrentCart();
        
        foreach ($cart->getItems() as $item) {
            if ($item->getId() === $itemId) {
                $item->setQuantity($quantity);
                break;
            }
        }

        $cart->recalculateTotal();
        $this->em->flush();
    }

    /**
     * Vider complètement le panier
     */
    public function clear(): void
    {
        $cart = $this->getCurrentCart();
        $cart->clear();
        $this->em->flush();
    }

    /**
     * Obtenir le nombre total d'articles dans le panier
     */
    public function getItemCount(): int
    {
        $cart = $this->getCurrentCart();
        return $cart->getItemCount();
    }
}