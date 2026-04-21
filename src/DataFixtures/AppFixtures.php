<?php

namespace App\DataFixtures;

use App\Entity\Article;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // Créer 20 articles variés
        $articles = [
            [
                'title' => 'Laptop Dell XPS 15',
                'content' => 'Ordinateur portable haute performance avec écran 4K, processeur Intel i7, 16GB RAM et SSD 512GB. Parfait pour le développement et le design.',
                'price' => '1499.99'
            ],
            [
                'title' => 'iPhone 15 Pro',
                'content' => 'Le dernier smartphone Apple avec puce A17 Pro, appareil photo 48MP et écran Super Retina XDR de 6.1 pouces.',
                'price' => '1299.00'
            ],
            [
                'title' => 'Casque Sony WH-1000XM5',
                'content' => 'Casque audio sans fil avec réduction de bruit active, autonomie 30h et qualité sonore exceptionnelle.',
                'price' => '399.99'
            ],
            [
                'title' => 'Clavier mécanique Keychron K8',
                'content' => 'Clavier sans fil compact avec switches mécaniques, rétroéclairage RGB et compatible Mac/Windows.',
                'price' => '129.99'
            ],
            [
                'title' => 'Souris Logitech MX Master 3S',
                'content' => 'Souris ergonomique sans fil avec capteur 8K DPI, molette électromagnétique et batterie rechargeable.',
                'price' => '119.99'
            ],
            [
                'title' => 'Écran Samsung 27" 4K',
                'content' => 'Moniteur IPS 4K UHD (3840x2160) avec HDR10, fréquence 60Hz et port USB-C pour la recharge.',
                'price' => '449.99'
            ],
            [
                'title' => 'SSD Samsung 980 PRO 1TB',
                'content' => 'Disque SSD NVMe ultra-rapide avec vitesses de lecture jusqu\'à 7000 MB/s. Interface PCIe 4.0.',
                'price' => '149.99'
            ],
            [
                'title' => 'Webcam Logitech C920',
                'content' => 'Webcam Full HD 1080p avec microphones stéréo intégrés, idéale pour visioconférences et streaming.',
                'price' => '89.99'
            ],
            [
                'title' => 'Chaise de bureau Herman Miller',
                'content' => 'Chaise ergonomique premium avec support lombaire ajustable, accoudoirs 4D et garantie 12 ans.',
                'price' => '899.00'
            ],
            [
                'title' => 'iPad Pro 12.9"',
                'content' => 'Tablette Apple avec puce M2, écran Liquid Retina XDR, compatibilité Apple Pencil et Magic Keyboard.',
                'price' => '1399.00'
            ],
            [
                'title' => 'AirPods Pro 2',
                'content' => 'Écouteurs sans fil avec réduction de bruit adaptative, audio spatial et boîtier de charge MagSafe.',
                'price' => '279.99'
            ],
            [
                'title' => 'Lampe de bureau LED Xiaomi',
                'content' => 'Lampe intelligente avec luminosité ajustable, température de couleur variable et contrôle via application.',
                'price' => '49.99'
            ],
            [
                'title' => 'Sac à dos The North Face',
                'content' => 'Sac à dos pour ordinateur portable 15", compartiment rembourré, résistant à l\'eau avec port USB.',
                'price' => '129.00'
            ],
            [
                'title' => 'Switch Nintendo OLED',
                'content' => 'Console de jeu hybride avec écran OLED 7", station d\'accueil et deux manettes Joy-Con.',
                'price' => '399.99'
            ],
            [
                'title' => 'Kindle Paperwhite',
                'content' => 'Liseuse électronique 6.8" avec éclairage réglable, étanche et stockage 16GB pour des milliers de livres.',
                'price' => '149.99'
            ],
            [
                'title' => 'Disque dur externe WD 4TB',
                'content' => 'Disque dur externe portable USB 3.0 avec protection par mot de passe et logiciel de sauvegarde automatique.',
                'price' => '109.99'
            ],
            [
                'title' => 'Micro Blue Yeti',
                'content' => 'Microphone USB professionnel avec 4 modes de capture, monitoring casque et support anti-vibrations.',
                'price' => '139.99'
            ],
            [
                'title' => 'Manette Xbox Elite Series 2',
                'content' => 'Manette sans fil premium avec boutons programmables, sticks ajustables et batterie rechargeable 40h.',
                'price' => '189.99'
            ],
            [
                'title' => 'Ring Light 10"',
                'content' => 'Anneau lumineux LED avec trépied, luminosité réglable et support smartphone pour photos et vidéos.',
                'price' => '39.99'
            ],
            [
                'title' => 'Carte graphique NVIDIA RTX 4070',
                'content' => 'Carte graphique haute performance 12GB GDDR6X avec Ray Tracing et DLSS 3 pour gaming 4K.',
                'price' => '699.99'
            ],
        ];

        foreach ($articles as $index => $data) {
            $article = new Article();
            $article->setTitle($data['title']);
            $article->setContent($data['content']);
            $article->setPrice($data['price']);
            $article->setName($data['title']); // Même valeur que title
            $article->setCreatedAt(new \DateTimeImmutable('-' . rand(1, 30) . ' days'));

            $manager->persist($article);
        }

        $manager->flush();

        echo "✅ 20 articles créés avec succès !\n";
    }
}