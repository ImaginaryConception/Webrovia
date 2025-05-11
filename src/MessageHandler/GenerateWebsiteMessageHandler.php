<?php

namespace App\MessageHandler;

use App\Entity\Prompt;
use App\Service\GeminiService;
use App\Repository\PromptRepository;
use App\Message\GenerateWebsiteMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

#[AsMessageHandler]
class GenerateWebsiteMessageHandler
{
    private const REQUIRED_FILE_EXTENSIONS = ['.html', '.css', '.js'];
    private const MAX_RETRIES = 3;

    public function __construct(
        private PromptRepository $promptRepository,
        private EntityManagerInterface $entityManager,
        private GeminiService $geminiService
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
                $files = $this->geminiService->makeRequest($promptContent);
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

    private function validateGeneratedFiles(array $files, ?array $existingFiles): void
    {
        if (empty($files)) {
            throw new \RuntimeException('Aucun fichier généré par Gemini');
        }

        if (!$existingFiles) {
            $this->validateRequiredFiles($files);
        }
    }

    private function validateRequiredFiles(array $files): void
    {
        $foundExtensions = [];
        foreach ($files as $filename => $content) {
            foreach (self::REQUIRED_FILE_EXTENSIONS as $ext) {
                if (str_ends_with($filename, $ext)) {
                    $foundExtensions[] = $ext;
                    break;
                }
            }
        }

        $missingExtensions = array_diff(self::REQUIRED_FILE_EXTENSIONS, $foundExtensions);
        if (!empty($missingExtensions)) {
            throw new \RuntimeException(sprintf(
                'Fichiers requis manquants. Extensions manquantes : %s',
                implode(', ', $missingExtensions)
            ));
        }
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