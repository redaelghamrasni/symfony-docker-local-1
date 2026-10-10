<?php

namespace App\Controller\Admin;

use App\Entity\Quote;
use App\Repository\OrderRepository;
use App\Repository\QuoteRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Back-office visibility for the pre-payment quotes — the mutable object a
 * checkout builds before (and whether or not) a payment succeeds. Read-only on
 * purpose: quotes are driven by the checkout flow and the purge command, not
 * edited by hand. The list gives full visibility of what is happening in carts
 * and powers conversion tracking (draft → ready → converted / abandoned).
 */
#[Route('/admin/quotes', name: 'admin_quotes_')]
class QuoteController extends AbstractController
{
    public function __construct(
        private QuoteRepository $quoteRepository,
        private OrderRepository $orderRepository,
    ) {}

    #[Route('/', name: 'index')]
    public function index(Request $request): Response
    {
        $status = $request->query->get('status');
        $quotes = $this->quoteRepository->findForAdmin($status);

        return $this->render('admin/quotes/index.html.twig', [
            'quotes'       => $quotes,
            'activeStatus' => $status,
            'statusCounts' => $this->quoteRepository->countByStatus(),
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Quote $quote): Response
    {
        // The Order this quote converted into, when any — shown as a cross-link so
        // the admin can jump from a converted quote to its order.
        $convertedOrder = $quote->getConvertedOrderId() !== null
            ? $this->orderRepository->find($quote->getConvertedOrderId())
            : null;

        return $this->render('admin/quotes/show.html.twig', [
            'quote'          => $quote,
            'convertedOrder' => $convertedOrder,
        ]);
    }
}
