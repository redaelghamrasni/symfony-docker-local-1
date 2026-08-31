<?php

namespace App\Command;

use App\Service\ChatbotModelResolver;
use App\Service\SettingService;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\InputProcessor\SystemPromptInputProcessor;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Store\Document\VectorizerInterface;
use Symfony\AI\Store\Query\VectorQuery;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:ask-faq',
    description: 'Ask a question to the FAQ and get an answer based on the context.',
)]
final class AskFaqCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'ai.vectorizer.faq_bgem3')]
        private readonly VectorizerInterface $vectorizer,
        #[Autowire(service: 'ai.store.postgres.faq_bgem3')]
        private readonly StoreInterface $store,
        #[Autowire(service: 'ai.platform.ollama')]
        private readonly PlatformInterface $ollamaPlatform,
        #[Autowire(service: 'ai.platform.gemini')]
        private readonly PlatformInterface $geminiPlatform,
        private readonly ChatbotModelResolver $modelResolver,
        private readonly SettingService $settingService,
        #[Autowire(param: 'app.chatbot.system_prompt')]
        private readonly string $systemPrompt,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('question', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $question = $input->getArgument('question');

        // 1. RETRIEVAL : retrouver les passages pertinents
        $queryVector = $this->vectorizer->vectorize($question);
        $results = $this->store->query(
            new VectorQuery($queryVector),
            ['max_items' => 3]
        );

        if (empty($results)) {
            $io->warning('No relevant passages found in the FAQ.');
            return Command::SUCCESS;
        }

        // 2. AUGMENTED : construire le contexte à partir des passages
        $context = '';
        foreach ($results as $doc) {
            $meta = $doc->getMetadata()->getArrayCopy();
            $context .= sprintf(
                "- Q: %s\n  R: %s\n",
                $meta['question'] ?? '',
                $meta['answer'] ?? '',
            );
        }

        $prompt = <<<PROMPT
            Context (extracts from our FAQ) :
            $context

            Customer question : $question

            Answer the customer's question by only using
            the context above.
            PROMPT;

        // 3. GENERATION : the agent drafts the response
        $io->section('Question');
        $io->text($question);

        $model = $this->settingService->get('chatbot.model', 'qwen2.5');
        $platform = $this->modelResolver->isGeminiModel($model) ? $this->geminiPlatform : $this->ollamaPlatform;
        $agent = new Agent($platform, $model, [new SystemPromptInputProcessor($this->systemPrompt)]);

        $result = $agent->call($prompt);

        $io->section('Generated Answer');
        $io->text($result->getContent());

        return Command::SUCCESS;
    }
}