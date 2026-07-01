<?php

namespace App\Command;

use App\Entity\Article;
use App\Entity\ArticleTranslation;
use App\Repository\ArticleRepository;
use App\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:articles:seed', description: 'Update existing articles with real descriptions and add 50 new ones')]
class SeedArticlesCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private ArticleRepository $articleRepository,
        private CategoryRepository $categoryRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $cats = [];
        foreach ($this->categoryRepository->findAll() as $cat) {
            $cats[$cat->getSlug()] = $cat;
        }
        if (empty($cats)) {
            $io->error('No categories found. Run fixtures first.');
            return Command::FAILURE;
        }

        $updated = 0;
        foreach ($this->getUpdates() as $data) {
            $article = $this->articleRepository->findOneBy(['title' => $data['title']]);
            if (!$article) {
                $io->warning("Not found (skipped): {$data['title']}");
                continue;
            }
            $article->setContent($data['fr']);
            $this->upsertTranslation($article, 'en', $data['title'], $data['en']);
            $this->upsertTranslation($article, 'fr', $data['title'], $data['fr']);
            $updated++;
        }
        $this->em->flush();
        $io->success("Updated $updated existing articles.");

        $created = 0;
        foreach ($this->getNewArticles() as $data) {
            if ($this->articleRepository->findOneBy(['title' => $data['title']])) {
                $io->note("Already exists (skipped): {$data['title']}");
                continue;
            }
            $cat = $cats[$data['cat']] ?? null;
            if (!$cat) { $io->warning("Category not found: {$data['cat']}"); continue; }

            $article = new Article();
            $article
                ->setTitle($data['title'])
                ->setName($data['title'])
                ->setContent($data['fr'])
                ->setPrice($data['price'])
                ->setImageUrl($data['img'])
                ->setCategory($cat)
                ->setCreatedAt(new \DateTimeImmutable('-' . random_int(5, 180) . ' days'));
            $this->em->persist($article);
            $this->upsertTranslation($article, 'en', $data['title'], $data['en']);
            $this->upsertTranslation($article, 'fr', $data['title'], $data['fr']);
            $created++;
        }
        $this->em->flush();
        $io->success("Created $created new articles. Total processed: " . ($updated + $created));

        return Command::SUCCESS;
    }

    private function upsertTranslation(Article $article, string $locale, string $title, string $content): void
    {
        foreach ($article->getTranslations() as $t) {
            if ($t->getLocale() === $locale) {
                $t->setTitle($title)->setContent($content);
                return;
            }
        }
        $t = new ArticleTranslation();
        $t->setLocale($locale)->setTitle($title)->setContent($content)->setArticle($article);
        $article->getTranslations()->add($t);
        $this->em->persist($t);
    }

    private function getUpdates(): array
    {
        return [
            [
                'title' => 'Laptop Dell XPS 15',
                'en' => "The Dell XPS 15 pairs a 13th-generation Intel Core i7 processor with an NVIDIA RTX 4060 GPU inside a slim aluminum and carbon-fiber chassis weighing under 2 kg. Its 15.6-inch OLED 3.5K display covers 100% of the DCI-P3 color space at up to 400 nits, making it ideal for photo and video editors. A fast PCIe 4.0 NVMe SSD and up to 12 hours of battery life complete the package for on-the-go professionals.",
                'fr' => "Le Dell XPS 15 associe un processeur Intel Core i7 de 13e generation a une carte graphique NVIDIA RTX 4060 dans un chassis fin en aluminium et fibre de carbone pesant moins de 2 kg. Son ecran OLED 3,5K de 15,6 pouces couvre 100 % de l'espace colorimetrique DCI-P3 jusqu'a 400 nits, ideal pour les photographes et monteurs video. Un SSD NVMe PCIe 4.0 rapide et jusqu'a 12 heures d'autonomie completent ce package pour les professionnels nomades.",
            ],
            [
                'title' => 'iPhone 15 Pro',
                'en' => "The iPhone 15 Pro is powered by Apple's A17 Pro chip built on 3nm technology, delivering GPU performance comparable to dedicated gaming consoles. Its grade-5 titanium frame is lighter and more durable than stainless steel, while the 48 MP triple-camera system with 5x optical zoom redefines mobile photography. The customizable Action Button and USB-C port with 10 Gb/s transfers make it the most versatile iPhone ever.",
                'fr' => "L'iPhone 15 Pro est propulse par la puce A17 Pro gravee en 3 nm, offrant des performances GPU comparables a celles des consoles de jeu. Son chassis en titane de grade 5 est plus leger et plus resistant que l'acier inoxydable, tandis que le triple capteur 48 MP avec zoom optique 5x redefinit la photographie mobile. Le bouton Action personnalisable et le port USB-C a 10 Gb/s en font l'iPhone le plus polyvalent jamais concu.",
            ],
            [
                'title' => 'Casque Sony WH-1000XM5',
                'en' => "The Sony WH-1000XM5 uses 8 microphones and two dedicated processors to deliver the most effective active noise cancellation in a consumer headphone, blocking up to 29 dB of ambient noise. Hi-Res Audio certification, LDAC codec support, and 30 hours of battery life with 3-minute rapid charge make it the benchmark wireless headphone. Multipoint connection lets it pair to two devices simultaneously.",
                'fr' => "Le Sony WH-1000XM5 utilise 8 microphones et deux processeurs dedies pour offrir la reduction de bruit active la plus efficace du marche, bloquant jusqu'a 29 dB de bruit ambiant. La certification Hi-Res Audio, le codec LDAC et 30 heures d'autonomie avec charge rapide 3 minutes = 3 h en font le casque sans fil de reference. La connexion multipoint permet de le coupler a deux appareils simultanement.",
            ],
            [
                'title' => 'Clavier mécanique Keychron K8',
                'en' => "The Keychron K8 is a tenkeyless wireless mechanical keyboard connecting via Bluetooth 5.1 or USB-C, compatible with Mac, Windows, and iOS out of the box. Choose from Gateron Red, Blue, or Brown switches; the hot-swappable PCB allows full customization without soldering. Per-key RGB backlight and up to 240 hours of battery life make it one of the best-value mechanical keyboards available.",
                'fr' => "Le Keychron K8 est un clavier mecanique sans fil tenkeyless se connectant via Bluetooth 5.1 ou USB-C, compatible Mac, Windows et iOS. Disponible avec des switchs Gateron Rouge, Bleu ou Marron, le PCB hot-swap permet une personnalisation complete sans soudure. Le retro-eclairage RVB par touche et jusqu'a 240 heures d'autonomie en font l'un des meilleurs rapports qualite-prix du marche.",
            ],
            [
                'title' => 'Souris Logitech MX Master 3S',
                'en' => "The Logitech MX Master 3S features an 8,000 DPI Darkfield sensor that tracks flawlessly on any surface including glass, and a MagSpeed scroll wheel capable of scrolling 1,000 lines per second. Quiet-click buttons reduce noise by 90%, and Logi Options+ lets you assign custom actions per application to every button. It connects to up to three devices via Bluetooth or USB receiver and recharges via USB-C.",
                'fr' => "La Logitech MX Master 3S integre un capteur Darkfield 8 000 DPI fonctionnant sur toutes les surfaces y compris le verre, et une molette MagSpeed capable de defiler 1 000 lignes par seconde. Les clics silencieux reduisent le bruit de 90 %, et Logi Options+ permet d'attribuer des actions par application a chaque bouton. Elle se connecte a trois appareils via Bluetooth ou recepteur USB et se recharge en USB-C.",
            ],
            [
                'title' => 'Écran Samsung 27" 4K',
                'en' => "This Samsung 27-inch UHD monitor delivers a 3840x2160 IPS panel with 99% sRGB color coverage and wide viewing angles for accurate color work across the screen. HDR10 support enhances contrast for multimedia content, and HDMI 2.0, DisplayPort 1.2, plus dual USB-C ports double it as a connectivity hub. A nearly frameless design and full height, tilt, and pivot adjustability suit any desk setup.",
                'fr' => "Cet ecran Samsung UHD 27 pouces offre une dalle IPS 3840x2160 couvrant 99 % du sRGB avec des angles de vision larges pour un rendu des couleurs precis. Le support HDR10 accentue le contraste pour les contenus multimedias, et les ports HDMI 2.0, DisplayPort 1.2 et double USB-C en font un hub de connectivite. Un design sans bordure et un reglage hauteur/inclinaison/pivotement s'adaptent a tous les bureaux.",
            ],
            [
                'title' => 'SSD Samsung 980 PRO 1TB',
                'en' => "The Samsung 980 PRO is a PCIe 4.0 NVMe M.2 SSD achieving sequential read speeds up to 7,000 MB/s and write speeds up to 5,000 MB/s, ideal for high-resolution video editing, 3D rendering, and fast game loading. Samsung's MLC V-NAND delivers a 600 TBW endurance rating backed by a 5-year warranty. PlayStation 5-compatible when paired with the optional heatsink.",
                'fr' => "Le Samsung 980 PRO est un SSD NVMe M.2 PCIe 4.0 atteignant des vitesses de lecture jusqu'a 7 000 Mo/s et d'ecriture jusqu'a 5 000 Mo/s, ideal pour l'edition video haute resolution et le gaming. La technologie MLC V-NAND de Samsung offre une endurance de 600 TBW garantie 5 ans. Compatible PlayStation 5 avec le dissipateur thermique optionnel.",
            ],
            [
                'title' => 'Webcam Logitech C920',
                'en' => "The Logitech C920 records Full HD 1080p at 30 fps with automatic low-light correction and autofocus, giving a crisp, professional image for video calls, streaming, or recording. Dual stereo microphones with background-noise reduction provide clear audio without a separate microphone. Plug-and-play on Windows, macOS, and Chrome OS with no driver installation required.",
                'fr' => "La Logitech C920 enregistre en Full HD 1080p a 30 fps avec correction automatique de la lumiere faible et autofocus, offrant une image nette pour les appels video et le streaming. Ses deux microphones stereo avec reduction du bruit de fond fournissent un son clair sans micro supplementaire. Plug-and-play sur Windows, macOS et Chrome OS, sans installation de pilote.",
            ],
            [
                'title' => 'Chaise de bureau Herman Miller',
                'en' => "The Herman Miller Aeron is one of the world's most studied ergonomic office chairs, featuring an 8Z Pellicle mesh back that distributes weight evenly while allowing airflow to prevent heat build-up. PostureFit SL technology independently supports the sacrum and lumbar spine, mimicking the natural forward tilt of the pelvis for reduced fatigue during long work sessions. Available in three sizes (A, B, C) with a 12-year warranty.",
                'fr' => "La chaise Aeron de Herman Miller est l'une des chaises de bureau ergonomiques les plus etudiees au monde, dotee d'un dossier en Mesh 8Z Pellicle qui repartit le poids uniformement tout en laissant l'air circuler. La technologie PostureFit SL soutient independamment le sacrum et la region lombaire pour reduire la fatigue lors des longues sessions de travail. Disponible en trois tailles (A, B, C) avec une garantie de 12 ans.",
            ],
            [
                'title' => 'iPad Pro 12.9"',
                'en' => "The iPad Pro 12.9-inch runs Apple's M2 chip with up to 16 GB of unified memory, delivering laptop-class performance for video editing, 3D design, and machine learning tasks. Its Liquid Retina XDR miniLED display achieves a 1,000,000:1 contrast ratio and up to 1,600 nits peak brightness in HDR content, with ProMotion up to 120 Hz. Thunderbolt/USB 4, Wi-Fi 6E, and Apple Pencil 2 support make it the most capable iPad ever.",
                'fr' => "L'iPad Pro 12,9 pouces est propulse par la puce M2 d'Apple avec jusqu'a 16 Go de memoire unifiee, offrant des performances comparables a un ordinateur portable pour le montage video et la conception 3D. Son ecran Liquid Retina XDR miniLED atteint un contraste de 1 000 000:1 et jusqu'a 1 600 nits en HDR, avec ProMotion jusqu'a 120 Hz. La connectivite Thunderbolt/USB 4, le Wi-Fi 6E et l'Apple Pencil 2 en font l'iPad le plus capable jamais concu.",
            ],
            [
                'title' => 'AirPods Pro 2',
                'en' => "AirPods Pro 2nd generation feature Apple's H2 chip for 2x more Active Noise Cancellation than the previous generation, with 29 dB of measured noise attenuation. Adaptive Transparency processes audio 48,000 times per second to blend outside sound naturally, and Personalized Spatial Audio with dynamic head tracking delivers an immersive Dolby Atmos experience. The MagSafe case charges via USB-C, MagSafe, or Qi.",
                'fr' => "Les AirPods Pro 2e generation integrent la puce H2 d'Apple pour une ANC deux fois superieure a la generation precedente, avec 29 dB d'attenuation mesuree. La Transparence adaptative traite l'audio 48 000 fois par seconde pour integrer naturellement les sons exterieurs, et l'audio spatial personnalise avec suivi de la tete offre une experience Dolby Atmos immersive. L'etui MagSafe se recharge via USB-C, MagSafe ou Qi.",
            ],
            [
                'title' => 'Lampe de bureau LED Xiaomi',
                'en' => "The Xiaomi Mi LED Desk Lamp Pro delivers flicker-free illumination with a Color Rendering Index above 95, protecting your eyes during extended work or study sessions. Brightness and color temperature (2,700 K warm to 6,500 K cool) are adjustable via a touch-dial or the Mi Home app for smart home integration. A USB-A charging port on the base keeps your devices powered.",
                'fr' => "La lampe de bureau LED Xiaomi Mi Pro offre un eclairage sans scintillement avec un indice de rendu des couleurs superieur a 95, protegeant les yeux lors des longues sessions de travail. La luminosite et la temperature de couleur (2 700 K a 6 500 K) se reglent via un bouton tactile ou l'application Mi Home. Le port USB-A en facade recharge vos appareils.",
            ],
            [
                'title' => 'Sac à dos The North Face',
                'en' => "The North Face Borealis backpack offers 28 liters of organized storage including a fleece-lined 15-inch laptop sleeve, a front organizer pocket, and a water-bottle side pocket. Built from durable recycled nylon with a DWR finish, it is designed for commuters and light hikers alike. The FlexVent suspension system uses molded shoulder straps and a ventilated back panel to distribute weight comfortably.",
                'fr' => "Le sac a dos The North Face Borealis offre 28 litres de rangement organise avec une pochette ordinateur 15 pouces en tissu doux, une poche avant avec organiseur et une poche laterale pour bouteille d'eau. Fabrique en nylon recycle durable avec traitement DWR, il convient aux navetteurs urbains et aux randonneurs legers. Le systeme de suspension FlexVent repartit le poids confortablement toute la journee.",
            ],
            [
                'title' => 'Switch Nintendo OLED',
                'en' => "The Nintendo Switch OLED Model features a 7-inch OLED screen with deeper blacks and richer colors than the standard LCD model, making portable gaming visually superior. The redesigned dock includes a built-in wired LAN port for stable online play in TV mode, and 64 GB of internal storage expandable via microSD provides more room for digital games. Enhanced stereo speakers round out the best Switch experience yet.",
                'fr' => "La Nintendo Switch OLED dispose d'un ecran OLED de 7 pouces aux noirs plus profonds et aux couleurs plus riches que le modele LCD standard. La nouvelle station d'accueil inclut un port LAN filaire integre pour un jeu en ligne stable en mode TV, et 64 Go de stockage interne extensible via microSD offrent plus d'espace pour les jeux numeriques. Des haut-parleurs stereo ameliores completent la meilleure experience Switch a ce jour.",
            ],
            [
                'title' => 'Kindle Paperwhite',
                'en' => "The Kindle Paperwhite (11th generation) features a 6.8-inch 300 ppi glare-free display with adjustable warm light to reduce blue light for comfortable night-time reading. Its IPX8 rating allows submersion in up to 2 meters of fresh water for 60 minutes, so you can read at the beach or in the bath worry-free. Up to 10 weeks of battery life and millions of Kindle titles make it the definitive e-reader.",
                'fr' => "Le Kindle Paperwhite (11e generation) dispose d'un ecran antireflet 300 ppp de 6,8 pouces avec lumiere chaude reglable pour une lecture nocturne plus confortable. Sa certification IPX8 lui permet d'etre immerge jusqu'a 2 metres pendant 60 minutes, idéal pour lire a la plage ou dans le bain. Jusqu'a 10 semaines d'autonomie et des millions de titres Kindle en font la liseuse numerique de reference.",
            ],
            [
                'title' => 'Disque dur externe WD 4TB',
                'en' => "The WD Elements Desktop 4 TB external hard drive provides massive, reliable storage for backups, media libraries, and game collections via a USB 3.0 connection backward-compatible with USB 2.0. Pre-formatted in NTFS for instant plug-and-play use on Windows, it can be reformatted for macOS or Linux without tools. A 2-year limited warranty and WD's longevity record make it a trusted home and office storage choice.",
                'fr' => "Le disque dur externe WD Elements Desktop de 4 To offre un vaste stockage fiable pour les sauvegardes, bibliotheques multimedias et collections de jeux via USB 3.0 retrocompatible USB 2.0. Preformate en NTFS pour Windows, il est facilement reformatable pour macOS ou Linux. Une garantie limitee de 2 ans et la reputation de longevite de WD en font un choix de confiance pour le stockage domestique et professionnel.",
            ],
            [
                'title' => 'Micro Blue Yeti',
                'en' => "The Blue Yeti is the world's best-selling USB microphone, featuring three custom condenser capsules switchable between cardioid, bidirectional, omnidirectional, and stereo pickup patterns. An adjustable gain knob, instant-mute button, and zero-latency headphone monitoring jack turn it into a self-contained recording studio. It is the go-to choice for podcasters, streamers, and musicians connecting via USB to any computer without drivers.",
                'fr' => "Le Blue Yeti est le microphone USB le plus vendu au monde, avec trois capsules a condensateur commutables entre les modes cardioide, bidirectionnel, omnidirectionnel et stereo. Un bouton de gain reglable, un bouton mute instantane et une sortie casque a latence zero en font un studio d'enregistrement autonome. Le choix incontournable des podcasteurs, streamers et musiciens, branchable directement en USB sans pilote.",
            ],
            [
                'title' => 'Manette Xbox Elite Series 2',
                'en' => "The Xbox Elite Wireless Controller Series 2 offers over 30 ways to play your way with interchangeable paddles, thumbsticks, and D-pads, plus rubberized diamond-grip side panels for secure handling. Adjustable-tension thumbsticks, hair trigger locks, and remappable buttons via the Xbox Accessories app give competitive gamers a measurable edge. Up to 40 hours of play per charge and an included charging dock complete the package.",
                'fr' => "La manette Xbox Elite Series 2 offre plus de 30 facons de personnaliser l'experience avec des palettes, joysticks et croix directionnelles interchangeables, et des cotes en caoutchouc diamant pour une prise securisee. Les joysticks a tension reglable, les gachettes a course courte et les boutons remappables via l'application Xbox Accessories donnent un avantage aux joueurs competitifs. Jusqu'a 40 heures d'autonomie et une station de charge incluse.",
            ],
            [
                'title' => 'Ring Light 10"',
                'en' => "This 10-inch ring light provides soft, even, shadow-free illumination that flatters faces for video calls, streaming, portrait photography, and makeup tutorials. Color temperature adjusts from warm 3,200 K to neutral 5,600 K across 10 brightness levels via included remote, and a smartphone mount ring clips any phone securely to the center. The adjustable tripod stand extends from 17 to 47 inches to match your shooting position.",
                'fr' => "Ce ring light de 10 pouces fournit un eclairage doux, homogene et sans ombres qui flatte les visages pour les appels video, le streaming et les tutoriels maquillage. La temperature de couleur s'ajuste de 3 200 K a 5 600 K sur 10 niveaux via la telecommande incluse, et un anneau support smartphone fixe votre telephone au centre. Le trepied reglable s'etend de 43 a 119 cm selon votre position de prise de vue.",
            ],
            [
                'title' => 'Carte graphique NVIDIA RTX 4070',
                'en' => "The NVIDIA GeForce RTX 4070 is built on the Ada Lovelace architecture with 12 GB of GDDR6X memory, delivering high-fps 1440p gaming and capable 4K performance in modern titles. DLSS 3 Frame Generation can more than double frame rates compared to rasterization alone, and Ada's 3rd-generation RT Cores produce stunning ray-traced visuals. At just 200W TDP, it is one of the most power-efficient high-end GPUs ever made.",
                'fr' => "La NVIDIA GeForce RTX 4070 repose sur l'architecture Ada Lovelace avec 12 Go de memoire GDDR6X, offrant d'excellentes performances en 1440p et une bonne capacite en 4K. Le DLSS 3 Frame Generation peut doubler les frequences d'images, et les RT Cores de 3e generation produisent des effets de ray-tracing saisissants. Avec seulement 200 W de TDP, c'est l'une des GPU haut de gamme les plus economes jamais produites.",
            ],
        ];
    }

    private function getNewArticles(): array
    {
        return [
            // ── Informatique ─────────────────────────────────────────────────
            [
                'title' => 'MacBook Pro 16" M3 Pro',
                'cat'   => 'informatique', 'price' => '2999.00',
                'img'   => 'https://images.unsplash.com/photo-1541807084-5c52b6b3adef?w=500&h=380&fit=crop&q=80',
                'en' => "The MacBook Pro 16-inch with M3 Pro features a 12-core CPU and 18-core GPU paired with up to 36 GB of unified memory, handling demanding creative and AI workflows without breaking a sweat. Its 16.2-inch Liquid Retina XDR display runs at up to 120 Hz with 1,600 nits peak HDR brightness and optional nano-texture glass. Up to 22 hours of battery life and three Thunderbolt 4 ports make it a true studio-grade portable workstation.",
                'fr' => "Le MacBook Pro 16 pouces M3 Pro integre un CPU 12 coeurs et un GPU 18 coeurs avec jusqu'a 36 Go de memoire unifiee, gerant sans effort les flux de travail creatifs et d'IA les plus exigeants. Son ecran Liquid Retina XDR de 16,2 pouces fonctionne jusqu'a 120 Hz avec 1 600 nits en HDR et une option verre nanotexture. Jusqu'a 22 heures d'autonomie et trois ports Thunderbolt 4 en font une vraie station de travail portable de niveau studio.",
            ],
            [
                'title' => 'MacBook Air 13" M2',
                'cat'   => 'informatique', 'price' => '1299.00',
                'img'   => 'https://images.unsplash.com/photo-1517336714731-489689fd1ca8?w=500&h=380&fit=crop&q=80',
                'en' => "The MacBook Air 13 with M2 features a completely redesigned fanless body weighing just 2.7 lbs, with a 13.6-inch Liquid Retina display at 2560x1664 resolution and 500 nits of brightness. Apple's M2 chip provides an 8-core CPU and up to 10-core GPU with up to 24 GB of unified memory for smooth multitasking and creative work. MagSafe charging, a 1080p FaceTime camera, and 18-hour battery life make it the ideal everyday laptop.",
                'fr' => "Le MacBook Air 13 pouces M2 adopte un design entierement repense sans ventilateur pesant 1,24 kg, avec un ecran Liquid Retina 13,6 pouces a 2560x1664 pixels et 500 nits de luminosite. La puce M2 offre un CPU 8 coeurs et un GPU jusqu'a 10 coeurs avec jusqu'a 24 Go de memoire unifiee pour une multitache fluide et un travail creatif efficace. La recharge MagSafe, la camera FaceTime 1080p et 18 heures d'autonomie en font l'ordinateur portable ideal du quotidien.",
            ],
            [
                'title' => 'ThinkPad X1 Carbon Gen 11',
                'cat'   => 'informatique', 'price' => '1849.00',
                'img'   => 'https://images.unsplash.com/photo-1525547719571-a2d4ac8945e2?w=500&h=380&fit=crop&q=80',
                'en' => "The ThinkPad X1 Carbon Gen 11 weighs just 1.12 kg thanks to a carbon-fiber and magnesium chassis that still passes 12 MIL-SPEC durability tests. An Intel Core i7-1365U vPro processor, 32 GB LPDDR5 RAM, and a 14-inch WUXGA IPS display keep it competitive for enterprise users who demand reliability. Four Thunderbolt 4 ports, Wi-Fi 6E, and Lenovo's legendary keyboard comfort set the standard for business ultrabooks.",
                'fr' => "Le ThinkPad X1 Carbon Gen 11 pese seulement 1,12 kg grace a un chassis en fibre de carbone et magnesium passant 12 tests de durabilite MIL-SPEC. Un processeur Intel Core i7-1365U vPro, 32 Go de RAM LPDDR5 et un ecran 14 pouces WUXGA IPS le maintiennent competitif pour les entreprises exigeantes. Quatre ports Thunderbolt 4, le Wi-Fi 6E et le legendaire clavier Lenovo fixent la norme de l'ultraportable professionnel.",
            ],
            [
                'title' => 'ASUS ROG Zephyrus G14',
                'cat'   => 'informatique', 'price' => '1799.00',
                'img'   => 'https://images.unsplash.com/photo-1593642702821-c8da6771f0c6?w=500&h=380&fit=crop&q=80',
                'en' => "The ASUS ROG Zephyrus G14 packs an AMD Ryzen 9 processor and NVIDIA RTX 4060 into a 14-inch chassis weighing just 1.65 kg, making it one of the most powerful compact gaming laptops available. Its 2880x1800 OLED display runs at 120 Hz with 0.2 ms response time and factory-calibrated DCI-P3 color coverage. The 76 Whr battery delivers up to 10 hours of light use, exceptional for a gaming machine.",
                'fr' => "L'ASUS ROG Zephyrus G14 integre un processeur AMD Ryzen 9 et une GPU NVIDIA RTX 4060 dans un chassis 14 pouces pesant seulement 1,65 kg. Son ecran OLED 2880x1800 a 120 Hz avec un temps de reponse de 0,2 ms et une couverture DCI-P3 calibree en usine donne vie aux jeux et aux contenus creatifs. La batterie de 76 Wh offre jusqu'a 10 heures en usage leger, exceptionnel pour un PC gaming.",
            ],
            [
                'title' => 'Raspberry Pi 5 8 Go',
                'cat'   => 'informatique', 'price' => '89.99',
                'img'   => 'https://images.unsplash.com/photo-1518770660439-4636190af475?w=500&h=380&fit=crop&q=80',
                'en' => "The Raspberry Pi 5 is 2-3x faster than its predecessor, featuring a quad-core Cortex-A76 processor at 2.4 GHz, 8 GB LPDDR4X RAM, and a dedicated RP1 I/O chip for improved peripheral performance. Dual micro-HDMI ports support simultaneous 4K output at 60 fps, and PCIe 2.0 connectivity opens the door to NVMe SSDs via an optional HAT+ adapter. The ideal platform for home automation, retro gaming, robotics, and learning to program.",
                'fr' => "Le Raspberry Pi 5 est 2 a 3 fois plus rapide que son predecesseur grace a un processeur quad-core Cortex-A76 a 2,4 GHz, 8 Go de RAM LPDDR4X et une puce I/O dediee RP1. Deux ports micro-HDMI permettent une sortie 4K a 60 fps simultanee, et la connectivite PCIe 2.0 ouvre la porte aux SSD NVMe via un adaptateur HAT+ optionnel. La plateforme ideale pour la domotique, le retrogaming, la robotique et l'apprentissage de la programmation.",
            ],
            [
                'title' => 'Processeur AMD Ryzen 9 7950X',
                'cat'   => 'informatique', 'price' => '649.99',
                'img'   => 'https://images.unsplash.com/photo-1591799264318-7e6ef8ddb7ea?w=500&h=380&fit=crop&q=80',
                'en' => "The AMD Ryzen 9 7950X is a 16-core, 32-thread desktop processor built on the Zen 4 architecture at 5nm, boosting up to 5.7 GHz for single-threaded tasks with a 170W TDP for sustained multi-core workloads. It sits in AMD's AM5 platform with DDR5 and PCIe 5.0 support, future-proofing your build for years. 3D rendering, video encoding, and software compilation finish noticeably faster than on any previous AMD desktop CPU.",
                'fr' => "L'AMD Ryzen 9 7950X est un processeur de bureau 16 coeurs et 32 fils construit sur l'architecture Zen 4 en 5 nm, avec une frequence Boost jusqu'a 5,7 GHz et un TDP de 170 W pour les charges multi-coeurs soutenues. Il s'integre a la plateforme AM5 d'AMD avec support DDR5 et PCIe 5.0, perenisant votre configuration pour les annees a venir. Le rendu 3D, l'encodage video et la compilation de logiciels s'achevent nettement plus vite que sur tout CPU de bureau AMD precedent.",
            ],
            [
                'title' => 'Mémoire Corsair Vengeance DDR5 32 Go',
                'cat'   => 'informatique', 'price' => '139.99',
                'img'   => 'https://images.unsplash.com/photo-1562976540-1502c2145186?w=500&h=380&fit=crop&q=80',
                'en' => "Corsair Vengeance DDR5-5600 32 GB (2x16 GB) delivers high-bandwidth memory at 5,600 MT/s with tight CL36 timings, optimized for Intel and AMD DDR5 platforms. Intel XMP 3.0 and AMD EXPO profiles allow one-click overclocking without manual tuning. The low-profile aluminum heat spreader fits under most CPU coolers, and lifetime warranty coverage provides long-term peace of mind.",
                'fr' => "La Corsair Vengeance DDR5-5600 32 Go (2x16 Go) offre une memoire a haute bande passante a 5 600 MT/s avec des timings serres CL36, optimisee pour les plateformes Intel et AMD DDR5. Les profils Intel XMP 3.0 et AMD EXPO permettent un overclocking en un clic sans reglage manuel. Le dissipateur en aluminium profil bas passe sous la plupart des refroidisseurs CPU, et la garantie a vie assure une tranquillite d'esprit a long terme.",
            ],
            [
                'title' => 'Hub USB-C 12-en-1 Anker',
                'cat'   => 'informatique', 'price' => '79.99',
                'img'   => 'https://images.unsplash.com/photo-1625895197185-efcec01cffe0?w=500&h=380&fit=crop&q=80',
                'en' => "The Anker 12-in-1 USB-C hub expands a single port into dual 4K HDMI, a 4K DisplayPort, 100W USB-C power delivery pass-through, SD and microSD readers, three USB-A 3.0 ports, a USB-C 3.0 data port, and Gigabit Ethernet. Compatible with Mac and PC, bus-powered, and requiring no driver installation. The aluminum shell dissipates heat efficiently for reliable performance under heavy use.",
                'fr' => "Le hub USB-C 12-en-1 Anker transforme un seul port en double HDMI 4K, DisplayPort 4K, pass-through USB-C 100W, lecteurs SD et microSD, trois ports USB-A 3.0, un port USB-C 3.0 donnees et Ethernet Gigabit. Compatible Mac et PC, alimente par le bus, sans installation de pilote. Le boitier en aluminium dissipe efficacement la chaleur pour des performances fiables lors d'une utilisation intensive.",
            ],
            [
                'title' => 'Alimentation Corsair RM850x',
                'cat'   => 'informatique', 'price' => '179.99',
                'img'   => 'https://images.unsplash.com/photo-1591799264318-7e6ef8ddb7ea?w=500&h=380&fit=crop&q=80',
                'en' => "The Corsair RM850x is an 850W fully modular ATX power supply with an 80 PLUS Gold efficiency rating, wasting minimal energy as heat even under full load. Zero RPM mode keeps the fan completely silent during light and medium loads, spinning only when high sustained output is needed. Corsair's tight voltage regulation and a 10-year warranty make it one of the most trusted PSUs for high-end gaming and workstation builds.",
                'fr' => "L'alimentation Corsair RM850x est une PSU ATX 850W entierement modulaire certifiee 80 PLUS Gold, gaspillant un minimum d'energie meme en pleine charge. Le mode Zero RPM maintient le ventilateur completement silencieux sous charge legere et moderee, ne tournant que lors d'une haute puissance soutenue. La regulation de tension precise de Corsair et la garantie de 10 ans en font l'une des alimentations les plus fiables pour les builds gaming haut de gamme.",
            ],
            [
                'title' => 'Dock Thunderbolt 4 CalDigit TS4',
                'cat'   => 'informatique', 'price' => '349.99',
                'img'   => 'https://images.unsplash.com/photo-1625895197185-efcec01cffe0?w=500&h=380&fit=crop&q=80',
                'en' => "The CalDigit TS4 packs 18 ports into a compact aluminum enclosure: two Thunderbolt 4 downstream ports at 40 Gb/s, three USB-A 3.2 Gen 2, three USB-C ports, 2.5 GbE, SD and microSD slots, a 3.5 mm audio combo jack, and 98W host charging. It supports dual 4K displays or a single 8K monitor and is compatible with Thunderbolt 3 and USB4 hosts. The vertical stand saves desk space and promotes airflow.",
                'fr' => "Le CalDigit TS4 regroupe 18 ports dans un boitier aluminium compact : deux ports Thunderbolt 4 aval a 40 Gb/s, trois USB-A 3.2 Gen 2, trois USB-C, 2,5 GbE, emplacements SD et microSD, prise audio combo 3,5 mm et 98 W de charge pour l'hote. Il supporte deux ecrans 4K ou un seul ecran 8K et est compatible avec les hotes Thunderbolt 3 et USB4. Son support vertical economise l'espace sur le bureau et favorise la circulation de l'air.",
            ],

            // ── Smartphones & Tablettes ───────────────────────────────────────
            [
                'title' => 'Samsung Galaxy S24 Ultra',
                'cat'   => 'smartphones-tablettes', 'price' => '1399.00',
                'img'   => 'https://images.unsplash.com/photo-1511707171634-5f897ff02aa9?w=500&h=380&fit=crop&q=80',
                'en' => "The Samsung Galaxy S24 Ultra features a 6.8-inch Dynamic AMOLED 2X display with 2,600 nits peak brightness and Corning Gorilla Glass Armor for near-zero reflectivity. A Snapdragon 8 Gen 3 processor and 12 GB of RAM power a quad-camera system headlined by a 200 MP main sensor and a 50 MP 5x periscope telephoto enabling 30x Space Zoom. The built-in S Pen supports handwriting-to-text in 100+ languages and Galaxy AI features like Circle to Search.",
                'fr' => "Le Samsung Galaxy S24 Ultra dispose d'un ecran Dynamic AMOLED 2X de 6,8 pouces avec 2 600 nits de luminosite en pic et Corning Gorilla Glass Armor pour une reflectivite quasi nulle. Un processeur Snapdragon 8 Gen 3 et 12 Go de RAM alimentent le systeme quad-camera avec un capteur principal de 200 MP et un telephoto periscopique 5x de 50 MP permettant un Space Zoom 30x. Le S Pen integre supporte la reconnaissance d'ecriture dans plus de 100 langues et les fonctions Galaxy AI.",
            ],
            [
                'title' => 'Google Pixel 9 Pro',
                'cat'   => 'smartphones-tablettes', 'price' => '1099.00',
                'img'   => 'https://images.unsplash.com/photo-1580910051074-3eb694886505?w=500&h=380&fit=crop&q=80',
                'en' => "The Google Pixel 9 Pro is powered by Google's Tensor G4 chip and features a 6.3-inch Super Actua OLED display peaking at 3,000 nits for stunning clarity in direct sunlight. Its triple-camera system pairs a 50 MP main sensor with a 48 MP ultra-wide and a 48 MP 5x telephoto, backed by Google's AI tools including Magic Eraser, Best Take, and Photo Unblur. Seven years of OS and security updates ensure the longest software support in Android.",
                'fr' => "Le Google Pixel 9 Pro est propulse par la puce Tensor G4 de Google et dispose d'un ecran Super Actua OLED de 6,3 pouces culminant a 3 000 nits pour une clarte epoustouflante en plein soleil. Son triple systeme de cameras associe un capteur principal de 50 MP a un ultra-grand-angle de 48 MP et un telephoto 5x de 48 MP, soutenus par les outils IA de Google dont Magic Eraser, Best Take et Photo Unblur. Sept ans de mises a jour OS et de securite garantissent le support logiciel le plus long d'Android.",
            ],
            [
                'title' => 'Nothing Phone 2a',
                'cat'   => 'smartphones-tablettes', 'price' => '399.00',
                'img'   => 'https://images.unsplash.com/photo-1592750475338-74b7b21085ab?w=500&h=380&fit=crop&q=80',
                'en' => "The Nothing Phone 2a features a 6.7-inch AMOLED display at 120 Hz driven by a MediaTek Dimensity 7200 Pro processor and up to 12 GB of RAM. Its dual 50 MP Sony IMX890 cameras deliver sharp results in daylight and competent night photography, while the transparent back with unique Glyph LED interface adds an unmistakable design identity. A 5,000 mAh battery with 45W wired charging keeps you powered through long days.",
                'fr' => "Le Nothing Phone 2a dispose d'un ecran AMOLED de 6,7 pouces a 120 Hz propulse par un processeur MediaTek Dimensity 7200 Pro et jusqu'a 12 Go de RAM. Son double capteur Sony IMX890 de 50 MP produit des photos nettes en plein jour et une photographie nocturne competente, tandis que le dos transparent avec l'interface LED Glyph unique lui confere une identite design inimitable. Une batterie de 5 000 mAh avec charge filaire 45W vous tient en charge toute la journee.",
            ],
            [
                'title' => 'iPad Air 11" M2',
                'cat'   => 'smartphones-tablettes', 'price' => '799.00',
                'img'   => 'https://images.unsplash.com/photo-1544244015-0df4b3ffc6b0?w=500&h=380&fit=crop&q=80',
                'en' => "The iPad Air 11-inch with M2 chip delivers a 30% CPU and 25% GPU boost over the previous generation in a thin aluminum body available in five colors. Its 10.86-inch Liquid Retina display with True Tone and P3 wide color is ideal for creative workflows, and the 12 MP front camera with Center Stage keeps you perfectly framed on video calls. Apple Pencil Pro and Magic Keyboard compatibility make it a capable productivity powerhouse.",
                'fr' => "L'iPad Air 11 pouces avec puce M2 offre un gain de 30 % en CPU et 25 % en GPU dans un corps aluminium fin disponible en cinq couleurs. Son ecran Liquid Retina de 10,86 pouces avec True Tone et couleur etendue P3 est ideal pour les workflows creatifs, et la camera frontale 12 MP avec Centre de la scene vous cadre parfaitement en appel video. La compatibilite avec l'Apple Pencil Pro et le Magic Keyboard en fait un outil de productivite puissant.",
            ],
            [
                'title' => 'Samsung Galaxy Tab S9 FE',
                'cat'   => 'smartphones-tablettes', 'price' => '549.00',
                'img'   => 'https://images.unsplash.com/photo-1544244015-0df4b3ffc6b0?w=500&h=380&fit=crop&q=80',
                'en' => "The Samsung Galaxy Tab S9 FE offers a 10.9-inch LCD display at 2304x1440, driven by an Exynos 1380 processor, 6 GB of RAM, and an 8,000 mAh battery rated for up to 14 hours of video playback. IP68 water and dust resistance, Samsung DeX desktop mode, and S Pen support make it a versatile tablet at a competitive price. It ships with Android 13 and four years of OS updates guaranteed.",
                'fr' => "La Samsung Galaxy Tab S9 FE offre un ecran LCD de 10,9 pouces a 2304x1440 pixels, propulse par un processeur Exynos 1380, 6 Go de RAM et une batterie de 8 000 mAh pour jusqu'a 14 heures de lecture video. La resistance IP68, le mode bureau Samsung DeX et la compatibilite S Pen en font une tablette polyvalente a un prix competitif. Livree avec Android 13 et quatre ans de mises a jour OS garanties.",
            ],
            [
                'title' => 'Apple Watch Series 9 45mm',
                'cat'   => 'smartphones-tablettes', 'price' => '499.00',
                'img'   => 'https://images.unsplash.com/photo-1551816230-ef5deaed4a26?w=500&h=380&fit=crop&q=80',
                'en' => "Apple Watch Series 9 features the new S9 chip for 60% faster on-device processing, enabling the double-tap gesture that lets you control your watch without touching the screen. The always-on Retina LTPO OLED display reaches 2,000 nits outdoors for excellent readability in sunlight. Blood oxygen, ECG, crash and fall detection, and temperature sensing cover both fitness and safety in a single wrist-worn device.",
                'fr' => "L'Apple Watch Series 9 integre la nouvelle puce S9 pour un traitement sur l'appareil 60 % plus rapide, permettant le geste double tape qui controle la montre sans toucher l'ecran. L'ecran Retina LTPO OLED always-on atteint 2 000 nits en exterieur pour une lisibilite excellente au soleil. Oxymetre, ECG, detection des chutes et capteur de temperature couvrent forme physique et securite dans un seul appareil au poignet.",
            ],
            [
                'title' => 'Samsung Galaxy Watch 7 44mm',
                'cat'   => 'smartphones-tablettes', 'price' => '329.00',
                'img'   => 'https://images.unsplash.com/photo-1551816230-ef5deaed4a26?w=500&h=380&fit=crop&q=80',
                'en' => "The Samsung Galaxy Watch 7 is powered by a new Exynos W1000 3nm chip that is 30% more energy-efficient than its predecessor, delivering a full day of health tracking on a single charge. Its 1.47-inch circular Super AMOLED display reaches 3,000 nits, tracking heart rate, blood oxygen, skin temperature, and body composition. Galaxy AI features like Energy Score and workout suggestions make it the smartest Samsung smartwatch to date.",
                'fr' => "La Samsung Galaxy Watch 7 est propulsee par le nouveau processeur Exynos W1000 3 nm, 30 % plus econome en energie que son predecesseur, offrant une journee complete de suivi sante sur une seule charge. Son ecran Super AMOLED circulaire de 1,47 pouce atteint 3 000 nits et mesure la frequence cardiaque, l'oxygene dans le sang, la temperature cutanee et la composition corporelle. Les fonctions Galaxy AI en font la montre connectee Samsung la plus intelligente a ce jour.",
            ],
            [
                'title' => 'GoPro HERO 13 Black',
                'cat'   => 'smartphones-tablettes', 'price' => '449.00',
                'img'   => 'https://images.unsplash.com/photo-1502920917128-1aa500764cbd?w=500&h=380&fit=crop&q=80',
                'en' => "The GoPro HERO 13 Black shoots 5.3K 60fps video and 27 MP photos with HyperSmooth 6.0 stabilization for smooth footage during high-speed action sports. New Enduro battery chemistry delivers up to 40% longer recording times in cold conditions, and the modular Lenses ecosystem lets you swap between ultra-wide or anamorphic looks. Waterproof to 10 meters without a housing, it is the most versatile action camera in the HERO lineup.",
                'fr' => "La GoPro HERO 13 Black filme en video 5,3K 60 fps et en photos 27 MP avec la stabilisation HyperSmooth 6.0 pour des images fluides lors des sports d'action a grande vitesse. La nouvelle chimie de batterie Enduro offre jusqu'a 40 % de temps d'enregistrement en plus dans le froid, et l'ecosysteme modulaire de lentilles permet d'echanger les optiques pour des looks ultra-larges ou anamorphiques. Etanche a 10 metres sans boitier, c'est la camera d'action la plus polyvalente de la gamme HERO.",
            ],

            // ── Audio ─────────────────────────────────────────────────────────
            [
                'title' => 'Casque Bose QuietComfort 45',
                'cat'   => 'audio', 'price' => '329.00',
                'img'   => 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?w=500&h=380&fit=crop&q=80',
                'en' => "The Bose QuietComfort 45 combines Aware Mode for environmental sound passthrough with Quiet Mode ANC to keep you fully focused or connected depending on your environment. Up to 24 hours of wireless playback and a 15-minute quick charge for 3 hours of listening ensure you are never without audio. The lightweight 240g frame with plush synthetic leather earcups is comfortable enough to wear all day.",
                'fr' => "Le Bose QuietComfort 45 combine le mode Aware pour le laisser-passer du son environnemental avec le mode Quiet ANC pour vous maintenir pleinement concentre selon votre environnement. Jusqu'a 24 heures d'ecoute sans fil et une charge rapide de 15 minutes pour 3 heures garantissent une musique en continu. Le cadre leger de 240 g avec des coussinets en cuir synthetique moelleux est suffisamment confortable pour etre porte toute la journee.",
            ],
            [
                'title' => 'Casque Sennheiser HD 600',
                'cat'   => 'audio', 'price' => '349.00',
                'img'   => 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?w=500&h=380&fit=crop&q=80',
                'en' => "The Sennheiser HD 600 is an open-back reference headphone renowned for its transparent, natural sound reproduction with a flat frequency response from 12 Hz to 39 kHz. At 300 ohms impedance, it pairs best with a dedicated headphone amplifier to unlock its full dynamic range and micro-detail retrieval. Used in professional recording studios and by audiophiles worldwide, it remains one of the best headphones ever made at any price.",
                'fr' => "Le Sennheiser HD 600 est un casque de reference a dos ouvert reconnu pour sa reproduction sonore transparente et naturelle avec une reponse en frequence plate de 12 Hz a 39 kHz. Avec une impedance de 300 ohms, il se couple idealement a un amplificateur casque dedie pour liberer toute sa plage dynamique et sa recuperation de micro-details. Utilise dans les studios d'enregistrement professionnels et par les audiophiles du monde entier, il reste l'un des meilleurs casques jamais fabriques.",
            ],
            [
                'title' => 'Enceinte Bose SoundLink Flex',
                'cat'   => 'audio', 'price' => '179.00',
                'img'   => 'https://images.unsplash.com/photo-1608043152269-423dbba4e7e1?w=500&h=380&fit=crop&q=80',
                'en' => "The Bose SoundLink Flex is an IP67-rated waterproof Bluetooth 5.1 speaker that floats in water, perfect for beach, pool, and outdoor adventures. PositionIQ technology automatically detects orientation and adjusts the EQ whether the speaker is upright, on its side, or lying flat. Up to 12 hours of battery life and a built-in microphone for speakerphone calls complete a rugged premium portable audio solution.",
                'fr' => "La Bose SoundLink Flex est une enceinte Bluetooth 5.1 certifiee IP67 qui flotte sur l'eau, parfaite pour la plage, la piscine et les aventures en plein air. La technologie PositionIQ detecte automatiquement l'orientation et ajuste l'egaliseur selon la position de l'enceinte. Jusqu'a 12 heures d'autonomie et un microphone integre pour les appels haut-parleur completent cette solution audio portable robuste et premium.",
            ],
            [
                'title' => 'Enceinte JBL Charge 5',
                'cat'   => 'audio', 'price' => '189.00',
                'img'   => 'https://images.unsplash.com/photo-1608043152269-423dbba4e7e1?w=500&h=380&fit=crop&q=80',
                'en' => "The JBL Charge 5 delivers powerful JBL Pro Sound via a woofer and separate tweeter with two passive radiators for deep bass, all in an IP67-rated waterproof enclosure. Up to 20 hours of playtime and a built-in USB-A power bank output keep you and your devices going all day. PartyBoost lets you wirelessly link multiple JBL speakers for a bigger sound at gatherings.",
                'fr' => "La JBL Charge 5 offre un puissant son Pro JBL via un woofer et un tweeter separe avec deux radiateurs passifs pour des graves profonds, dans un boitier certifie IP67. Jusqu'a 20 heures d'autonomie et une sortie USB-A integree pour recharger votre telephone vous maintiennent en route toute la journee. PartyBoost vous permet de relier sans fil plusieurs enceintes JBL pour un son plus large lors des rassemblements.",
            ],
            [
                'title' => 'Microphone Shure SM7B',
                'cat'   => 'audio', 'price' => '399.00',
                'img'   => 'https://images.unsplash.com/photo-1590602847861-f357a9332bbc?w=500&h=380&fit=crop&q=80',
                'en' => "The Shure SM7B is the industry-standard dynamic microphone used by broadcasters, podcasters, and musicians for over four decades, delivering a warm, detailed vocal tone with excellent off-axis rejection. Its internal shock isolation and pop filter eliminate mechanical noise and breath plosives without external accessories. The SM7B requires a preamp with at least 60 dB of clean gain to perform at its best.",
                'fr' => "Le Shure SM7B est le microphone dynamique de reference industrie utilise par les radiodiffuseurs, podcasteurs et musiciens depuis plus de quatre decennies, offrant une tonalite vocale chaleureuse avec une excellente rejection hors axe. Son isolation interne aux chocs et son filtre anti-pop eliminent les bruits mecaniques et les plosives sans accessoires supplementaires. Le SM7B necessite un preampli avec au moins 60 dB de gain propre pour performer au mieux.",
            ],
            [
                'title' => 'Interface audio Focusrite Scarlett Solo',
                'cat'   => 'audio', 'price' => '179.00',
                'img'   => 'https://images.unsplash.com/photo-1598488035139-bdbb2231ce04?w=500&h=380&fit=crop&q=80',
                'en' => "The Focusrite Scarlett Solo is the world's best-selling USB audio interface, providing a high-quality mic preamp with up to 56 dB of gain, a Hi-Z instrument input, and 24-bit/192 kHz recording resolution in a compact bus-powered enclosure. Direct monitoring with near-zero latency ensures what you hear is exactly what you record. It ships with Ableton Live Lite and a suite of pro plugins to get you started immediately.",
                'fr' => "La Focusrite Scarlett Solo est l'interface audio USB la plus vendue au monde, offrant un preampli micro de haute qualite avec jusqu'a 56 dB de gain, une entree instrument Hi-Z et une resolution 24 bits/192 kHz dans un boitier compact alimente par le bus. L'ecoute directe a latence quasi nulle garantit que ce que vous entendez correspond a ce que vous enregistrez. Elle est livree avec Ableton Live Lite et une suite de plugins pro pour commencer immediatement.",
            ],
            [
                'title' => 'Microphone Rode PodMic USB',
                'cat'   => 'audio', 'price' => '229.00',
                'img'   => 'https://images.unsplash.com/photo-1590602847861-f357a9332bbc?w=500&h=380&fit=crop&q=80',
                'en' => "The Rode PodMic USB is a broadcast-grade dynamic microphone connecting via USB-C directly to any computer without an audio interface, making professional podcast sound immediately accessible. An internal shock mount and high-pass filter reduce desk rumble and low-frequency interference, while the magnetic detachable cable with both USB-C and XLR outputs lets you switch between USB-only and hybrid setups. The ergonomic swivel mount adapts to any arm or stand.",
                'fr' => "Le Rode PodMic USB est un microphone dynamique de niveau radiodiffusion se connectant via USB-C directement a n'importe quel ordinateur sans interface audio, rendant le son professionnel pour podcast immediatement accessible. Un support anti-choc interne et un filtre coupe-bas reduisent les vibrations du bureau, tandis que le cable amovible magnetique avec sorties USB-C et XLR permet de basculer entre les configurations USB seul et hybrides. Le support pivotant ergonomique s'adapte a tout bras ou pied de micro.",
            ],
            [
                'title' => 'Enceinte Marshall Emberton III',
                'cat'   => 'audio', 'price' => '169.00',
                'img'   => 'https://images.unsplash.com/photo-1608043152269-423dbba4e7e1?w=500&h=380&fit=crop&q=80',
                'en' => "The Marshall Emberton III is a compact Bluetooth 5.3 speaker inspired by Marshall's iconic guitar amp aesthetic, delivering 360-degree stereophonic sound through two full-range drivers and two passive radiators. IP67-rated dust and water resistance means it survives submersion in up to 1 meter of water for 30 minutes. Exceptional 30 hours of playtime and a sustainable design using recycled materials make it the most complete Emberton yet.",
                'fr' => "La Marshall Emberton III est une enceinte Bluetooth 5.3 compacte inspiree de l'esthetique iconique des amplis guitare Marshall, delivrant un son stereophonique a 360 degres via deux haut-parleurs large bande et deux radiateurs passifs. Certifiee IP67, elle survit a une immersion jusqu'a 1 metre pendant 30 minutes. Une autonomie exceptionnelle de 30 heures et une conception durable utilisant des materiaux recycles en font l'Emberton la plus complete a ce jour.",
            ],

            // ── Écrans & Stockage ─────────────────────────────────────────────
            [
                'title' => 'Écran LG 27" 4K UltraFine',
                'cat'   => 'ecrans-stockage', 'price' => '699.00',
                'img'   => 'https://images.unsplash.com/photo-1527443224154-c4a573d5fccb?w=500&h=380&fit=crop&q=80',
                'en' => "The LG 27-inch 4K UltraFine uses a nano IPS panel at 3840x2160 covering 98% of DCI-P3, factory-calibrated to Delta-E 2 or less, making it a reliable reference display for photographers and video editors. A single Thunderbolt 3 cable delivers 4K 60 Hz signal and 96W of power delivery to a connected MacBook or PC, eliminating desktop cable clutter. Two USB-A downstream ports add convenient peripheral connectivity.",
                'fr' => "L'ecran LG 27 pouces 4K UltraFine utilise un panneau nano IPS a 3840x2160 couvrant 98 % du DCI-P3, calibre en usine a Delta-E inferieur a 2, en faisant un ecran de reference fiable pour les photographes et les monteurs video. Un seul cable Thunderbolt 3 fournit le signal 4K 60 Hz et 96 W de charge au MacBook ou PC connecte, eliminant l'encombrement des cables. Deux ports USB-A aval ajoutent une connectivite peripherique pratique.",
            ],
            [
                'title' => 'Écran ASUS ProArt PA279CV 27"',
                'cat'   => 'ecrans-stockage', 'price' => '549.00',
                'img'   => 'https://images.unsplash.com/photo-1527443224154-c4a573d5fccb?w=500&h=380&fit=crop&q=80',
                'en' => "The ASUS ProArt PA279CV is a 27-inch 4K IPS monitor factory-calibrated to Delta-E 2 or less, covering 100% sRGB and 95% DCI-P3 for accurate color in design, photo, and video workflows. A USB-C port provides 65W of power delivery and DisplayPort Alt Mode in a single cable connection. ProArt Calibration technology enables on-screen calibration without a third-party colorimeter.",
                'fr' => "L'ecran ASUS ProArt PA279CV est un moniteur IPS 4K 27 pouces calibre en usine a Delta-E inferieur a 2, couvrant 100 % du sRGB et 95 % du DCI-P3 pour une reproduction des couleurs precise dans les workflows de design, photo et video. Un port USB-C fournit 65 W de charge et le mode DisplayPort Alt en une seule connexion cable. La technologie ProArt Calibration permet une calibration a l'ecran sans colorimetre tiers.",
            ],
            [
                'title' => 'SSD WD Black SN850X 2 To',
                'cat'   => 'ecrans-stockage', 'price' => '199.00',
                'img'   => 'https://images.unsplash.com/photo-1558618666-fcd25c85cd64?w=500&h=380&fit=crop&q=80',
                'en' => "The WD Black SN850X is a top-tier PCIe Gen 4 NVMe M.2 SSD delivering sequential reads up to 7,300 MB/s and writes up to 6,600 MB/s, optimized for gaming with Game Mode 2.0 that pre-loads frequently accessed data into cache. Its 2 TB capacity fits a large game library, backed by a 5-year warranty and 1,200 TBW endurance. Available with or without a heatsink for PlayStation 5 installation.",
                'fr' => "Le WD Black SN850X est un SSD NVMe M.2 PCIe Gen 4 haut de gamme offrant des lectures sequentielles jusqu'a 7 300 Mo/s et des ecritures jusqu'a 6 600 Mo/s, optimise pour le gaming avec le Game Mode 2.0 qui precharge les donnees frequemment consultees dans le cache. Sa capacite de 2 To heberge une grande bibliotheque de jeux, garanti 5 ans avec 1 200 TBW d'endurance. Disponible avec ou sans dissipateur pour installation sur PlayStation 5.",
            ],
            [
                'title' => 'SSD portable Samsung T7 Shield 2 To',
                'cat'   => 'ecrans-stockage', 'price' => '179.00',
                'img'   => 'https://images.unsplash.com/photo-1558618666-fcd25c85cd64?w=500&h=380&fit=crop&q=80',
                'en' => "The Samsung T7 Shield portable SSD is encased in a rugged rubber exterior rated IP65 for dust and water resistance, protecting 2 TB of data during outdoor adventures and travel. NVMe speeds of up to 1,050 MB/s read and 1,000 MB/s write via USB 3.2 Gen 2 make it among the fastest portable drives available. Hardware AES 256-bit encryption and an optional password lock add security for sensitive data.",
                'fr' => "Le SSD portable Samsung T7 Shield est enfermé dans un boitier en caoutchouc robuste certifie IP65 contre la poussiere et l'eau, protegeant 2 To de donnees lors des aventures en plein air et des voyages. Des vitesses NVMe jusqu'a 1 050 Mo/s en lecture et 1 000 Mo/s en ecriture via USB 3.2 Gen 2 en font l'un des disques portables les plus rapides. Le chiffrement materiel AES 256 bits et un verrou optionnel par mot de passe ajoutent une securite pour les donnees sensibles.",
            ],
            [
                'title' => 'Disque dur Seagate Barracuda 4 To',
                'cat'   => 'ecrans-stockage', 'price' => '89.99',
                'img'   => 'https://images.unsplash.com/photo-1597852074816-d933c7d2b988?w=500&h=380&fit=crop&q=80',
                'en' => "The Seagate Barracuda 4 TB 3.5-inch hard drive offers cost-effective mass storage at 5,400 RPM with a 256 MB cache buffer and SATA 6 Gb/s interface. Multi-Tier Caching technology uses NAND flash alongside the spinning platter to accelerate common file operations. A 2-year limited warranty and wide compatibility with Windows, macOS, and Linux make it the reliable workhorse for backup drives and media servers.",
                'fr' => "Le disque dur Seagate Barracuda 4 To 3,5 pouces offre un stockage de masse economique a 5 400 tr/min avec un tampon de cache de 256 Mo et une interface SATA 6 Gb/s. La technologie de mise en cache Multi-Tier utilise une memoire flash NAND pour accelerer les operations de fichiers courantes. Une garantie limitee de 2 ans et une large compatibilite avec Windows, macOS et Linux en font le disque de travail fiable pour les sauvegardes et les serveurs multimedias.",
            ],
            [
                'title' => 'Bras moniteur Ergotron LX',
                'cat'   => 'ecrans-stockage', 'price' => '179.00',
                'img'   => 'https://images.unsplash.com/photo-1527443224154-c4a573d5fccb?w=500&h=380&fit=crop&q=80',
                'en' => "The Ergotron LX Monitor Arm supports displays from 21 to 34 inches and up to 11.3 kg, offering full articulation including tilt, pan, and 360-degree rotation on a sleek aluminum arm clamping to desk edges up to 6.2 cm thick. CF-balance technology keeps monitors precisely in position with no drift after adjustment, and integrated cable management routes cords cleanly through the arm. A 10-year warranty is among the best in the monitor arm category.",
                'fr' => "Le bras moniteur Ergotron LX supporte des ecrans de 21 a 34 pouces et jusqu'a 11,3 kg, offrant une articulation complete incluant inclinaison, panoramique et rotation a 360 degres sur un bras en aluminium elegant se pinçant aux bords de bureau jusqu'a 6,2 cm d'epaisseur. La technologie a equilibrage CF maintient les moniteurs en position sans derive apres reglage, et la gestion integree des cables les achemine proprement a travers le bras. Une garantie de 10 ans est parmi les meilleures de la categorie.",
            ],
            [
                'title' => 'Câble HDMI 2.1 8K 2m',
                'cat'   => 'ecrans-stockage', 'price' => '29.99',
                'img'   => 'https://images.unsplash.com/photo-1527443224154-c4a573d5fccb?w=500&h=380&fit=crop&q=80',
                'en' => "This certified HDMI 2.1 cable supports up to 8K 60 Hz or 4K 120 Hz video with 48 Gbps maximum bandwidth, enabling Variable Refresh Rate (VRR), Auto Low Latency Mode (ALLM), and Enhanced Audio Return Channel (eARC). The braided nylon jacket resists tangling and abrasion, while gold-plated connectors ensure reliable signal integrity over 2 meters. Compatible with PS5, Xbox Series X, RTX 40-series, and all HDMI 2.1 sources.",
                'fr' => "Ce cable HDMI 2.1 certifie prend en charge jusqu'a la video 8K 60 Hz ou 4K 120 Hz avec 48 Gbps de bande passante maximale, permettant le Variable Refresh Rate (VRR), le Mode Faible Latence Automatique (ALLM) et l'Enhanced Audio Return Channel (eARC). La gaine en nylon tresse resiste aux enchevêtrements et a l'abrasion, tandis que les connecteurs plaqués or assurent une integrite de signal fiable sur 2 metres. Compatible PS5, Xbox Series X, RTX serie 40 et toutes les sources HDMI 2.1.",
            ],
            [
                'title' => 'Carte SD SanDisk Extreme Pro 256 Go',
                'cat'   => 'ecrans-stockage', 'price' => '59.99',
                'img'   => 'https://images.unsplash.com/photo-1558618666-fcd25c85cd64?w=500&h=380&fit=crop&q=80',
                'en' => "The SanDisk Extreme Pro 256 GB SDXC card delivers read speeds up to 200 MB/s and write speeds up to 140 MB/s, enabling 4K UHD and 8K RAW video recording without buffer slowdowns. V60 and U3 speed class ratings guarantee sustained video performance, and UHS-II dual-row pins provide maximum interface bandwidth for compatible cameras. Rated for temperatures from -25 C to 85 C with a lifetime limited warranty.",
                'fr' => "La carte SanDisk Extreme Pro SDXC de 256 Go offre des vitesses de lecture jusqu'a 200 Mo/s et d'ecriture jusqu'a 140 Mo/s, permettant l'enregistrement video 4K UHD et RAW 8K sans ralentissements de tampon. Les classes de vitesse V60 et U3 garantissent des performances video soutenues, et les broches double rangee UHS-II assurent la bande passante maximale pour les appareils photo compatibles. Homologue de -25 C a 85 C avec une garantie a vie limitee.",
            ],

            // ── Gaming ────────────────────────────────────────────────────────
            [
                'title' => 'PlayStation 5 Slim',
                'cat'   => 'gaming', 'price' => '549.00',
                'img'   => 'https://images.unsplash.com/photo-1607853202273-797f1c22a38e?w=500&h=380&fit=crop&q=80',
                'en' => "The PlayStation 5 Slim is 30% smaller and 18-24% lighter than the original PS5 while retaining the same custom AMD Zen 2 CPU, RDNA 2 GPU with 10.3 teraflops, and ultra-fast 5.5 GB/s NVMe SSD. It supports 4K gaming at up to 120 fps, ray tracing, and 8K output, with DualSense adaptive triggers and haptic feedback delivering unmatched immersion. The detachable disc drive edition allows you to purchase both physical and digital games.",
                'fr' => "La PlayStation 5 Slim est 30 % plus petite et 18 a 24 % plus legere que la PS5 originale tout en conservant le meme CPU AMD Zen 2, le GPU RDNA 2 avec 10,3 teraflops et le SSD NVMe ultra-rapide a 5,5 Go/s. Elle supporte le gaming en 4K jusqu'a 120 fps, le ray tracing et la sortie 8K, avec les gachettes adaptatives et le retour haptique de la DualSense pour une immersion inegalee. L'edition a lecteur de disque detachable permet d'acheter des jeux physiques et numeriques.",
            ],
            [
                'title' => 'Xbox Series X',
                'cat'   => 'gaming', 'price' => '599.00',
                'img'   => 'https://images.unsplash.com/photo-1621259182978-fbf93132d53d?w=500&h=380&fit=crop&q=80',
                'en' => "The Xbox Series X delivers 12 teraflops of GPU power with AMD RDNA 2 architecture and a custom Zen 2 8-core CPU, enabling true 4K gaming at up to 120 fps in supported titles. A 1 TB Custom NVMe SSD dramatically reduces load times, and Quick Resume lets you suspend and instantly resume up to five games simultaneously. Full backward compatibility with thousands of original Xbox, Xbox 360, and Xbox One titles preserves your entire library.",
                'fr' => "La Xbox Series X delivre 12 teraflops de puissance GPU avec l'architecture AMD RDNA 2 et un CPU Zen 2 8 coeurs, permettant un vrai gaming en 4K jusqu'a 120 fps dans les titres supportes. Un SSD NVMe personnalise de 1 To reduit considerablement les temps de chargement, et la Reprise rapide vous permet de suspendre et de reprendre instantanement jusqu'a cinq jeux simultanement. La retrocompatibilite avec des milliers de titres Xbox original, Xbox 360 et Xbox One preserve l'integralite de votre bibliotheque.",
            ],
            [
                'title' => 'Steam Deck OLED 512 Go',
                'cat'   => 'gaming', 'price' => '679.00',
                'img'   => 'https://images.unsplash.com/photo-1593640495253-23196b27a87f?w=500&h=380&fit=crop&q=80',
                'en' => "The Steam Deck OLED replaces the original LCD with a 7.4-inch HDR OLED panel at 90 Hz with 1,000 nits peak brightness, delivering a dramatically more vibrant and power-efficient display. The 6nm AMD APU, 16 GB LPDDR5 RAM, and 50 Whr battery run the full Steam library at playable settings. SteamOS 3.0 supports Proton compatibility layers for Windows games without modification.",
                'fr' => "Le Steam Deck OLED remplace l'ecran LCD original par un panneau HDR OLED de 7,4 pouces a 90 Hz avec 1 000 nits de luminosite en pic, offrant un affichage considerablement plus vibrant et econome en energie. L'APU AMD 6 nm, les 16 Go de RAM LPDDR5 et la batterie de 50 Wh font tourner toute la bibliotheque Steam. SteamOS 3.0 supporte les couches de compatibilite Proton pour les jeux Windows sans modification.",
            ],
            [
                'title' => 'Casque gaming HyperX Cloud Alpha',
                'cat'   => 'gaming', 'price' => '149.00',
                'img'   => 'https://images.unsplash.com/photo-1605296867304-46d5465a13f1?w=500&h=380&fit=crop&q=80',
                'en' => "The HyperX Cloud Alpha features dual-chamber drivers that separate bass from mid and high frequencies for a cleaner, more detailed stereo soundstage in games and music. The aluminum frame, braided cable, and memory foam leatherette earcups are built for marathon gaming sessions with minimal listener fatigue. Compatible with PC, PS4/PS5, Xbox, Switch, and mobile via a standard 3.5 mm connection with no software required.",
                'fr' => "Le HyperX Cloud Alpha dispose de haut-parleurs a double chambre qui separent les basses des frequences moyennes et aiguës pour une scene sonore stereo plus propre et plus detaillee dans les jeux et la musique. Le cadre en aluminium, le cable tresse et les coussinets en mousse a memoire de forme en similicuir sont concus pour des sessions de gaming marathon avec une fatigue minimale. Compatible avec PC, PS4/PS5, Xbox, Switch et mobile via un jack 3,5 mm standard, sans logiciel requis.",
            ],
            [
                'title' => 'Souris gaming Razer DeathAdder V3',
                'cat'   => 'gaming', 'price' => '89.99',
                'img'   => 'https://images.unsplash.com/photo-1527864550417-7fd91fc51a46?w=500&h=380&fit=crop&q=80',
                'en' => "The Razer DeathAdder V3 is a wired ultra-lightweight ergonomic gaming mouse at just 63g, equipped with the Focus Pro 30,000 DPI optical sensor that tracks accurately on any surface including glass. Razer Optical Mouse Switches 3rd Gen actuate at 0.2 ms response with a 90-million-click lifespan, while the ergonomic right-handed shape supports comfortable palm and claw grip for extended competitive sessions.",
                'fr' => "La Razer DeathAdder V3 est une souris gaming filaire ergonomique ultra-legere de seulement 63 g, equipee du capteur optique Focus Pro 30 000 DPI qui trace avec precision sur n'importe quelle surface y compris le verre. Les switchs optiques Razer 3e generation s'actionnent a 0,2 ms de reponse avec une duree de vie de 90 millions de clics, tandis que la forme ergonomique main droite supporte une prise en paume et griffes confortable pour de longues sessions competitives.",
            ],
            [
                'title' => 'Clavier gaming SteelSeries Apex Pro TKL',
                'cat'   => 'gaming', 'price' => '229.00',
                'img'   => 'https://images.unsplash.com/photo-1587829741301-dc798b83add3?w=500&h=380&fit=crop&q=80',
                'en' => "The SteelSeries Apex Pro TKL is the first keyboard with OmniPoint 2.0 adjustable magnetic switches, letting you set actuation points from 0.1 mm to 4.0 mm per key individually for unmatched responsiveness in competitive gaming. The tenkeyless format saves desk space, and the OLED Smart Display shows real-time in-game info and macros. PrismSync RGB, aircraft-grade aluminum frame, and a magnetic wrist rest are included.",
                'fr' => "Le SteelSeries Apex Pro TKL est le premier clavier avec des switchs magnetiques OmniPoint 2.0 reglables, vous permettant de definir des points d'actionnement de 0,1 a 4,0 mm par touche individuellement pour une reactivite inegalee en gaming competitif. Le format tenkeyless economise l'espace sur le bureau, et l'ecran intelligent OLED affiche des informations en jeu en temps reel et des macros. Eclairage RGB PrismSync, chassis en aluminium aeronautique et repose-poignet magnetique sont inclus.",
            ],
            [
                'title' => 'Meta Quest 3 128 Go',
                'cat'   => 'gaming', 'price' => '649.00',
                'img'   => 'https://images.unsplash.com/photo-1622979135225-d2ba269cf1ac?w=500&h=380&fit=crop&q=80',
                'en' => "The Meta Quest 3 is the world's first mainstream mixed reality headset, featuring a Snapdragon XR2 Gen 2 processor, 8 GB of RAM, and full-color passthrough cameras that blend digital content seamlessly with the physical world. Its pancake lens design achieves 40% less volume than the Quest 2, while the 2064x2208 per-eye display delivers crystal-clear VR and MR visuals. Touch Plus controllers provide natural hand-tracking with no external sensors required.",
                'fr' => "Le Meta Quest 3 est le premier casque de realite mixte grand public au monde, dote d'un processeur Snapdragon XR2 Gen 2, de 8 Go de RAM et de cameras passthrough couleur integrale qui melangent le contenu numerique avec le monde physique. Sa conception a lentilles pancake atteint 40 % moins de volume que le Quest 2, tandis que l'ecran 2064x2208 pixels par oeil offre des visuels VR et MR d'une clarte cristalline. Les controleurs Touch Plus assurent un suivi naturel des mains sans capteurs externes requis.",
            ],
            [
                'title' => 'Manette DualSense Edge PS5',
                'cat'   => 'gaming', 'price' => '229.00',
                'img'   => 'https://images.unsplash.com/photo-1585504198199-20277593b94f?w=500&h=380&fit=crop&q=80',
                'en' => "The DualSense Edge is Sony's first officially licensed high-performance PS5 controller, with swappable stick modules to replace worn-out thumbsticks without voiding any warranty. Per-profile button remapping, trigger deadzones, and stick sensitivity curves are all adjustable in PlayStation system software, and two back buttons are included for a more complete competitive setup. A braided USB-C cable with a locking connector prevents disconnection mid-match.",
                'fr' => "La DualSense Edge est la premiere manette PS5 hautes performances officiellement licenciee par Sony, avec des modules de sticks interchangeables pour remplacer les sticks uses sans annuler la garantie. La personnalisation des boutons par profil, les zones mortes des gachettes et les courbes de sensibilite des sticks sont tous reglables dans le logiciel systeme PlayStation, et deux boutons arriere sont inclus pour une configuration competitive plus complete. Un cable USB-C tresse avec connecteur de verrouillage empeche toute deconnexion en plein match.",
            ],

            // ── Bureau & Accessoires ──────────────────────────────────────────
            [
                'title' => 'Bureau assis-debout FlexiSpot E7',
                'cat'   => 'bureau-accessoires', 'price' => '499.00',
                'img'   => 'https://images.unsplash.com/photo-1593642632559-0c6d3fc62b89?w=500&h=380&fit=crop&q=80',
                'en' => "The FlexiSpot E7 standing desk uses a dual-motor lift system to move silently from 22 to 48 inches in height, supporting desktops up to 275 lbs and suitable for any multi-monitor setup. Four programmable height presets let you switch between sitting and standing with a single button press, and an anti-collision sensor stops the motor if an obstacle is detected. The powder-coated steel frame carries a 15-year warranty.",
                'fr' => "Le bureau assis-debout FlexiSpot E7 utilise un systeme de levage double moteur pour se deplacer silencieusement de 56 a 122 cm de hauteur, supportant des plateaux jusqu'a 125 kg et adapte a tout setup multi-ecrans. Quatre prérĉglages de hauteur programmables permettent de basculer entre position assise et debout en un appui de bouton, et un capteur anti-collision arrete le moteur si un obstacle est detecte. Le cadre en acier thermolaque est garanti 15 ans.",
            ],
            [
                'title' => 'Support laptop Nexstand K2',
                'cat'   => 'bureau-accessoires', 'price' => '59.99',
                'img'   => 'https://images.unsplash.com/photo-1593642632559-0c6d3fc62b89?w=500&h=380&fit=crop&q=80',
                'en' => "The Nexstand K2 is a fully adjustable foldable laptop stand with six height positions from 15 to 27 cm, made from durable ABS plastic weighing just 255 g and folding flat for bag storage. Its universal design fits any laptop from 10 to 17 inches, and non-slip silicone pads protect your laptop while keeping the stand stable on any surface. Raising the screen to eye level is the most effective ergonomic improvement for laptop users.",
                'fr' => "Le Nexstand K2 est un support ordinateur portable entierement reglable et pliable avec six positions en hauteur de 15 a 27 cm, fabrique en plastique ABS durable pesant seulement 255 g et se pliant a plat pour le rangement dans la housse. Son design universel s'adapte a tout ordinateur portable de 10 a 17 pouces, et des patins en silicone antiderapants protegent l'ordinateur tout en maintenant le support stable sur n'importe quelle surface. Ramener l'ecran au niveau des yeux est l'amelioration ergonomique la plus efficace pour les utilisateurs d'ordinateurs portables.",
            ],
            [
                'title' => 'Imprimante HP LaserJet Pro M404dn',
                'cat'   => 'bureau-accessoires', 'price' => '399.00',
                'img'   => 'https://images.unsplash.com/photo-1593642632559-0c6d3fc62b89?w=500&h=380&fit=crop&q=80',
                'en' => "The HP LaserJet Pro M404dn prints monochrome documents at up to 38 pages per minute with a first-page-out time under 6 seconds, making it one of the fastest compact laser printers for office use. Automatic duplex printing cuts paper consumption in half, and the 250-sheet input tray handles mixed media without constant refilling. JetIntelligence toner cartridges report accurate supply levels and are easy to replace.",
                'fr' => "L'imprimante HP LaserJet Pro M404dn imprime des documents monochrome jusqu'a 38 pages par minute avec un temps de sortie de la premiere page inferieur a 6 secondes, ce qui en fait l'une des imprimantes laser compactes les plus rapides pour usage bureautique. L'impression recto-verso automatique reduit de moitie la consommation de papier, et le bac d'alimentation de 250 feuilles gere les supports mixtes sans rechargement constant. Les cartouches de toner JetIntelligence signalent des niveaux d'approvisionnement precis et sont faciles a remplacer.",
            ],
            [
                'title' => 'Webcam 4K Elgato Facecam Pro',
                'cat'   => 'bureau-accessoires', 'price' => '299.00',
                'img'   => 'https://images.unsplash.com/photo-1576073719676-aa95576db207?w=500&h=380&fit=crop&q=80',
                'en' => "The Elgato Facecam Pro captures 4K 60 fps ultra-wide video using a Sony STARVIS sensor with an f/2.0 aperture for exceptional low-light performance without a ring light. Camera Hub software on PC provides granular control over exposure, white balance, shutter speed, and zoom, saving settings to the camera directly so they travel between computers. The Sony sensor eliminates the need for a ring light in most home studio setups.",
                'fr' => "L'Elgato Facecam Pro capture une video ultra-large 4K 60 fps en utilisant un capteur Sony STARVIS avec une ouverture f/2.0 pour des performances exceptionnelles en faible luminosite sans ring light. Le logiciel Camera Hub sur PC offre un controle precis de l'exposition, de la balance des blancs, de la vitesse d'obturation et du zoom, et enregistre les parametres directement dans la camera pour les transporter entre les ordinateurs. Les performances en faible luminosite du capteur Sony eliminent le besoin d'un ring light dans la plupart des home studios.",
            ],
            [
                'title' => 'Station de recharge USB 60W Anker',
                'cat'   => 'bureau-accessoires', 'price' => '49.99',
                'img'   => 'https://images.unsplash.com/photo-1593642632559-0c6d3fc62b89?w=500&h=380&fit=crop&q=80',
                'en' => "The Anker 60W 6-port USB charging station provides two USB-C Power Delivery ports up to 30W each and four USB-A ports with PowerIQ 2.0, charging up to six devices simultaneously from a single wall outlet. ActiveShield 2.0 monitors device temperature more than 3 million times per day and adjusts output to protect connected devices. The compact design with non-slip base suits any desk, bedside table, or travel bag.",
                'fr' => "La station de recharge USB Anker 60W a 6 ports fournit deux ports USB-C Power Delivery jusqu'a 30W chacun et quatre ports USB-A avec PowerIQ 2.0, chargeant jusqu'a six appareils simultanement depuis une seule prise murale. ActiveShield 2.0 surveille la temperature des appareils plus de 3 millions de fois par jour et ajuste la sortie pour les proteger. Le design compact avec base antiderapante convient a tout bureau, table de chevet ou sac de voyage.",
            ],
            [
                'title' => 'Lampe LED sans fil Baseus',
                'cat'   => 'bureau-accessoires', 'price' => '69.99',
                'img'   => 'https://images.unsplash.com/photo-1513506003901-1e6a35ee04d2?w=500&h=380&fit=crop&q=80',
                'en' => "The Baseus wireless LED desk lamp combines a 10W Qi fast-wireless charging pad at the base with a flicker-free LED arm light providing up to 1,000 lux at 50 cm, bright enough for detailed close work. Five color temperatures and five brightness levels are adjustable via touch panel, and the memory function recalls your last setting at next power-on. The USB-A pass-through port on the base charges a second wired device simultaneously.",
                'fr' => "La lampe de bureau LED sans fil Baseus combine un pad de charge sans fil Qi rapide 10W a la base avec un bras LED sans scintillement fournissant jusqu'a 1 000 lux a 50 cm, suffisamment lumineux pour les travaux de precision. Cinq temperatures de couleur et cinq niveaux de luminosite sont reglables via le panneau tactile, et la fonction memoire rappelle vos derniers reglages au prochain allumage. Le port USB-A en sortie sur la base charge simultanement un deuxieme appareil filaire.",
            ],
            [
                'title' => 'Sac à dos Samsonite Securipak 15.6"',
                'cat'   => 'bureau-accessoires', 'price' => '159.00',
                'img'   => 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?w=500&h=380&fit=crop&q=80',
                'en' => "The Samsonite Securipak is a 25-liter anti-theft backpack with an RFID-blocking pocket protecting your passport and contactless cards from electronic skimming. Hidden zippers on the back panel safeguard your main compartment from pickpockets, while a dedicated padded 15.6-inch laptop sleeve and tablet pocket keep devices organized. USB-A and USB-C pass-through ports let you charge devices from an internal power bank without opening the bag.",
                'fr' => "Le sac a dos Samsonite Securipak est un sac a dos anti-vol de 25 litres avec une poche de blocage RFID protégeant votre passeport et vos cartes sans contact contre l'ecremage electronique. Des fermetures cachees sur le panneau arriere protegent votre compartiment principal contre les pickpockets, tandis qu'une pochette ordinateur 15,6 pouces rembourrée et une poche tablette separee gardent les appareils organises. Des ports de passage USB-A et USB-C vous permettent de charger les appareils depuis une batterie interne sans ouvrir le sac.",
            ],
            [
                'title' => 'Chargeur sans fil Qi 15W Belkin',
                'cat'   => 'bureau-accessoires', 'price' => '49.99',
                'img'   => 'https://images.unsplash.com/photo-1593642632559-0c6d3fc62b89?w=500&h=380&fit=crop&q=80',
                'en' => "The Belkin BoostCharge Pro 15W Wireless Charging Pad delivers the fastest possible Qi2 charging speed for compatible iPhones and up to 15W for Samsung Galaxy devices without case removal. An LED indicator confirms charging status, and a built-in coil alignment ring guides your phone to the sweet spot automatically. Comes with a 30W USB-C wall charger in the box so you have everything you need out of the box.",
                'fr' => "Le pad de charge sans fil Belkin BoostCharge Pro 15W delivre la vitesse de charge Qi2 la plus rapide possible pour les iPhone compatibles et jusqu'a 15W pour les appareils Samsung Galaxy sans retrait de coque. Un indicateur LED confirme l'etat de charge, et un anneau d'alignement de bobine integre guide automatiquement votre telephone vers le point ideal. Livre avec un chargeur mural USB-C 30W dans la boite pour demarrer immediatement.",
            ],
        ];
    }
}
