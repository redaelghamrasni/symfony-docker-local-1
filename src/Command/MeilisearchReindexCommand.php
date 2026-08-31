<?php

namespace App\Command;

use App\Entity\Article;
use App\Entity\Category;
use App\Entity\Order;
use App\Entity\User;
use App\Service\MeilisearchService;
use App\Service\SearchIndexService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:meilisearch:reindex', description: 'Réindexe les articles, catégories, utilisateurs et commandes dans Meilisearch')]
class MeilisearchReindexCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private MeilisearchService $meilisearchService,
        private SearchIndexService $searchIndexService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->reindexArticles($io);
        $this->reindexCategories($io);
        $this->reindexUsers($io);
        $this->reindexOrders($io);

        return Command::SUCCESS;
    }

    private function reindexArticles(SymfonyStyle $io): void
    {
        $io->section('Articles');
        $this->meilisearchService->configureIndex('articles', [
            'searchableAttributes' => ['title', 'content'],
            'filterableAttributes' => ['price'],
            'sortableAttributes'   => ['price', 'createdAt'],
        ]);

        $articles = $this->em->getRepository(Article::class)->findAll();
        $documents = array_map(
            fn (Article $a) => $this->searchIndexService->toArticleDocument($a),
            $articles,
        );

        $this->meilisearchService->index('articles', $documents);
        $io->success(sprintf('%d article(s) réindexé(s).', count($documents)));
    }

    private function reindexCategories(SymfonyStyle $io): void
    {
        $io->section('Catégories');
        $this->meilisearchService->configureIndex('categories', [
            'searchableAttributes' => ['name'],
            'sortableAttributes'   => ['createdAt'],
        ]);

        $categories = $this->em->getRepository(Category::class)->findAll();
        $documents = array_map(
            fn (Category $c) => $this->searchIndexService->toCategoryDocument($c),
            $categories,
        );

        $this->meilisearchService->index('categories', $documents);
        $io->success(sprintf('%d catégorie(s) réindexée(s).', count($documents)));
    }

    private function reindexUsers(SymfonyStyle $io): void
    {
        $io->section('Utilisateurs');
        $this->meilisearchService->configureIndex('users', [
            'searchableAttributes' => ['firstName', 'lastName', 'email'],
            'sortableAttributes'   => ['createdAt'],
        ]);

        $users = $this->em->getRepository(User::class)->findAll();
        $documents = array_map(
            fn (User $u) => $this->searchIndexService->toUserDocument($u),
            $users,
        );

        $this->meilisearchService->index('users', $documents);
        $io->success(sprintf('%d utilisateur(s) réindexé(s).', count($documents)));
    }

    private function reindexOrders(SymfonyStyle $io): void
    {
        $io->section('Commandes');
        $this->meilisearchService->configureIndex('orders', [
            'searchableAttributes' => ['id', 'customerFirstName', 'customerLastName', 'customerEmail'],
            'filterableAttributes' => ['status'],
            'sortableAttributes'   => ['createdAt', 'total'],
        ]);

        $orders = $this->em->getRepository(Order::class)->findAll();
        $documents = array_map(
            fn (Order $o) => $this->searchIndexService->toOrderDocument($o),
            $orders,
        );

        $this->meilisearchService->index('orders', $documents);
        $io->success(sprintf('%d commande(s) réindexée(s).', count($documents)));
    }
}
