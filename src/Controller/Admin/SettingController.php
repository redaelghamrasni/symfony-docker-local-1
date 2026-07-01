<?php

namespace App\Controller\Admin;

use App\Entity\Setting;
use App\Service\SettingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/settings', name: 'admin_settings_')]
#[IsGranted('ROLE_ADMIN')]
class SettingController extends AbstractController
{
    public function __construct(
        private SettingService $settingService,
        private EntityManagerInterface $em
    ) {}

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $token = $request->request->get('_token');
            if (!$this->isCsrfTokenValid('admin_settings', $token)) {
                $this->addFlash('error', 'admin.settings.csrf_error');
                return $this->redirectToRoute('admin_settings_index');
            }

            $settings = $request->request->all('settings');
            foreach ($settings as $key => $value) {
                $existing = $this->em->getRepository(Setting::class)->find($key);
                if ($existing) {
                    $existing->setValue($value !== '' ? $value : null);
                    $this->em->flush();
                }
            }

            $this->addFlash('success', 'admin.settings.saved');
            return $this->redirectToRoute('admin_settings_index');
        }

        return $this->render('admin/settings/index.html.twig', [
            'settings' => $this->settingService->all(),
        ]);
    }
}
