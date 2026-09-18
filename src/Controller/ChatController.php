<?php

namespace App\Controller;

use App\Service\SettingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Temporary placeholder: the real chat widget (see the stashed WIP) isn't wired in yet.
 * Replace this controller once it lands — it only exists so /chat doesn't 404 in the meantime.
 */
class ChatController extends AbstractController
{
    public function __construct(
        private readonly SettingService $settingService,
    ) {
    }

    #[Route('/chat', name: 'chat_index')]
    public function index(): Response
    {
        $chatbotEnabled = $this->settingService->getBool('chatbot.enabled', true);

        return $this->render('chat/unavailable.html.twig', [
            'chatbotEnabled' => $chatbotEnabled,
        ], new Response(status: $chatbotEnabled ? 200 : 503));
    }
}
