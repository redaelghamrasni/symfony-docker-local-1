<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Decides whether a chatbot.model value is a hosted Gemini model or an Ollama model to pull locally.
 */
class ChatbotModelResolver
{
    public function __construct(
        #[Autowire(param: 'app.chatbot.gemini_models')]
        private readonly array $geminiModels,
    ) {
    }

    public function isGeminiModel(string $model): bool
    {
        return in_array($model, $this->geminiModels, true);
    }
}
