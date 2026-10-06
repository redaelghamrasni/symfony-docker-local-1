<?php

namespace App\DataFixtures;

use App\Entity\Setting;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class SettingFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $setting = new Setting('shipping.free_threshold', 'Seuil de livraison gratuite ($)', 'number');
        $manager->persist($setting);

        // Home country drives the market layer (tax model, regions, origin).
        $homeCountry = new Setting('home_country', 'Home country', 'country');
        $homeCountry->setValue('CA');
        $manager->persist($homeCountry);

        // Pacing between carrier calls during the shipping-rate snapshot refresh
        // (API rate-limit throttle, seconds). Also seeded in prod by migration
        // Version20261006140000; kept here so a dev fixtures reload retains it.
        $throttle = new Setting('shipping.snapshot.throttle', 'Shipping rate refresh — seconds between carrier calls', 'number');
        $throttle->setValue('1');
        $manager->persist($throttle);

        $manager->flush();
    }
}
