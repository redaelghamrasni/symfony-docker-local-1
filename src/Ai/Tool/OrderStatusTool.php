<?php

namespace App\Ai\Tool;

use App\Entity\Order;
use App\Entity\User;
use App\Repository\OrderRepository;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(tags: ['ai.tool'])]
#[AsTool(
    name: 'get_order_status',
    description: <<<DESC
        Gets the status of a customer order (status, carrier,
        tracking number, estimated delivery date). Use this tool whenever a customer
        asks where their order is, their package, or their delivery.

        The customer can be identified in two ways:
        - If they are logged in, the order is searched among their own.

        - If they are not logged in (guest), they MUST provide their email, and at least one
          other clue to find the order (name, ordered product, approximate date,
          or approximate amount).
        DESC
)]
final class OrderStatusTool
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly Security $security,
    ) {}

    /**
     * @param string|null $email      Email used when placing the order (required if the customer is not logged in)
     * @param string|null $customerName Full name of the customer, to help find the order
     * @param string|null $productKeyword A product from the order (e.g., "headphones"), to help find the order
     * @param string|null $approxDate Approximate date of the order in YYYY-MM-DD format
     * @param float|null  $approxTotal Approximate total amount of the order
     */
    public function __invoke(
        ?string $email = null,
        ?string $customerName = null,
        ?string $productKeyword = null,
        ?string $approxDate = null,
        ?float $approxTotal = null,
    ): string {
        $user = $this->security->getUser();
        $date = $this->parseDate($approxDate);

        // Case 1 : User connected → we search in his order only, no need for email or other clues
        if ($user instanceof User) {
            $candidates = $this->orders->findByLooseCriteria(
                customerName: $customerName,
                productKeyword: $productKeyword,
                approxDate: $date,
                approxTotal: $approxTotal,
            );
            // Security filter : only keep orders for this user
            $candidates = array_filter(
                $candidates,
                static fn (Order $o) => $o->getUser()?->getId() === $user->getId()
            );

            return $this->formatResult(array_values($candidates), authenticated: true);
        }

        // Case 2 : guest → the email is required for any disclosure
        if (!$email) {
            return 'To find your order, I need the email used during purchase, '
                 . 'along with another clue like the name, an ordered product, '
                 . 'the approximate date, or the approximate amount. If you have an '
                 . 'account, you can also log in.';
        }

        // we find all orders that match the provided criteria (loose search)
        $candidates = $this->orders->findByLooseCriteria(
            customerName: $customerName,
            productKeyword: $productKeyword,
            approxDate: $date,
            approxTotal: $approxTotal,
        );

        // ...then we keep only those that match the email exactly (case-insensitive)
        $verified = array_filter(
            $candidates,
            static fn (Order $o) => strcasecmp((string) $o->getCustomerEmail(), $email) === 0
        );

        return $this->formatResult(array_values($verified), authenticated: false);
    }

    private function parseDate(?string $raw): ?\DateTimeImmutable
    {
        if (!$raw) {
            return null;
        }
        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param Order[] $orders
     */
    private function formatResult(array $orders, bool $authenticated): string
    {
        if (count($orders) === 0) {
            return 'No order matches these details. '
                 . 'Please check the provided information (especially the email for guests).';
        }

        if (count($orders) > 1) {
            // too many candidates → we ask the user to refine their search
            return sprintf(
                '%d orders match these details. Can you provide more information '
                . '(a more exact date, a product, or an amount) to identify the correct one?',
                count($orders)
            );
        }

        $order = $orders[0];

        // we expose the order details only if the user is authenticated or if the email matches exactly
        $lines = [];
        $lines[] = sprintf('Commande n°%d', $order->getId());
        $lines[] = sprintf('Statut : %s', $this->humanStatus($order->getStatus()));

        if ($order->getShippingCarrier()) {
            $lines[] = sprintf('Transporteur : %s', $order->getShippingCarrier());
        }
        if ($order->getShippingCarrierStatus()) {
            $lines[] = sprintf('Suivi transporteur : %s', $order->getShippingCarrierStatus());
        }
        if ($order->getTrackingNumber()) {
            $lines[] = sprintf('Numéro de suivi : %s', $order->getTrackingNumber());
        }
        if ($order->getEstimatedDeliveryDate()) {
            $lines[] = sprintf(
                'Livraison estimée : %s',
                $order->getEstimatedDeliveryDate()->format('d/m/Y')
            );
        }

        return implode("\n", $lines);
    }

    private function humanStatus(string $status): string
    {
        return match ($status) {
            'pending' => 'en attente de traitement',
            'in_progress' => 'en préparation',
            'shipped' => 'expédiée',
            'completed' => 'livrée',
            'cancelled' => 'annulée',
            default => $status,
        };
    }
}