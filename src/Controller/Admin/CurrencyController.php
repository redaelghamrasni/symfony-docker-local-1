<?php

namespace App\Controller\Admin;

use App\Entity\Currency;
use App\Form\Admin\CurrencyType;
use App\Repository\ArticlePriceRepository;
use App\Repository\CurrencyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Currency management.
 *
 * Note the route order: /new is declared before /{id} so "new" is not
 * swallowed by the id placeholder (see CLAUDE.md).
 */
#[Route('/admin/currencies', name: 'admin_currencies_')]
#[IsGranted('ROLE_ADMIN')]
class CurrencyController extends AbstractController
{
    public function __construct(
        private readonly CurrencyRepository $currencies,
        private readonly ArticlePriceRepository $articlePrices,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/currencies/index.html.twig', [
            'currencies'   => $this->currencies->findAllOrdered(),
            'priceCounts'  => $this->articlePrices->countByCurrency(),
            'articleTotal' => $this->em->getRepository(\App\Entity\Article::class)->count([]),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $currency = new Currency();
        $form = $this->createForm(CurrencyType::class, $currency);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->currencies->findByCode($currency->getCode()) !== null) {
                $this->addFlash('error', 'admin.currencies.flash.duplicate');

                return $this->render('admin/currencies/form.html.twig', [
                    'form'     => $form,
                    'currency' => $currency,
                    'is_edit'  => false,
                ]);
            }

            $this->em->persist($currency);
            $this->em->flush();

            $this->auditLogger->info('audit.currency.created', [
                'code'     => $currency->getCode(),
                'rate'     => $currency->getExchangeRate(),
                'enabled'  => $currency->isEnabled(),
            ]);

            $this->addFlash('success', 'admin.currencies.flash.created');

            return $this->redirectToRoute('admin_currencies_index');
        }

        return $this->render('admin/currencies/form.html.twig', [
            'form'     => $form,
            'currency' => $currency,
            'is_edit'  => false,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Currency $currency, Request $request): Response
    {
        $form = $this->createForm(CurrencyType::class, $currency, [
            'is_edit'    => true,
            'is_default' => $currency->isDefault(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();

            $this->auditLogger->info('audit.currency.updated', [
                'code'    => $currency->getCode(),
                'rate'    => $currency->getExchangeRate(),
                'enabled' => $currency->isEnabled(),
            ]);

            $this->addFlash('success', 'admin.currencies.flash.updated');

            return $this->redirectToRoute('admin_currencies_index');
        }

        return $this->render('admin/currencies/form.html.twig', [
            'form'     => $form,
            'currency' => $currency,
            'is_edit'  => true,
        ]);
    }

    /** Quick toggle from the list, rather than opening the form to tick a box. */
    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'])]
    public function toggle(Currency $currency, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('currency_toggle_' . $currency->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'admin.currencies.flash.csrf');

            return $this->redirectToRoute('admin_currencies_index');
        }

        if ($currency->isDefault()) {
            // Disabling the currency prices are authored in would leave the
            // shop with nothing to sell in.
            $this->addFlash('error', 'admin.currencies.flash.cannot_disable_default');

            return $this->redirectToRoute('admin_currencies_index');
        }

        $currency->setEnabled(!$currency->isEnabled());
        $this->em->flush();

        $this->auditLogger->info('audit.currency.toggled', [
            'code'    => $currency->getCode(),
            'enabled' => $currency->isEnabled(),
        ]);

        $this->addFlash('success', $currency->isEnabled()
            ? 'admin.currencies.flash.enabled'
            : 'admin.currencies.flash.disabled');

        return $this->redirectToRoute('admin_currencies_index');
    }

    #[Route('/{id}/default', name: 'make_default', methods: ['POST'])]
    public function makeDefault(Currency $currency, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('currency_default_' . $currency->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'admin.currencies.flash.csrf');

            return $this->redirectToRoute('admin_currencies_index');
        }

        $previous = $this->currencies->findDefault()?->getCode();
        $this->currencies->makeDefault($currency);

        // Changing the reference currency re-bases every converted price in the
        // catalogue, so it is worth a loud audit line.
        $this->auditLogger->warning('audit.currency.default_changed', [
            'from' => $previous,
            'to'   => $currency->getCode(),
            'note' => 'article base prices are now interpreted as being in this currency',
        ]);

        $this->addFlash('success', 'admin.currencies.flash.default_changed');

        return $this->redirectToRoute('admin_currencies_index');
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Currency $currency, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('currency_delete_' . $currency->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'admin.currencies.flash.csrf');

            return $this->redirectToRoute('admin_currencies_index');
        }

        if ($currency->isDefault()) {
            $this->addFlash('error', 'admin.currencies.flash.cannot_delete_default');

            return $this->redirectToRoute('admin_currencies_index');
        }

        $code = $currency->getCode();

        // Past orders keep their own currency code as a plain string snapshot,
        // so deleting a currency cannot rewrite order history. Article prices
        // in this currency go with it (ON DELETE CASCADE).
        $this->em->remove($currency);
        $this->em->flush();

        $this->auditLogger->warning('audit.currency.deleted', ['code' => $code]);
        $this->addFlash('success', 'admin.currencies.flash.deleted');

        return $this->redirectToRoute('admin_currencies_index');
    }
}
