<?php

namespace App\Command;

use Symfony\AI\Store\Document\VectorizerInterface;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\Query\VectorQuery;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:search-faq',
    description: 'Search in the FAQ and compare Gemini vs bge-m3'
)]
final class SearchFaqCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'ai.vectorizer.faq_gemini')]
        private readonly VectorizerInterface $geminiVectorizer,
        #[Autowire(service: 'ai.store.postgres.faq_gemini')]
        private readonly StoreInterface $geminiStore,
        #[Autowire(service: 'ai.vectorizer.faq_bgem3')]
        private readonly VectorizerInterface $bgem3Vectorizer,
        #[Autowire(service: 'ai.store.postgres.faq_bgem3')]
        private readonly StoreInterface $bgem3Store,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $question = $input->getArgument('question');

        $io->title(sprintf('Search : "%s"', $question));

        foreach ([
            ['Gemini', $this->geminiVectorizer, $this->geminiStore],
            ['bge-m3', $this->bgem3Vectorizer, $this->bgem3Store],
        ] as [$label, $vectorizer, $store]) {
            $io->section($label);

            // 1. Vectorize the question with the model
            $queryVector = $vectorizer->vectorize($question);

            // 2. Query the store
            $results = $store->query(
                new VectorQuery($queryVector),
                ['max_items' => 3]
            );

            // 3. Display results
            $rows = [];
            foreach ($results as $doc) {
                $meta = $doc->getMetadata()->getArrayCopy();
                $rows[] = [
                    number_format($doc->getScore(), 4),
                    $meta['locale'] ?? '?',
                    mb_substr($meta['question'] ?? '(?)', 0, 60),
                ];
            }
            $io->table(['Score', 'Locale', 'Question found'], $rows);
        }

        return Command::SUCCESS;
    }

    protected function configure(): void
    {
        $this->addArgument('question', mode: \Symfony\Component\Console\Input\InputArgument::REQUIRED);
    }
}