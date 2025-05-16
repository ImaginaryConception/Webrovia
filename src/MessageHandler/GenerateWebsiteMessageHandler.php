<?php

namespace App\MessageHandler;

use App\Entity\Prompt;
use App\Service\AIService;
use App\Repository\PromptRepository;
use App\Message\GenerateWebsiteMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

#[AsMessageHandler]
class GenerateWebsiteMessageHandler
{
    private const MAX_RETRIES = 3;

    public function __construct(
        private PromptRepository $promptRepository,
        private EntityManagerInterface $entityManager,
        private AIService $aiService
    ) {}

    public function __invoke(GenerateWebsiteMessage $message)
    {
        $prompt = $this->promptRepository->find($message->getPromptId());
        if (!$prompt) {
            // error_log("Prompt non trouvé : ID " . $message->getPromptId());
            return;
        }

        try {
            $this->processPrompt($prompt);
        } catch (\Exception $e) {
            $this->handleError($prompt, $e);
        } finally {
            $this->entityManager->flush();
        }
    }

    private function processPrompt(Prompt $prompt): void
    {
        $retryCount = 0;
        $lastError = null;
        $promptContent = $prompt->getModificationRequest() ?? $prompt->getContent();

        do {
            try {
                $files = $this->aiService->makeRequest($promptContent);

                // if (empty($files)) {
                //     throw new \RuntimeException("Aucun fichier généré.");
                // }

                $prompt->setGeneratedFiles($files);
                $prompt->setStatus('completed');
                return;
            } catch (HttpExceptionInterface|\Exception $e) {
                $lastError = $e;
                $retryCount++;
                if ($retryCount < self::MAX_RETRIES) {
                    // error_log("Tentative $retryCount/$this::MAX_RETRIES échouée : " . $e->getMessage());
                    sleep(pow(2, $retryCount));
                }
            }
        } while ($retryCount < self::MAX_RETRIES);

        throw $lastError ?? new \RuntimeException('Échec de la génération après plusieurs tentatives');
    }

    private function handleError(Prompt $prompt, \Exception $e): void
    {
        $errorMessage = $this->formatErrorMessage($e);
        // error_log("Erreur lors de la génération : " . $errorMessage);
        $prompt->setStatus('error');
        $prompt->setError($errorMessage);
    }

    private function formatErrorMessage(\Exception $e): string
    {
        if ($e instanceof HttpExceptionInterface) {
            return sprintf('Erreur HTTP %d : %s', $e->getCode(), $e->getMessage());
        }

        return sprintf('Erreur : %s', $e->getMessage());
    }
}
