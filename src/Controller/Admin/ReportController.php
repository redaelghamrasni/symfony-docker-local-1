<?php

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Back-office reporting hub. Intentionally empty for now: the section exists so
 * the navigation and route are in place, and the actual reports (marketing,
 * conversion funnel, seller analytics — see ROADMAP #2 Phase 2) are added in a
 * later iteration.
 */
#[Route('/admin/reports', name: 'admin_reports_')]
class ReportController extends AbstractController
{
    #[Route('/', name: 'index')]
    public function index(): Response
    {
        return $this->render('admin/reports/index.html.twig');
    }
}
