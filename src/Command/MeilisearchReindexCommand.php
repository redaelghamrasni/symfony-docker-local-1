<?php

namespace App\Command;

use App\Entity\Article;
use App\Service\MeilisearchService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:meilisearch:reindex', description: 'Réindexe tous les articles dans Meilisearch')]
class MeilisearchReindexCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private MeilisearchService $meilisearchService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->section('Configuration de l\'index Meilisearch');
        $this->meilisearchService->configureIndex();
        $io->success('Index configuré.');

        $io->section('Réindexation des articles');
        $articles = $this->em->getRepository(Article::class)->findAll();

        $this->meilisearchService->indexAll($articles);

        $io->success(sprintf('%d articles réindexés avec succès.', count($articles)));

        return Command::SUCCESS;
    }
}