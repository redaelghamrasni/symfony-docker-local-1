<?php

namespace App\Controller\Admin;

use App\Service\ReportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Back-office reporting: conversion funnel, abandoned carts, sales & revenue and
 * product performance over a selectable window. All aggregation lives in
 * {@see ReportService}, shared with the dashboard synthesis. Read-only.
 */
#[Route('/admin/reports', name: 'admin_reports_')]
class ReportController extends AbstractController
{
    /** Allowed look-back windows, in days. */
    private const PERIODS = [7, 30, 90, 365];

    public function __construct(private readonly ReportService $reports) {}

    #[Route('/', name: 'index')]
    public function index(Request $request): Response
    {
        $period = (int) $request->query->get('period', 30);
        if (!in_array($period, self::PERIODS, true)) {
            $period = 30;
        }

        $tz   = new \DateTimeZone('America/Toronto');
        $to   = new \DateTimeImmutable('now', $tz);
        $from = $to->modify("-{$period} days");

        return $this->render('admin/reports/index.html.twig', [
            'period'               => $period,
            'periods'              => self::PERIODS,
            'from'                 => $from,
            'to'                   => $to,
            'funnel'               => $this->reports->conversionFunnel($from, $to),
            'funnelTrend'          => $this->reports->funnelTrend($from, $to),
            'abandonedSummary'     => $this->reports->abandonedSummary($from, $to),
            'abandonedCarts'       => $this->reports->abandonedCarts($from, $to, 50),
            'sales'                => $this->reports->salesSummary($from, $to),
            'topProducts'          => $this->reports->topProducts($from, $to, 10),
            'topAbandonedProducts' => $this->reports->topAbandonedProducts($from, $to, 10),
        ]);
    }
}
