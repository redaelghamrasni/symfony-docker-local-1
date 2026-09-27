<?php

namespace App\Controller\Admin;

use App\Entity\TaxRate;
use App\Form\Admin\TaxRateType;
use App\Repository\TaxRateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Manages provincial sales-tax rates.
 *
 * Route order: /new before /{id} so "new" is not captured by the id
 * placeholder (see CLAUDE.md). Rates are entered and shown as percentages;
 * the entity stores fractions, so this controller converts between the two.
 */
#[Route('/admin/taxes', name: 'admin_taxes_')]
#[IsGranted('ROLE_ADMIN')]
class TaxRateController extends AbstractController
{
    public function __construct(
        private readonly TaxRateRepository $taxRates,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/taxes/index.html.twig', [
            'rates' => $this->taxRates->findAllOrdered(),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $rate = new TaxRate();
        $form = $this->createForm(TaxRateType::class, null);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            if ($this->taxRates->findByProvince($data['province']) !== null) {
                $this->addFlash('error', 'admin.taxes.flash.duplicate');

                return $this->render('admin/taxes/form.html.twig', ['form' => $form, 'is_edit' => false]);
            }

            $this->applyForm($rate, $data);
            $this->em->persist($rate);
            $this->em->flush();

            $this->auditLogger->info('audit.tax_rate.created', $this->auditData($rate));
            $this->addFlash('success', 'admin.taxes.flash.created');

            return $this->redirectToRoute('admin_taxes_index');
        }

        return $this->render('admin/taxes/form.html.twig', ['form' => $form, 'is_edit' => false]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(TaxRate $rate, Request $request): Response
    {
        // Pre-fill the percentage fields from the stored fractions.
        $form = $this->createForm(TaxRateType::class, [
            'province'   => $rate->getProvince(),
            'name'       => $rate->getName(),
            'gstPercent' => $this->toPercent($rate->getGst()),
            'pstPercent' => $this->toPercent($rate->getPst()),
            'hstPercent' => $this->toPercent($rate->getHst()),
        ], ['is_edit' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $before = $this->auditData($rate);
            $this->applyForm($rate, $form->getData());
            $this->em->flush();

            $this->auditLogger->info('audit.tax_rate.updated', [
                'province' => $rate->getProvince(),
                'before'   => $before,
                'after'    => $this->auditData($rate),
            ]);
            $this->addFlash('success', 'admin.taxes.flash.updated');

            return $this->redirectToRoute('admin_taxes_index');
        }

        return $this->render('admin/taxes/form.html.twig', ['form' => $form, 'is_edit' => true, 'rate' => $rate]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(TaxRate $rate, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('tax_delete_' . $rate->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'admin.taxes.flash.csrf');

            return $this->redirectToRoute('admin_taxes_index');
        }

        $province = $rate->getProvince();

        // Past orders store their tax amounts as a snapshot, so removing a rate
        // never changes what a customer was already charged. A province with no
        // row falls back to the statutory default in TaxService.
        $this->em->remove($rate);
        $this->em->flush();

        $this->auditLogger->warning('audit.tax_rate.deleted', ['province' => $province]);
        $this->addFlash('success', 'admin.taxes.flash.deleted');

        return $this->redirectToRoute('admin_taxes_index');
    }

    /** @param array<string, mixed> $data */
    private function applyForm(TaxRate $rate, array $data): void
    {
        $rate->setProvince((string) $data['province']);
        $rate->setName((string) $data['name']);
        $rate->setGst($this->toFraction($data['gstPercent']));
        $rate->setPst($this->toFraction($data['pstPercent']));
        $rate->setHst($this->toFraction($data['hstPercent']));
    }

    private function toFraction(float|int|string|null $percent): string
    {
        return number_format(((float) $percent) / 100, 5, '.', '');
    }

    private function toPercent(string $fraction): float
    {
        return round((float) $fraction * 100, 5);
    }

    /** @return array<string, string> */
    private function auditData(TaxRate $rate): array
    {
        return ['gst' => $rate->getGst(), 'pst' => $rate->getPst(), 'hst' => $rate->getHst()];
    }
}
