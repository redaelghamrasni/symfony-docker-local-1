<?php

namespace App\Controller;

use App\Repository\FaqEntryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class FaqController extends AbstractController
{
    public function __construct(
        private FaqEntryRepository $faqEntryRepository,
    ) {}

    #[Route('/faq', name: 'app_faq_index')]
    public function index(Request $request): Response
    {
        $groupedEntries = $this->faqEntryRepository->findByLocaleGroupedByCategory($request->getLocale());

        return $this->render('faq/index.html.twig', [
            'groupedEntries' => $groupedEntries,
        ]);
    }
}
