<?php

namespace App\MessageHandler;

use App\Entity\Article;
use App\Entity\Category;
use App\Entity\Order;
use App\Entity\User;
use App\Message\ReindexEntityMessage;
use App\Service\SearchIndexService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ReindexEntityMessageHandler
{
    private const ENTITY_CLASSES = [
        'article'  => Article::class,
        'category' => Category::class,
        'user'     => User::class,
        'order'    => Order::class,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SearchIndexService $searchIndexService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ReindexEntityMessage $message): void
    {
        $type = $message->getEntityType();
        $id = $message->getId();

        if (!isset(self::ENTITY_CLASSES[$type])) {
            $this->logger->error('Unknown entity type "{type}" for Meilisearch reindex.', ['type' => $type]);
            return;
        }

        try {
            if ($message->isRemove()) {
                $this->remove($type, $id);
                return;
            }

            $entity = $this->em->find(self::ENTITY_CLASSES[$type], $id);
            if ($entity === null) {
                // Deleted again before the message was processed; nothing to index.
                return;
            }

            $this->index($type, $entity);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to reindex {type}#{id} in Meilisearch: {error}', [
                'type' => $type,
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function index(string $type, Article|Category|User|Order $entity): void
    {
        match ($type) {
            'article' => $this->searchIndexService->indexArticle($entity),
            'category' => $this->searchIndexService->indexCategory($entity),
            'user' => $this->searchIndexService->indexUser($entity),
            'order' => $this->searchIndexService->indexOrder($entity),
        };
    }

    private function remove(string $type, int $id): void
    {
        match ($type) {
            'article' => $this->searchIndexService->removeArticle($id),
            'category' => $this->searchIndexService->removeCategory($id),
            'user' => $this->searchIndexService->removeUser($id),
            'order' => $this->searchIndexService->removeOrder($id),
        };
    }
}
