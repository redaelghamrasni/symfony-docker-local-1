<?php

namespace App\DataFixtures;

use App\Entity\Address;
use App\Entity\Article;
use App\Entity\ArticleTranslation;
use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(
        private UserPasswordHasherInterface $passwordHasher
    ) {}

    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create('fr_FR');
        $faker->seed(42);

        // ── Categories ────────────────────────────────────────────────────────
        $categoryData = [
            ['Informatique',            'informatique',           'https://images.unsplash.com/photo-1496181133206-80ce9b88a853?w=600&h=400&fit=crop&q=80', 'Computers'],
            ['Smartphones & Tablettes', 'smartphones-tablettes',  'https://images.unsplash.com/photo-1592750475338-74b7b21085ab?w=600&h=400&fit=crop&q=80', 'Smartphones & Tablets'],
            ['Audio',                   'audio',                  'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?w=600&h=400&fit=crop&q=80', 'Audio'],
            ['Écrans & Stockage',       'ecrans-stockage',        'https://images.unsplash.com/photo-1527443224154-c4a573d5fccb?w=600&h=400&fit=crop&q=80', 'Screens & Storage'],
            ['Gaming',                  'gaming',                 'https://images.unsplash.com/photo-1542751371-adc38448a05e?w=600&h=400&fit=crop&q=80', 'Gaming'],
            ['Bureau & Accessoires',    'bureau-accessoires',     'https://images.unsplash.com/photo-1593642632559-0c6d3fc62b89?w=600&h=400&fit=crop&q=80', 'Office & Accessories'],
        ];

        $categories = [];
        foreach ($categoryData as [$name, $slug, $imageUrl, $nameEn]) {
            $cat = new Category();
            $cat->setName($name)->setSlug($slug)->setImageUrl($imageUrl);

            $frTrans = new CategoryTranslation();
            $frTrans->setLocale('fr')->setName($name)->setCategory($cat);

            $enTrans = new CategoryTranslation();
            $enTrans->setLocale('en')->setName($nameEn)->setCategory($cat);

            $manager->persist($cat);
            $manager->persist($frTrans);
            $manager->persist($enTrans);
            $categories[$slug] = $cat;
        }

        // ── Articles ──────────────────────────────────────────────────────────
        // [title, category, price, imageUrl, content_fr, content_en]
        $articleData = [
            [
                'Laptop Dell XPS 15', 'informatique', '1499.99',
                'https://images.unsplash.com/photo-1593642632559-0c6d3fc62b89?w=500&h=380&fit=crop&q=80',
                "Le Dell XPS 15 embarque un processeur Intel Core i7 de 13e génération couplé à une carte graphique NVIDIA RTX 4060, le tout dans un châssis ultra-fin en aluminium et fibre de carbone. Son écran OLED 3,5K de 15,6 pouces offre une précision colorimétrique exceptionnelle et une luminosité de 400 nits. Avec jusqu'à 12 heures d'autonomie et un SSD NVMe rapide, c'est l'outil idéal pour les créatifs et les développeurs.",
                "The Dell XPS 15 packs a 13th Gen Intel Core i7 and NVIDIA RTX 4060 into a slim aluminum and carbon fiber chassis under 2 kg. Its 15.6-inch OLED 3.5K display delivers stunning color accuracy with 100% DCI-P3 coverage and 400 nits brightness. Up to 12 hours of battery life and a fast NVMe SSD make it the go-to machine for creative professionals and developers.",
            ],
            [
                'iPhone 15 Pro', 'smartphones-tablettes', '1299.00',
                'https://images.unsplash.com/photo-1592750475338-74b7b21085ab?w=500&h=380&fit=crop&q=80',
                "L'iPhone 15 Pro est propulsé par la puce A17 Pro gravée en 3 nm, la plus puissante jamais installée dans un iPhone, avec des performances GPU rivalisant avec celles d'une console de jeu. Son châssis en titane de grade 5 le rend à la fois léger et extrêmement résistant, tandis que le triple capteur 48 MP ouvre la voie à la photographie computationnelle avancée. Le bouton Action personnalisable et le port USB-C à 10 Gb/s en font l'iPhone le plus polyvalent à ce jour.",
                "The iPhone 15 Pro runs Apple's A17 Pro chip on 3nm technology, delivering GPU performance on par with dedicated gaming consoles. Its grade-5 titanium frame keeps weight low while offering superior durability, and the 48 MP triple-camera system enables advanced computational photography including 5x optical zoom. The customizable Action Button and 10 Gb/s USB-C port make it the most versatile iPhone ever built.",
            ],
            [
                'Casque Sony WH-1000XM5', 'audio', '399.99',
                'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?w=500&h=380&fit=crop&q=80',
                "Le Sony WH-1000XM5 est le casque à réduction de bruit active le plus avancé du marché, s'appuyant sur 8 microphones et deux puces dédiées pour bloquer pratiquement tout son ambiant. Son autonomie de 30 heures avec recharge rapide (3 min = 3 h de lecture) en fait le compagnon idéal pour les longs voyages. La certification Hi-Res Audio et l'égaliseur personnalisable via l'application Sony Music Center satisfont même les audiophiles les plus exigeants.",
                "The Sony WH-1000XM5 leads the industry in active noise cancellation, using 8 microphones and two dedicated processors to eliminate virtually all ambient sound. Thirty hours of battery life with rapid charge (3 minutes = 3 hours of playback) makes it ideal for long-haul travel. Hi-Res Audio certification and a fully customizable EQ via the Sony Music Center app satisfy even the most demanding audiophiles.",
            ],
            [
                'Clavier mécanique Keychron K8', 'informatique', '129.99',
                'https://images.unsplash.com/photo-1587829741301-dc798b83add3?w=500&h=380&fit=crop&q=80',
                "Le Keychron K8 est un clavier mécanique tenkeyless (TKL) sans fil compatible Mac, Windows et iOS via Bluetooth 5.1 ou câble USB-C. Disponible avec des switchs Gateron rouges, bleus ou marrons, il offre une expérience de frappe fiable et précise pour les programmeurs et rédacteurs exigeants. Son rétroéclairage RVB personnalisable et sa compatibilité avec les keycaps MX standard en font une plateforme de personnalisation sans limite.",
                "The Keychron K8 is a wireless tenkeyless mechanical keyboard compatible with Mac, Windows, and iOS via Bluetooth 5.1 or USB-C wired connection. Available with Gateron Red, Blue, or Brown switches, it delivers a precise typing experience suited for programmers and writers. Customizable RGB backlighting and support for standard MX keycaps make it a highly upgradeable platform.",
            ],
            [
                'Souris Logitech MX Master 3S', 'informatique', '119.99',
                'https://images.unsplash.com/photo-1527864550417-7fd91fc51a46?w=500&h=380&fit=crop&q=80',
                "La Logitech MX Master 3S est la souris de productivité ultime avec son capteur Darkfield 8 000 DPI qui fonctionne sur n'importe quelle surface, y compris le verre. La molette MagSpeed électromagnétique défile 1 000 lignes en une seconde, idéale pour naviguer dans de longs documents. Grâce à Logi Options+, tous les boutons sont entièrement programmables par application pour un workflow sans interruption.",
                "The Logitech MX Master 3S is the ultimate productivity mouse, featuring an 8,000 DPI Darkfield sensor that tracks on any surface, including glass. The electromagnetic MagSpeed scroll wheel moves 1,000 lines per second for instant navigation through long documents. Through the Logi Options+ app, every button is fully customizable on a per-application basis.",
            ],
            [
                'Écran Samsung 27" 4K', 'ecrans-stockage', '449.99',
                'https://images.unsplash.com/photo-1527443224154-c4a573d5fccb?w=500&h=380&fit=crop&q=80',
                "Cet écran Samsung 27\" UHD affiche une résolution 4K (3840×2160) sur une dalle IPS avec des angles de vision larges et 99 % de couverture sRGB pour une reproduction fidèle des couleurs. La technologie HDR10 accentue les contrastes pour une immersion totale dans les contenus multimédias. Ses ports HDMI 2.0, DisplayPort 1.2 et deux USB-C en font un hub de connectivité complet pour un bureau épuré.",
                "This Samsung 27-inch 4K UHD monitor features a 3840×2160 IPS panel with wide viewing angles and 99% sRGB color coverage for accurate reproduction. HDR10 support enhances contrast for an immersive multimedia and creative experience. HDMI 2.0, DisplayPort 1.2, and dual USB-C ports make it a full connectivity hub for a clean desk setup.",
            ],
            [
                'SSD Samsung 980 PRO 1TB', 'ecrans-stockage', '149.99',
                'https://images.unsplash.com/photo-1558618666-fcd25c85cd64?w=500&h=380&fit=crop&q=80',
                "Le Samsung 980 PRO est un SSD NVMe PCIe 4.0 offrant des vitesses de lecture séquentielle jusqu'à 7 000 Mo/s et d'écriture jusqu'à 5 000 Mo/s, idéal pour les charges de travail créatives intensives et le gaming haute performance. Sa technologie MLC V-NAND garantit une endurance exceptionnelle de 600 TBW. Compatible PS5 (avec dissipateur) et tout PC équipé d'un slot M.2 PCIe 4.0.",
                "The Samsung 980 PRO is a PCIe 4.0 NVMe SSD delivering sequential read speeds up to 7,000 MB/s and write speeds up to 5,000 MB/s, ideal for demanding creative workflows and PC gaming. Samsung's MLC V-NAND technology ensures an impressive 600 TBW endurance rating. Compatible with PlayStation 5 (with heatsink) and any PC with an M.2 PCIe 4.0 slot.",
            ],
            [
                'Webcam Logitech C920', 'informatique', '89.99',
                'https://images.unsplash.com/photo-1576073719676-aa95576db207?w=500&h=380&fit=crop&q=80',
                "La Logitech C920 est la webcam de référence pour la visioconférence professionnelle, offrant une capture Full HD 1080p à 30 fps avec autofocus et correction automatique de l'exposition. Son champ de vision de 78° et ses deux microphones stéréo avec réduction du bruit ambiant garantissent une image et un son clairs sur Teams, Zoom ou Meet. Compatible Windows, macOS et Chrome OS, sans aucune installation de pilote requise.",
                "The Logitech C920 is the benchmark webcam for professional video conferencing, delivering Full HD 1080p at 30 fps with automatic focus and exposure correction. Its 78° field of view and dual stereo microphones with background noise reduction ensure clear image and audio on Teams, Zoom, or Meet. Plug-and-play compatible with Windows, macOS, and Chrome OS — no drivers needed.",
            ],
            [
                'Chaise de bureau Herman Miller', 'bureau-accessoires', '899.00',
                'https://images.unsplash.com/photo-1580480055273-228ff5388ef8?w=500&h=380&fit=crop&q=80',
                "La chaise Aeron de Herman Miller est l'une des références mondiales en matière d'ergonomie, conçue après des années de recherche sur la posture humaine. Son dossier en Mesh 8Z Pellicle assure une suspension uniforme du corps et une circulation optimale de l'air pour le confort thermique. Les réglages PostureFit SL soutiennent simultanément le sacrum et la région lombaire pour prévenir les douleurs dorsales lors des longues sessions de travail.",
                "The Herman Miller Aeron chair is a global ergonomics benchmark, designed following years of research into human posture and movement. Its 8Z Pellicle mesh back provides uniform body suspension while promoting airflow for optimal thermal comfort. PostureFit SL adjustments support both the sacrum and lumbar spine simultaneously, helping prevent chronic back pain during extended work sessions.",
            ],
            [
                'iPad Pro 12.9"', 'smartphones-tablettes', '1399.00',
                'https://images.unsplash.com/photo-1544244015-0df4b3ffc6b0?w=500&h=380&fit=crop&q=80',
                "L'iPad Pro 12,9 pouces embarque la puce M2 d'Apple avec jusqu'à 16 Go de RAM, offrant des performances de niveau ordinateur portable pour les tâches créatives exigeantes. Son écran Liquid Retina XDR miniLED affiche un rapport de contraste de 1 000 000:1 et jusqu'à 1 600 nits de luminosité en mode HDR. La connectivité Thunderbolt/USB 4 et la compatibilité avec l'Apple Pencil 2e génération en font l'outil ultime pour les illustrateurs et professionnels nomades.",
                "The iPad Pro 12.9-inch runs Apple's M2 chip with up to 16 GB of unified memory, delivering laptop-class performance for the most demanding creative tasks. Its Liquid Retina XDR miniLED display achieves a 1,000,000:1 contrast ratio and up to 1,600 nits of brightness in HDR content. Thunderbolt/USB 4 connectivity and second-generation Apple Pencil support make it the definitive tool for illustrators and mobile professionals.",
            ],
            [
                'AirPods Pro 2', 'audio', '279.99',
                'https://images.unsplash.com/photo-1572635196237-14b3f281503f?w=500&h=380&fit=crop&q=80',
                "Les AirPods Pro 2e génération intègrent la puce H2 d'Apple qui multiplie par deux l'efficacité de la réduction de bruit active par rapport à la génération précédente, avec jusqu'à 29 dB d'isolation. La Transparence adaptative analyse l'environnement 48 000 fois par seconde pour mélanger intelligemment les sons extérieurs. L'audio spatial personnalisé avec suivi dynamique de la tête crée une expérience sonore immersive dans les contenus Dolby Atmos.",
                "AirPods Pro 2nd generation feature Apple's H2 chip, delivering 2x more Active Noise Cancellation than the previous generation with up to 29 dB of noise reduction. Adaptive Transparency analyzes the surrounding environment 48,000 times per second to blend ambient sound naturally. Personalized Spatial Audio with dynamic head tracking creates a cinema-grade immersive experience in Dolby Atmos content.",
            ],
            [
                'Lampe de bureau LED Xiaomi', 'bureau-accessoires', '49.99',
                'https://images.unsplash.com/photo-1513506003901-1e6a35ee04d2?w=500&h=380&fit=crop&q=80',
                "La lampe de bureau LED Xiaomi Mi offre un éclairage sans scintillement avec un indice de rendu des couleurs (IRC) supérieur à 95, protégeant efficacement la vue lors des longues sessions de travail. Son bras articulé en aluminium permet d'orienter le faisceau avec précision dans n'importe quelle direction. La luminosité et la température de couleur (de 2 700 K à 6 500 K) sont réglables pour s'adapter à chaque moment de la journée.",
                "The Xiaomi Mi LED desk lamp delivers flicker-free lighting with a Color Rendering Index (CRI) above 95, effectively protecting your eyes during long work sessions. Its articulated aluminum arm allows precise beam positioning in any direction. Brightness and color temperature (2,700K to 6,500K) are fully adjustable to match the time of day and the type of task.",
            ],
            [
                'Sac à dos The North Face', 'bureau-accessoires', '129.00',
                'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?w=500&h=380&fit=crop&q=80',
                "Le sac à dos The North Face Borealis offre 28 litres de volume avec une organisation intuitive : poche principale avec protection ordinateur, poche avant avec organiseur et housse tablette en tissu doux. Fabriqué en nylon durable avec revêtement résistant à l'eau, il est conçu aussi bien pour les navetteurs urbains que pour les randonnées légères. Le système de suspension FlexVent distribue le poids ergonomiquement grâce à des bretelles moulées et un dos rembourré ventilé.",
                "The North Face Borealis backpack offers 28 liters of storage with smart organization: a main compartment with padded laptop sleeve, a fleece-lined tablet pocket, and a front organizer panel. Made from durable nylon with a water-resistant finish, it suits both urban commuters and light hikers. The FlexVent suspension system ergonomically distributes weight through molded shoulder straps and a ventilated padded back panel.",
            ],
            [
                'Switch Nintendo OLED', 'gaming', '399.99',
                'https://images.unsplash.com/photo-1578303512597-81e6cc155b3e?w=500&h=380&fit=crop&q=80',
                "La Nintendo Switch OLED embarque un écran OLED de 7 pouces aux couleurs plus vives et aux noirs plus profonds que le modèle standard, pour une expérience visuelle supérieure en mode portable. La station d'accueil améliorée intègre désormais un port LAN filaire pour une connexion réseau stable en mode TV. Avec 64 Go de stockage interne extensible via microSD et des haut-parleurs stéréo améliorés, c'est la version la plus complète de la Switch.",
                "The Nintendo Switch OLED features a 7-inch OLED screen with more vibrant colors and deeper blacks than the standard model, elevating the portable gaming experience. The redesigned dock now includes a wired LAN port for a stable connection in TV mode. With 64 GB of internal storage expandable via microSD and enhanced stereo speakers, it is the most complete version of the Switch.",
            ],
            [
                'Kindle Paperwhite', 'smartphones-tablettes', '149.99',
                'https://images.unsplash.com/photo-1532012197267-da84d127e765?w=500&h=380&fit=crop&q=80',
                "Le Kindle Paperwhite (11e génération) offre un écran 6,8 pouces à 300 ppp avec rétroéclairage chaud réglable pour réduire la lumière bleue et permettre une lecture nocturne confortable. Sa résistance à l'eau IPX8 (jusqu'à 2 mètres pendant 60 minutes) permet de lire sereinement au bord de la piscine ou dans le bain. Avec une autonomie allant jusqu'à 10 semaines et 8 Go de stockage, c'est le compagnon de lecture idéal pour les grands lecteurs.",
                "The Kindle Paperwhite (11th generation) features a 6.8-inch 300 ppi display with adjustable warm light to reduce blue light for comfortable night-time reading. Its IPX8 water resistance rating (up to 2 meters for 60 minutes) means you can read worry-free by the pool or in the bath. With up to 10 weeks of battery life and 8 GB of storage, it is the ultimate dedicated e-reader.",
            ],
            [
                'Disque dur externe WD 4TB', 'ecrans-stockage', '109.99',
                'https://images.unsplash.com/photo-1597852074816-d933c7d2b988?w=500&h=380&fit=crop&q=80',
                "Le disque dur externe WD Elements Desktop de 4 To offre un stockage massif et fiable pour les sauvegardes de données, archives multimédias et bibliothèques de jeux. La connexion USB 3.0 (rétrocompatible USB 2.0) transfère les fichiers jusqu'à 10 fois plus vite qu'un port USB 2.0. Préformaté en NTFS pour Windows, il est facilement reformatable pour macOS ou Linux, avec une garantie constructeur de 2 ans.",
                "The WD Elements Desktop 4TB external hard drive provides massive, reliable storage for data backups, media archives, and game libraries. USB 3.0 connection (backward-compatible with USB 2.0) transfers files up to 10 times faster than USB 2.0 alone. Pre-formatted in NTFS for immediate Windows compatibility, it can be reformatted for macOS or Linux, and comes with a 2-year limited warranty.",
            ],
            [
                'Micro Blue Yeti', 'audio', '139.99',
                'https://images.unsplash.com/photo-1590602847861-f357a9332bbc?w=500&h=380&fit=crop&q=80',
                "Le Blue Yeti est le microphone USB de référence pour le podcasting, le streaming et l'enregistrement vocal, grâce à ses trois capsules à condensateur qui captent un son d'une clarté professionnelle. Ses quatre modes de pickup (cardioïde, bidirectionnel, omnidirectionnel, stéréo) s'adaptent à toutes les configurations d'enregistrement. La molette de gain, le bouton mute instantané et la sortie casque à latence zéro intégrée en font un véritable studio autonome.",
                "The Blue Yeti is the reference USB microphone for podcasting, streaming, and vocal recording, featuring three condenser capsules that capture professional-quality audio. Four pickup patterns (cardioid, bidirectional, omnidirectional, stereo) adapt to any recording scenario. An adjustable gain knob, instant-mute button, and built-in zero-latency headphone jack make it a complete recording studio in a single device.",
            ],
            [
                'Manette Xbox Elite Series 2', 'gaming', '189.99',
                'https://images.unsplash.com/photo-1585504198199-20277593b94f?w=500&h=380&fit=crop&q=80',
                "La manette Xbox Elite Series 2 offre plus de 30 façons de personnaliser l'expérience grâce aux palettes interchangeables, aux sticks à tension réglable et aux croix directionnelles modulables. Les gâchettes avec verrouillage de course courte et les palettes arrière permettent des actions plus rapides dans les jeux compétitifs. Avec 40 heures d'autonomie en rechargeable et un étui de transport rigide avec station de charge inclus, c'est la référence des manettes premium.",
                "The Xbox Elite Wireless Controller Series 2 offers over 30 ways to customize your play experience with swappable paddles, adjustable-tension thumbsticks, and interchangeable D-pads. Hair trigger locks and rear paddles enable faster actions in competitive gaming scenarios. Up to 40 hours of rechargeable battery life and a carry case with built-in charging dock complete this premium package.",
            ],
            [
                'Ring Light 10"', 'bureau-accessoires', '39.99',
                'https://images.unsplash.com/photo-1611532736597-de2d4265fba3?w=500&h=380&fit=crop&q=80',
                "Ce ring light de 10 pouces avec trépied ajustable est l'outil indispensable pour les créateurs de contenu, streamers et professionnels qui souhaitent un éclairage doux et homogène. La température de couleur est réglable de 3 200 K (chaud) à 5 600 K (neutre) avec 10 niveaux de luminosité, contrôlables à distance. Il intègre un support pour smartphone et un port USB pour charger les appareils pendant la prise de vue.",
                "This 10-inch ring light with adjustable tripod stand is the essential tool for content creators, streamers, and professionals who need soft, even lighting for video production. Color temperature ranges from 3,200K (warm) to 5,600K (neutral) with 10 brightness levels, controllable via remote. It includes a smartphone holder and a USB charging port to power your devices during filming.",
            ],
            [
                'Carte graphique NVIDIA RTX 4070', 'informatique', '699.99',
                'https://images.unsplash.com/photo-1591488320449-011701bb6704?w=500&h=380&fit=crop&q=80',
                "La NVIDIA GeForce RTX 4070 délivre d'excellentes performances en 1440p et 4K grâce à l'architecture Ada Lovelace et ses 12 Go de mémoire GDDR6X ultrarapide. Le ray-tracing de 4e génération et le DLSS 3 avec Frame Generation permettent d'atteindre des fréquences d'images inédites sans sacrifier la qualité visuelle. Avec une consommation de seulement 200 W, elle offre un rapport performances/énergie remarquable par rapport aux générations précédentes.",
                "The NVIDIA GeForce RTX 4070 delivers excellent 1440p and 4K gaming performance powered by the Ada Lovelace architecture and 12 GB of GDDR6X memory. 4th-generation ray tracing and DLSS 3 with Frame Generation push frame rates higher without compromising visual quality. At just 200W TDP, it offers an outstanding performance-per-watt ratio compared to previous GPU generations.",
            ],
        ];

        $articles = [];
        foreach ($articleData as [$title, $catSlug, $price, $imageUrl, $frContent, $enContent]) {

            $article = new Article();
            $article->setTitle($title)
                ->setName($title)
                ->setContent($frContent)
                ->setPrice($price)
                ->setImageUrl($imageUrl)
                ->setCategory($categories[$catSlug])
                ->setCreatedAt(new \DateTimeImmutable('-' . rand(10, 180) . ' days'));

            $frTrans = new ArticleTranslation();
            $frTrans->setLocale('fr')->setTitle($title)->setContent($frContent)->setArticle($article);
            $article->getTranslations()->add($frTrans);

            $enTrans = new ArticleTranslation();
            $enTrans->setLocale('en')->setTitle($title)->setContent($enContent)->setArticle($article);
            $article->getTranslations()->add($enTrans);

            $manager->persist($frTrans);
            $manager->persist($enTrans);
            $manager->persist($article);
            $articles[] = $article;
        }

        // ── Admin user ────────────────────────────────────────────────────────
        $admin = new User();
        $admin->setEmail('admin@example.com')
            ->setUsername('admin')
            ->setFirstName('Admin')
            ->setLastName('User')
            ->setPassword($this->passwordHasher->hashPassword($admin, 'admin123'))
            ->setRoles(['ROLE_ADMIN', 'ROLE_USER'])
            ->setAutoFillCheckout(true);
        $manager->persist($admin);

        // ── Regular users + addresses + orders ────────────────────────────────
        $statuses = ['pending', 'pending', 'in_progress', 'in_progress', 'in_progress', 'shipped', 'shipped', 'completed', 'completed', 'completed'];

        $usedEmails    = ['admin@example.com'];
        $usedUsernames = ['admin'];

        for ($i = 1; $i <= 15; $i++) {
            $firstName = $faker->firstName();
            $lastName  = $faker->lastName();

            do {
                $email = strtolower($firstName . '.' . $lastName . $faker->randomNumber(3)) . '@example.com';
            } while (in_array($email, $usedEmails, true));
            $usedEmails[] = $email;

            do {
                $username = strtolower($firstName) . $faker->randomNumber(3);
            } while (in_array($username, $usedUsernames, true));
            $usedUsernames[] = $username;

            $user = new User();
            $user->setEmail($email)
                ->setUsername($username)
                ->setFirstName($firstName)
                ->setLastName($lastName)
                ->setPassword($this->passwordHasher->hashPassword($user, 'password123'))
                ->setAutoFillCheckout((bool) rand(0, 1));

            $manager->persist($user);

            $this->createAddress($manager, $user, Address::TYPE_SHIPPING, $faker);

            if (rand(0, 1)) {
                $this->createAddress($manager, $user, Address::TYPE_BILLING, $faker);
            }

            $orderCount = rand(1, 4);
            for ($j = 0; $j < $orderCount; $j++) {
                $daysAgo   = rand(1, 160);
                $orderDate = new \DateTimeImmutable('-' . $daysAgo . ' days');

                $order = new Order();
                $order->setUser($user)
                    ->setStatus($faker->randomElement($statuses))
                    ->setCustomerFirstName($firstName)
                    ->setCustomerLastName($lastName)
                    ->setCustomerEmail($email)
                    ->setCustomerPhone($faker->phoneNumber())
                    ->setShippingStreet($faker->streetAddress())
                    ->setShippingCity($faker->city())
                    ->setShippingPostalCode($faker->postcode())
                    ->setBillingStreet($faker->streetAddress())
                    ->setBillingCity($faker->city())
                    ->setBillingPostalCode($faker->postcode());

                $this->setPrivateProperty($order, 'createdAt', $orderDate);
                $this->setPrivateProperty($order, 'updatedAt', $orderDate);

                $itemCount      = rand(1, 3);
                $pickedArticles = $faker->randomElements($articles, $itemCount);
                $total          = 0.0;

                foreach ($pickedArticles as $article) {
                    $qty       = rand(1, 2);
                    $unitPrice = (float) $article->getPrice();
                    $subtotal  = $unitPrice * $qty;
                    $total    += $subtotal;

                    $item = new OrderItem();
                    $item->setArticle($article)
                        ->setQuantity($qty)
                        ->setUnitPrice(number_format($unitPrice, 2, '.', ''))
                        ->setSubtotal(number_format($subtotal, 2, '.', ''));

                    $order->addItem($item);
                    $manager->persist($item);
                }

                $order->setTotal(number_format($total, 2, '.', ''));
                $manager->persist($order);
            }
        }

        $manager->flush();

        echo "✅ Fixtures chargées : 6 catégories, 20 articles (avec images), 1 admin + 15 utilisateurs.\n";
        echo "   Admin : admin@example.com / admin123\n";
    }

    private function createAddress(ObjectManager $manager, User $user, string $type, \Faker\Generator $faker): void
    {
        $address = new Address();
        $address->setUser($user)
            ->setType($type)
            ->setFirstName($user->getFirstName())
            ->setLastName($user->getLastName())
            ->setStreet($faker->streetAddress())
            ->setCity($faker->city())
            ->setPostalCode($faker->postcode())
            ->setPhone($faker->optional(0.7)->phoneNumber())
            ->setIsDefault(true);
        $manager->persist($address);
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $ref = new \ReflectionProperty($object, $property);
        $ref->setAccessible(true);
        $ref->setValue($object, $value);
    }
}
