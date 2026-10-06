<?php

namespace App\Controller\Admin;

use App\Entity\MarketRegion;
use App\Form\Admin\MarketRegionType;
use App\Market\MarketContext;
use App\Market\RegionCatalog;
use App\Repository\MarketRegionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Region overrides: add a market's regions when the bundled defaults do not
 * cover it, or customise an existing market.
 *
 * Route order: /new before /{id} so "new" is not captured by the id placeholder
 * (see CLAUDE.md). The list also shows, for the home market, which regions are
 * currently effective (overrides if any, else the built-in defaults).
 */
#[Route('/admin/regions', name: 'admin_regions_')]
#[IsGranted('ROLE_ADMIN')]
class RegionController extends AbstractController
{
    public function __construct(
        private readonly MarketRegionRepository $regions,
        private readonly RegionCatalog $catalog,
        private readonly MarketContext $marketContext,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $home = $this->marketContext->homeCountry();

        return $this->render('admin/regions/index.html.twig', [
            'overrides'       => $this->regions->findAllOrdered(),
            'homeCountry'     => $home,
            'effectiveHome'   => $this->catalog->forCountry($home),
            'homeIsOverride'  => $this->regions->findForCountry($home) !== [],
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $region = new MarketRegion();
        $form = $this->createForm(MarketRegionType::class, $region);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($region);
            $this->em->flush();

            $this->auditLogger->info('audit.region.created', [
                'country' => $region->getCountry(), 'code' => $region->getCode(),
            ]);
            $this->addFlash('success', 'admin.regions.flash.created');

            return $this->redirectToRoute('admin_regions_index');
        }

        return $this->render('admin/regions/form.html.twig', ['form' => $form, 'is_edit' => false]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(MarketRegion $region, Request $request): Response
    {
        $form = $this->createForm(MarketRegionType::class, $region);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();

            $this->auditLogger->info('audit.region.updated', [
                'country' => $region->getCountry(), 'code' => $region->getCode(),
            ]);
            $this->addFlash('success', 'admin.regions.flash.updated');

            return $this->redirectToRoute('admin_regions_index');
        }

        return $this->render('admin/regions/form.html.twig', ['form' => $form, 'is_edit' => true, 'region' => $region]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(MarketRegion $region, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('region_delete_' . $region->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'admin.regions.flash.csrf');

            return $this->redirectToRoute('admin_regions_index');
        }

        $country = $region->getCountry();
        $code = $region->getCode();
        $this->em->remove($region);
        $this->em->flush();

        $this->auditLogger->warning('audit.region.deleted', ['country' => $country, 'code' => $code]);
        $this->addFlash('success', 'admin.regions.flash.deleted');

        return $this->redirectToRoute('admin_regions_index');
    }
}
