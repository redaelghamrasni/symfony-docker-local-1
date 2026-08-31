<?php

namespace App\Controller\Admin;

use App\Entity\Setting;
use App\Message\PullOllamaModelMessage;
use App\Service\ChatbotModelResolver;
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
        private ChatbotModelResolver $modelResolver,
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
                    && !$this->modelResolver->isGeminiModel($value)
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
        $modelAvailability = $this->ollamaModelService->checkAvailability($this->chatbotAvailableModels);
        $chatbotDownloadedModels = array_keys(array_filter($modelAvailability));

        // Gemini is a hosted API, not a local download — always "ready", never listed for storage cleanup.
        foreach ($this->chatbotAvailableModels as $model) {
            if ($this->modelResolver->isGeminiModel($model)) {
                $modelAvailability[$model] = true;
            }
        }

        return $this->render('admin/settings/index.html.twig', [
            'settings' => $this->settingService->all(),
            'selectOptions' => [
                'chatbot.model' => $this->chatbotAvailableModels,
            ],
            'chatbotModel' => $chatbotModel,
            'chatbotModelReady' => $modelAvailability[$chatbotModel] ?? false,
            'chatbotModelAvailability' => $modelAvailability,
            'chatbotDownloadedModels' => $chatbotDownloadedModels,
        ]);
    }

    #[Route('/chatbot-model/free-space', name: 'chatbot_model_free_space', methods: ['POST'])]
    public function freeChatbotModelSpace(Request $request): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('admin_settings_free_space', $token)) {
            $this->addFlash('error', 'admin.settings.csrf_error');
            return $this->redirectToRoute('admin_settings_index');
        }

        $model = $request->request->get('model');
        if (!in_array($model, $this->chatbotAvailableModels, true) || $this->modelResolver->isGeminiModel($model)) {
            $this->addFlash('error', 'admin.settings.chatbot_model_free_error');
            return $this->redirectToRoute('admin_settings_index');
        }

        try {
            $this->ollamaModelService->deleteModel($model);
            $this->addFlash('success', 'admin.settings.chatbot_model_freed');
        } catch (\Throwable) {
            $this->addFlash('error', 'admin.settings.chatbot_model_free_error');
        }

        return $this->redirectToRoute('admin_settings_index');
    }

    #[Route('/chatbot-model/free-all-space', name: 'chatbot_model_free_all_space', methods: ['POST'])]
    public function freeAllChatbotModelSpace(Request $request): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('admin_settings_free_all_space', $token)) {
            $this->addFlash('error', 'admin.settings.csrf_error');
            return $this->redirectToRoute('admin_settings_index');
        }

        $availability = $this->ollamaModelService->checkAvailability($this->chatbotAvailableModels);
        $failures = 0;

        foreach (array_keys(array_filter($availability)) as $model) {
            try {
                $this->ollamaModelService->deleteModel($model);
            } catch (\Throwable) {
                $failures++;
            }
        }

        if ($failures > 0) {
            $this->addFlash('error', 'admin.settings.chatbot_model_free_all_error');
        } else {
            $this->addFlash('success', 'admin.settings.chatbot_model_freed_all');
        }

        return $this->redirectToRoute('admin_settings_index');
    }
}
