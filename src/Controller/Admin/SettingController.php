<?php

namespace App\Controller\Admin;

use App\Entity\Setting;
use App\Message\PullOllamaModelMessage;
use App\Service\OllamaModelService;
use App\Service\SettingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/settings', name: 'admin_settings_')]
#[IsGranted('ROLE_ADMIN')]
class SettingController extends AbstractController
{
    public function __construct(
        private SettingService $settingService,
        private EntityManagerInterface $em,
        private OllamaModelService $ollamaModelService,
        private MessageBusInterface $messageBus,
        #[Autowire(param: 'app.chatbot.available_models')]
        private array $chatbotAvailableModels,
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
                if (!$existing) {
                    continue;
                }

                $previousValue = $existing->getValue();
                $existing->setValue($value !== '' ? $value : null);
                $this->em->flush();

                if ('chatbot.model' === $key && $value !== '' && $value !== $previousValue
                    && !$this->ollamaModelService->isModelAvailable($value)
                ) {
                    $this->messageBus->dispatch(new PullOllamaModelMessage($value));
                    $this->addFlash('success', 'admin.settings.chatbot_model_pulling');
                }
            }

            $this->addFlash('success', 'admin.settings.saved');
            return $this->redirectToRoute('admin_settings_index');
        }

        $chatbotModel = $this->settingService->get('chatbot.model', 'qwen2.5');

        return $this->render('admin/settings/index.html.twig', [
            'settings' => $this->settingService->all(),
            'selectOptions' => [
                'chatbot.model' => $this->chatbotAvailableModels,
            ],
            'chatbotModel' => $chatbotModel,
            'chatbotModelReady' => $this->ollamaModelService->isModelAvailable($chatbotModel),
        ]);
    }
}
