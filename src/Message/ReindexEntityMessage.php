<?php

namespace App\Message;

final class ReindexEntityMessage
{
    /**
     * @param 'article'|'category'|'user'|'order' $entityType
     */
    public function __construct(
        private readonly string $entityType,
        private readonly int $id,
        private readonly bool $remove = false,
    ) {
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function isRemove(): bool
    {
        return $this->remove;
    }
}
