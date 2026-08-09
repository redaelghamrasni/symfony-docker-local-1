<?php

namespace App\Controller\Admin;

use App\Entity\FaqEntry;
use App\Form\Admin\FaqEntryType;
use App\Repository\FaqEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/faq', name: 'admin_faq_')]
class FaqController extends AbstractController
{
    public function __construct(
        private FaqEntryRepository $faqEntryRepository,
        private EntityManagerInterface $entityManager,
    ) {}

    #[Route('/', name: 'index')]
    public function index(): Response
    {
        $pairs = $this->faqEntryRepository->findAllGroupedByGroupKey();

        return $this->render('admin/faq/index.html.twig', [
            'pairs' => $pairs,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $form = $this->createForm(FaqEntryType::class, [
            'category_fr' => '',
            'question_fr' => '',
            'answer_fr' => '',
            'category_en' => '',
            'question_en' => '',
            'answer_en' => '',
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $groupKey = bin2hex(random_bytes(8));

            $fr = (new FaqEntry())->setLocale('fr')->setGroupKey($groupKey)
                ->setCategory($data['category_fr'])
                ->setQuestion($data['question_fr'])
                ->setAnswer($data['answer_fr']);
            $en = (new FaqEntry())->setLocale('en')->setGroupKey($groupKey)
                ->setCategory($data['category_en'])
                ->setQuestion($data['question_en'])
                ->setAnswer($data['answer_en']);

            $this->entityManager->persist($fr);
            $this->entityManager->persist($en);
            $this->entityManager->flush();

            $this->addFlash('success', 'admin.faq.created');
            return $this->redirectToRoute('admin_faq_index');
        }

        return $this->render('admin/faq/form.html.twig', [
            'form' => $form->createView(),
            'isEdit' => false,
        ]);
    }

    #[Route('/{groupKey}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(string $groupKey, Request $request): Response
    {
        ['fr' => $fr, 'en' => $en] = $this->faqEntryRepository->findByGroupKey($groupKey);

        if (!$fr && !$en) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(FaqEntryType::class, [
            'category_fr' => $fr?->getCategory() ?? '',
            'question_fr' => $fr?->getQuestion() ?? '',
            'answer_fr' => $fr?->getAnswer() ?? '',
            'category_en' => $en?->getCategory() ?? '',
            'question_en' => $en?->getQuestion() ?? '',
            'answer_en' => $en?->getAnswer() ?? '',
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            if (!$fr) {
                $fr = (new FaqEntry())->setLocale('fr')->setGroupKey($groupKey);
                $this->entityManager->persist($fr);
            }
            $fr->setCategory($data['category_fr'])
                ->setQuestion($data['question_fr'])
                ->setAnswer($data['answer_fr']);

            if (!$en) {
                $en = (new FaqEntry())->setLocale('en')->setGroupKey($groupKey);
                $this->entityManager->persist($en);
            }
            $en->setCategory($data['category_en'])
                ->setQuestion($data['question_en'])
                ->setAnswer($data['answer_en']);

            $this->entityManager->flush();

            $this->addFlash('success', 'admin.faq.updated');
            return $this->redirectToRoute('admin_faq_index');
        }

        return $this->render('admin/faq/form.html.twig', [
            'form' => $form->createView(),
            'isEdit' => true,
            'groupKey' => $groupKey,
            'fr' => $fr,
            'en' => $en,
        ]);
    }

    #[Route('/{groupKey}/delete', name: 'delete', methods: ['POST'])]
    public function delete(string $groupKey, Request $request): Response
    {
        if ($this->isCsrfTokenValid('delete' . $groupKey, $request->request->get('_token'))) {
            foreach ($this->faqEntryRepository->findBy(['groupKey' => $groupKey]) as $entry) {
                $this->entityManager->remove($entry);
            }
            $this->entityManager->flush();

            $this->addFlash('success', 'admin.faq.deleted');
        }

        return $this->redirectToRoute('admin_faq_index');
    }
}
