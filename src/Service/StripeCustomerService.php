<?php

namespace App\Service;

use App\Entity\Address;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\StripeClient;

class StripeCustomerService
{
    public function __construct(
        private StripeClient $stripeClient,
        private EntityManagerInterface $em,
    ) {}

    public function getOrCreateCustomer(User $user, ?string $phone = null, ?Address $address = null): string
    {
        $addressPayload = $address ? [
            'line1'       => $address->getStreet(),
            'city'        => $address->getCity(),
            'postal_code' => $address->getPostalCode(),
            'state'       => $address->getProvince(),
            'country'     => 'CA',
        ] : null;

        if ($user->getStripeCustomerId()) {
            // Optionnel : garder Stripe synchronisé si les infos changent
            $this->stripeClient->customers->update($user->getStripeCustomerId(), [
                'name' => trim($user->getFirstName() . ' ' . $user->getLastName()),
                'email' => $user->getEmail(),
                'phone' => $phone ?? $user->getPhone(), // selon l'option choisie
                'address' => $addressPayload,
            ]);

            return $user->getStripeCustomerId();
        }

        $customer = $this->stripeClient->customers->create([
            'email' => $user->getEmail(),
            'name' => trim($user->getFirstName() . ' ' . $user->getLastName()),
            'phone' => $phone ?? $user->getPhone(),
            'address' => $addressPayload,
            'metadata' => [
                'user_id' => $user->getId(),
            ],
        ]);

        $user->setStripeCustomerId($customer->id);
        $this->em->flush();

        return $customer->id;
    }
}