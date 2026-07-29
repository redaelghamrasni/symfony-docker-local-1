<?php

namespace App\Controller\Admin;

use App\Entity\Order;
use App\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/orders', name: 'admin_orders_')]
class OrderController extends AbstractController
{
    public function __construct(
        private OrderRepository $orderRepository,
        private EntityManagerInterface $entityManager
    ) {}

    #[Route('/', name: 'index')]
    public function index(Request $request): Response
    {
        $status = $request->query->get('status');
        $idsParam = $request->query->get('ids');

        if ($idsParam !== null) {
            $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $idsParam)))));
            $orders = $this->orderRepository->findByIds($ids);
        } else {
            $orders = $this->orderRepository->findForAdmin($status);
        }

        if ($request->isXmlHttpRequest()) {
            $html = $this->renderView('admin/orders/_rows.html.twig', ['orders' => $orders]);
            return new JsonResponse([
                'html'  => $html,
                'total' => count($orders),
            ]);
        }

        return $this->render('admin/orders/index.html.twig', [
            'orders'        => $orders,
            'activeStatus'  => $status,
            'statusCounts'  => $this->orderRepository->countByStatus(),
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Order $order): Response
    {
        return $this->render('admin/orders/show.html.twig', [
            'order' => $order,
        ]);
    }

    #[Route('/{id}/status', name: 'update_status', methods: ['POST'])]
    public function updateStatus(Order $order, Request $request): Response
    {
        $status = $request->request->get('status');
        $allowed = ['pending', 'in_progress', 'shipped', 'completed', 'cancelled'];

        if (!in_array($status, $allowed, true)) {
            $this->addFlash('error', 'admin.orders.status_invalid');
            return $this->redirectToRoute('admin_orders_show', ['id' => $order->getId()]);
        }

        // Marking an order "shipped" is, until an automated carrier feed exists, the moment the
        // admin must record the tracking number by hand — require it unless already on file.
        if ($status === 'shipped' && !$order->getTrackingNumber()) {
            $trackingNumber = trim($request->request->get('tracking_number', ''));
            if ($trackingNumber === '') {
                $this->addFlash('error', 'admin.orders.tracking_required');
                return $this->redirect($this->generateUrl('admin_orders_show', ['id' => $order->getId()]) . '#tab-shipping');
            }

            $order->setTrackingNumber($trackingNumber);
            $carrier = trim($request->request->get('carrier', ''));
            if ($carrier !== '') {
                $order->setShippingCarrier($carrier);
            }
            $order->setShippingCarrierStatus('shipped');
            if (!$order->getShippingDate()) {
                $order->setShippingDate(new \DateTimeImmutable('now', new \DateTimeZone('America/Toronto')));
            }
        }

        $justRecordedTracking = $status === 'shipped' && $request->request->get('tracking_number');

        $order->setStatus($status);
        $order->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->addFlash('success', 'admin.orders.status_updated');

        $url = $this->generateUrl('admin_orders_show', ['id' => $order->getId()]);
        return $this->redirect($justRecordedTracking ? $url . '#tab-shipping' : $url);
    }

    #[Route('/{id}/shipping', name: 'update_shipping', methods: ['POST'])]
    public function updateShipping(Order $order, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('shipping' . $order->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'admin.orders.csrf_error');
            return $this->redirectToRoute('admin_orders_show', ['id' => $order->getId()]);
        }

        $allowedStatuses = ['pending', 'shipped', 'in_transit', 'delivered', 'failed'];
        $carrierStatus = $request->request->get('carrier_status');
        if ($carrierStatus && in_array($carrierStatus, $allowedStatuses, true)) {
            $order->setShippingCarrierStatus($carrierStatus);
        } elseif ($carrierStatus === '') {
            $order->setShippingCarrierStatus(null);
        }

        $trackingNumber = trim($request->request->get('tracking_number', ''));
        $order->setTrackingNumber($trackingNumber !== '' ? $trackingNumber : null);

        $shippingDateRaw = trim($request->request->get('shipping_date', ''));
        if ($shippingDateRaw !== '') {
            try {
                $order->setShippingDate(new \DateTimeImmutable($shippingDateRaw));
            } catch (\Exception) {}
        } else {
            $order->setShippingDate(null);
        }

        $carrier = trim($request->request->get('carrier', ''));
        $order->setShippingCarrier($carrier !== '' ? $carrier : null);

        $estimatedDateRaw = trim($request->request->get('estimated_delivery_date', ''));
        if ($estimatedDateRaw !== '') {
            try {
                $order->setEstimatedDeliveryDate(new \DateTimeImmutable($estimatedDateRaw));
            } catch (\Exception) {}
        } else {
            $order->setEstimatedDeliveryDate(null);
        }

        $labelUrl = trim($request->request->get('shipping_label_url', ''));
        $order->setShippingLabelUrl($labelUrl !== '' ? $labelUrl : null);

        $order->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->addFlash('success', 'admin.orders.shipping_updated');

        return $this->redirectToRoute('admin_orders_show', ['id' => $order->getId()]);
    }

    #[Route('/{id}/payment', name: 'update_payment', methods: ['POST'])]
    public function updatePayment(Order $order, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('payment' . $order->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'admin.orders.csrf_error');
            return $this->redirectToRoute('admin_orders_show', ['id' => $order->getId()]);
        }

        $method = trim($request->request->get('payment_method', ''));
        $order->setPaymentMethod($method !== '' ? $method : null);

        $brand = trim($request->request->get('payment_brand', ''));
        $order->setPaymentBrand($brand !== '' ? $brand : null);

        $last4 = trim($request->request->get('payment_last4', ''));
        $order->setPaymentLast4($last4 !== '' ? substr($last4, 0, 4) : null);

        $intentId = trim($request->request->get('stripe_payment_intent_id', ''));
        $order->setStripePaymentIntentId($intentId !== '' ? $intentId : null);

        $order->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->addFlash('success', 'admin.orders.payment_updated');

        return $this->redirectToRoute('admin_orders_show', ['id' => $order->getId()]);
    }
}
