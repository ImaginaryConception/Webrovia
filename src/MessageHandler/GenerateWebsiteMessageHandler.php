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
    // Les extensions de fichiers ne sont plus à valider car nous utilisons un template existant
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
            error_log(sprintf('Prompt non trouvé avec l\'ID: %d', $message->getPromptId()));
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
        $existingFiles = $this->getExistingFiles($prompt);
        $promptContent = $this->getPromptContent($prompt);

        $retryCount = 0;
        $lastError = null;

        do {
            try {
                $files = $this->aiService->makeRequest($promptContent);
                $this->validateGeneratedFiles($files, $existingFiles);

                $prompt->setGeneratedFiles($files);
                $prompt->setStatus('completed');
                return;
            } catch (HttpExceptionInterface $e) {
                $lastError = $e;
                $retryCount++;
                if ($retryCount < self::MAX_RETRIES) {
                    error_log(sprintf('Tentative %d/%d échouée pour le prompt %d: %s',
                        $retryCount,
                        self::MAX_RETRIES,
                        $prompt->getId(),
                        $e->getMessage()
                    ));
                    sleep(pow(2, $retryCount));
                }
            }
        } while ($retryCount < self::MAX_RETRIES);

        throw $lastError ?? new \RuntimeException('Échec de la génération après plusieurs tentatives');
    }

    private function getExistingFiles(Prompt $prompt): ?array
    {
        if ($prompt->getModificationRequest() && $prompt->getOriginalPrompt()) {
            return $prompt->getOriginalPrompt()->getGeneratedFiles();
        }
        return null;
    }

    private function getPromptContent(Prompt $prompt): string
    {
        return $prompt->getModificationRequest() ?? $prompt->getContent();
    }

    private function validateAndParseResponse($rawResponse): array
    {
        // Validation détaillée de la réponse vide
        if ($rawResponse === null || $rawResponse === '') {
            throw new \RuntimeException('La réponse de Gemini est vide.');
        }

        if (is_string($rawResponse) && trim($rawResponse) === '') {
            throw new \RuntimeException('La réponse de Gemini ne contient que des espaces.');
        }

        // Accepter la réponse directement si c'est déjà un tableau
        if (is_array($rawResponse)) {
            if (empty($rawResponse)) {
                throw new \RuntimeException('La réponse de Gemini est un tableau vide.');
            }
            return $rawResponse;
        }

        // Tenter de parser comme JSON
        $files = json_decode($rawResponse, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (!is_array($files)) {
                throw new \RuntimeException('La réponse JSON de Gemini n\'est pas un tableau valide.');
            }
            if (empty($files)) {
                throw new \RuntimeException('La réponse JSON de Gemini est un tableau vide.');
            }
            return $files;
        }

        // Si ce n'est pas du JSON valide, traiter comme une réponse texte simple
        if (!is_string($rawResponse)) {
            throw new \RuntimeException('Format de réponse Gemini non valide : ' . gettype($rawResponse));
        }

        return ['content' => $rawResponse];
    }

    private function validateGeneratedFiles(array $files, ?array $existingFiles): void
    {
        // if (empty($files)) {
        //     throw new \RuntimeException('Aucun fichier généré par Gemini');
        // }

        if (!$existingFiles) {
            $this->validateRequiredFiles($files);
        }
    }

    private function validateRequiredFiles(array $files): void
    {
        // La validation n'est plus nécessaire car nous utilisons un template existant
        return;
    }

    private function handleError(Prompt $prompt, \Exception $e): void
    {
        $errorMessage = sprintf(
            'Erreur lors de la génération du site web (Prompt ID: %d): %s',
            $prompt->getId(),
            $e->getMessage()
        );
        error_log($errorMessage);

        $prompt->setStatus('error');
        $prompt->setError($e->getMessage());
    }
    
}