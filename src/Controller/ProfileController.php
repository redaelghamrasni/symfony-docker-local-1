<?php

namespace App\Controller;

use App\Entity\Address;
use App\Form\AddressType;
use App\Form\UserType;
use App\Repository\OrderRepository;
use App\Service\CartService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Doctrine\ORM\EntityManagerInterface;

#[IsGranted('ROLE_USER')]
class ProfileController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrderRepository $orderRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private CartService $cartService
    ) {}

    #[Route('/profile', name: 'app_profile_index', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();

        return $this->render('profile/index.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/profile/edit', name: 'app_profile_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $user = $this->getUser();
        $form = $this->createForm(UserType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            if ($plainPassword) {
                $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
            }

            $user->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('success', 'profile.updated');
            return $this->redirectToRoute('app_profile_index');
        }

        return $this->render('profile/edit.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/profile/address/new', name: 'app_profile_address_new', methods: ['GET', 'POST'])]
    public function newAddress(Request $request): Response
    {
        $address = new Address();
        $address->setUser($this->getUser());

        $form = $this->createForm(AddressType::class, $address);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($address);
            $this->entityManager->flush();

            $this->addFlash('success', 'profile.address_added');
            return $this->redirectToRoute('app_profile_index');
        }

        return $this->render('profile/address_new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/profile/orders', name: 'app_profile_orders', methods: ['GET'])]
    public function orders(): Response
    {
        $user = $this->getUser();
        $orders = $this->orderRepository->findByUserOrdered($user);

        return $this->render('profile/orders.html.twig', [
            'orders' => $orders,
            'user' => $user,
        ]);
    }

    #[Route('/profile/orders/{id}', name: 'app_profile_order_show', methods: ['GET'])]
    public function orderShow(int $id): Response
    {
        $user = $this->getUser();
        $order = $this->orderRepository->find($id);

        if (!$order || $order->getUser() !== $user) {
            throw $this->createAccessDeniedException('Commande non trouvée.');
        }

        return $this->render('profile/order_show.html.twig', [
            'order' => $order,
        ]);
    }

    #[Route('/profile/orders/{id}/order-again', name: 'app_profile_order_again', methods: ['GET'])]
    public function orderAgain(int $id): Response
    {
        $user = $this->getUser();
        $order = $this->orderRepository->find($id);

        if (!$order || $order->getUser() !== $user) {
            throw $this->createAccessDeniedException('Commande non trouvée.');
        }

        foreach ($order->getItems() as $item) {
            $this->cartService->addArticle($item->getArticle(), $item->getQuantity());
        }

        $this->addFlash('success', 'profile.order_added_to_cart');
        return $this->redirectToRoute('app_cart_index');
    }
}
