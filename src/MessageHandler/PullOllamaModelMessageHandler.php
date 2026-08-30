<?php

namespace App\MessageHandler;

use App\Message\PullOllamaModelMessage;
use App\Service\OllamaModelService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PullOllamaModelMessageHandler
{
    public function __construct(
        private readonly OllamaModelService $ollamaModelService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(PullOllamaModelMessage $message): void
    {
        $model = $message->getModel();

        try {
            $this->ollamaModelService->pullModel($model);
            $this->logger->info('Ollama model "{model}" pulled successfully.', ['model' => $model]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to pull Ollama model "{model}": {error}', [
                'model' => $model,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
