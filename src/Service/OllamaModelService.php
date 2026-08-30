<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Wraps Ollama's HTTP API to check which models are downloaded locally and to pull new ones.
 */
class OllamaModelService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $ollamaHost,
    ) {
    }

    /** @return string[] names of the models currently pulled on the Ollama host */
    public function listLocalModels(): array
    {
        try {
            $response = $this->httpClient->request('GET', rtrim($this->ollamaHost, '/').'/api/tags', [
                'timeout' => 5,
            ]);
            $data = $response->toArray(false);
        } catch (ExceptionInterface) {
            return [];
        }

        return array_map(
            static fn (array $model) => $model['name'] ?? $model['model'] ?? '',
            $data['models'] ?? [],
        );
    }

    public function isModelAvailable(string $model): bool
    {
        $wanted = $this->baseName($model);

        foreach ($this->listLocalModels() as $localModel) {
            if ($this->baseName($localModel) === $wanted) {
                return true;
            }
        }

        return false;
    }

    /**
     * Downloads a model through Ollama's pull API. This call blocks until the download
     * completes (it can take minutes for a multi-GB model), so it must only be run from
     * an async message handler, never from a web request.
     */
    public function pullModel(string $model): void
    {
        $response = $this->httpClient->request('POST', rtrim($this->ollamaHost, '/').'/api/pull', [
            'json' => ['model' => $model, 'stream' => false],
            'timeout' => 1800,
        ]);

        // Reading the content waits for the (non-streamed) response and surfaces HTTP errors.
        $response->getContent();
    }

    /**
     * Removes a model's downloaded files from the Ollama host to free up disk space.
     * The model can still be picked again later, which pulls it back down.
     */
    public function deleteModel(string $model): void
    {
        $response = $this->httpClient->request('DELETE', rtrim($this->ollamaHost, '/').'/api/delete', [
            'json' => ['model' => $model],
            'timeout' => 10,
        ]);

        $response->getContent();
    }

    private function baseName(string $model): string
    {
        return strtolower(strstr($model.':', ':', true));
    }
}
