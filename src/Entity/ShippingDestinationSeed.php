<?php

namespace App\Entity;

use App\Repository\ShippingDestinationSeedRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A baseline destination the shipping snapshot refresh rates against, so the
 * fallback has real rates in the database from day one — before any order
 * history exists. This is seed reference data (loaded by a DataFixture, editable
 * in the DB), NOT invented rates: it only says "a serviceable address in this
 * region", and the refresh command turns it into a real `ShippingRateSnapshot`
 * by actually rating it against the carrier. The refresh also rates the distinct
 * destinations found in order history, so over time real customer routes extend
 * this baseline. One row per (country, region).
 */
#[ORM\Entity(repositoryClass: ShippingDestinationSeedRepository::class)]
#[ORM\Table(name: 'shipping_destination_seed')]
#[ORM\UniqueConstraint(name: 'UNIQ_SHIPPING_SEED_ROUTE', columns: ['country', 'region'])]
class ShippingDestinationSeed
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 2)]
    private string $country;

    #[ORM\Column(length: 8, nullable: true)]
    private ?string $region = null;

    #[ORM\Column(length: 120)]
    private string $city;

    #[ORM\Column(name: 'postal_code', length: 20)]
    private string $postalCode;

    /** Required by Shippo for international rating; optional for domestic. */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $phone = null;

    /** Lets an operator retire a destination without deleting its row. */
    #[ORM\Column]
    private bool $active = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCountry(): string
    {
        return $this->country;
    }

    public function setCountry(string $country): static
    {
        $this->country = strtoupper(trim($country));
        return $this;
    }

    public function getRegion(): ?string
    {
        return $this->region;
    }

    public function setRegion(?string $region): static
    {
        $this->region = $region !== null && trim($region) !== '' ? strtoupper(trim($region)) : null;
        return $this;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function setCity(string $city): static
    {
        $this->city = $city;
        return $this;
    }

    public function getPostalCode(): string
    {
        return $this->postalCode;
    }

    public function setPostalCode(string $postalCode): static
    {
        $this->postalCode = $postalCode;
        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;
        return $this;
    }
}
