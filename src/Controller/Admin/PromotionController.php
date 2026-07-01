<?php

namespace App\Controller\Admin;

use App\Entity\Promotion;
use App\Form\PromotionType;
use App\Repository\PromotionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/promotions', name: 'admin_promotions_')]
class PromotionController extends AbstractController
{
    public function __construct(
        private PromotionRepository $promotionRepository,
        private EntityManagerInterface $entityManager,
    ) {}

    #[Route('/', name: 'index')]
    public function index(): Response
    {
        $promotions = $this->promotionRepository->findBy([], ['createdAt' => 'DESC']);

        return $this->render('admin/promotions/index.html.twig', [
            'promotions' => $promotions,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $promotion = new Promotion();
        $form = $this->createForm(PromotionType::class, $promotion);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($promotion);
            $this->entityManager->flush();

            $this->addFlash('success', 'admin.promotions.created');
            return $this->redirectToRoute('admin_promotions_index');
        }

        return $this->render('admin/promotions/form.html.twig', [
            'form' => $form->createView(),
            'promotion' => $promotion,
            'isEdit' => false,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Promotion $promotion, Request $request): Response
    {
        $form = $this->createForm(PromotionType::class, $promotion);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', 'admin.promotions.updated');
            return $this->redirectToRoute('admin_promotions_index');
        }

        return $this->render('admin/promotions/form.html.twig', [
            'form' => $form->createView(),
            'promotion' => $promotion,
            'isEdit' => true,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Promotion $promotion, Request $request): Response
    {
        if ($this->isCsrfTokenValid('delete' . $promotion->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($promotion);
            $this->entityManager->flush();

            $this->addFlash('success', 'admin.promotions.deleted');
        }

        return $this->redirectToRoute('admin_promotions_index');
    }
}
