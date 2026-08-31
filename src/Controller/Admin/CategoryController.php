<?php

namespace App\Controller\Admin;

use App\Entity\Category;
use App\Form\CategoryType;
use App\Message\ReindexEntityMessage;
use App\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/admin/categories', name: 'admin_categories_')]
class CategoryController extends AbstractController
{
    public function __construct(
        private CategoryRepository $categoryRepository,
        private EntityManagerInterface $entityManager,
        private SluggerInterface $slugger,
        private MessageBusInterface $messageBus,
    ) {}

    #[Route('/', name: 'index')]
    public function index(Request $request): Response
    {
        $idsParam = $request->query->get('ids');

        if ($idsParam !== null) {
            $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $idsParam)))));
            $categories = $this->categoryRepository->findByIds($ids);
        } else {
            $categories = $this->categoryRepository->findBy([], ['name' => 'ASC']);
        }

        if ($request->isXmlHttpRequest()) {
            $html = $this->renderView('admin/categories/_rows.html.twig', ['categories' => $categories]);
            return new JsonResponse([
                'html'  => $html,
                'total' => count($categories),
            ]);
        }

        return $this->render('admin/categories/index.html.twig', [
            'categories' => $categories,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $category = new Category();
        $form = $this->createForm(CategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $category->setSlug(
                strtolower($this->slugger->slug($category->getName())->toString())
            );
            $this->entityManager->persist($category);
            $this->entityManager->flush();
            $this->messageBus->dispatch(new ReindexEntityMessage('category', $category->getId()));

            $this->addFlash('success', 'admin.categories.created');
            return $this->redirectToRoute('admin_categories_index');
        }

        return $this->render('admin/categories/form.html.twig', [
            'form' => $form->createView(),
            'category' => $category,
            'isEdit' => false,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Category $category, Request $request): Response
    {
        $form = $this->createForm(CategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $category->setSlug(
                strtolower($this->slugger->slug($category->getName())->toString())
            );
            $this->entityManager->flush();
            $this->messageBus->dispatch(new ReindexEntityMessage('category', $category->getId()));

            $this->addFlash('success', 'admin.categories.updated');
            return $this->redirectToRoute('admin_categories_index');
        }

        return $this->render('admin/categories/form.html.twig', [
            'form' => $form->createView(),
            'category' => $category,
            'isEdit' => true,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Category $category, Request $request): Response
    {
        if ($this->isCsrfTokenValid('delete' . $category->getId(), $request->request->get('_token'))) {
            $id = $category->getId();
            $this->entityManager->remove($category);
            $this->entityManager->flush();
            $this->messageBus->dispatch(new ReindexEntityMessage('category', $id, remove: true));

            $this->addFlash('success', 'admin.categories.deleted');
        }

        return $this->redirectToRoute('admin_categories_index');
    }
}
