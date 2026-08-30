<?php

namespace App\Message;

final class PullOllamaModelMessage
{
    public function __construct(
        private readonly string $model,
    ) {
    }

    public function getModel(): string
    {
        return $this->model;
    }
}
