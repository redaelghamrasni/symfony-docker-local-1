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

        $manager->flush();
    }
}
