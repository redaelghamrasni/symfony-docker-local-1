<?php

namespace App\Command;

use Symfony\Component\Uid\Uuid;
use App\Repository\FaqEntryRepository;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\Document\VectorizerInterface;
use Symfony\AI\Store\Indexer\DocumentIndexer;
use Symfony\AI\Store\Indexer\DocumentProcessor;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:index-faq',
    description: 'Indexes FAQ entries (MySQL) in store vectorizers (Gemini + bge-m3)'
)]
final class IndexFaqCommand extends Command
{
    public function __construct(
        private readonly FaqEntryRepository $faqRepository,
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
        $entries = $this->faqRepository->findAll();

        if (empty($entries)) {
            $io->warning('No FAQ entries found. Did you load the fixtures?');
            return Command::SUCCESS;
        }

        // Construire les documents une seule fois (mêmes textes pour les deux modèles)
        $documents = [];
        foreach ($entries as $entry) {
            $content = sprintf(
                "Question: %s\nAnswer: %s",
                $entry->getQuestion(),
                $entry->getAnswer(),
            );

            $documents[] = new TextDocument(
                id: Uuid::v5(
                    Uuid::fromString('6ba7b810-9dad-11d1-80b4-00c04fd430c8'),
                    (string) $entry->getId()
                )->toRfc4122(),
                content: $content,
                metadata: new Metadata([
                    'faq_id' => $entry->getId(),
                    'locale' => $entry->getLocale(),
                    'category' => $entry->getCategory(),
                    'question' => $entry->getQuestion(),
                    'answer' => $entry->getAnswer(),
                ]),
            );
        }

        // Un indexer par modèle, chacun avec son processor (vectorizer + store)
        $targets = [
            'Gemini' => new DocumentIndexer(
                new DocumentProcessor($this->geminiVectorizer, $this->geminiStore)
            ),
            'bge-m3' => new DocumentIndexer(
                new DocumentProcessor($this->bgem3Vectorizer, $this->bgem3Store)
            ),
        ];

        foreach ($targets as $label => $indexer) {
            $io->section(sprintf('Indexation with %s', $label));

            // Repartir propre : vider le store avant de réindexer
            match ($label) {
                'Gemini' => $this->geminiStore->clear(),
                'bge-m3' => $this->bgem3Store->clear(),
            };

            $indexer->index($documents);

            $io->success(sprintf('%d entries indexed in the %s store.', count($documents), $label));
        }

        return Command::SUCCESS;
    }
}