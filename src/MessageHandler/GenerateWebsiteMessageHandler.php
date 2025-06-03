<?php

namespace App\MessageHandler;

use App\Entity\User;
use App\Entity\Prompt;
use App\Service\AIService;
use App\Repository\PromptRepository;
use App\Message\GenerateWebsiteMessage;
use App\Service\SymfonyProjectGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

#[AsMessageHandler]
class GenerateWebsiteMessageHandler
{
    private const MAX_RETRIES = 3;

    public function __construct(
        private PromptRepository $promptRepository,
        private EntityManagerInterface $entityManager,
        private AIService $aiService,
        private Security $security,
    ) {}

    public function __invoke(GenerateWebsiteMessage $message)
    {
        $prompt = $this->promptRepository->find($message->getPromptId());
        if (!$prompt) {
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
        $existingFiles = $prompt->getModificationRequest() ? $prompt->getGeneratedFiles() : null;
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new \LogicException('L\'utilisateur connecté n\'est pas une instance de App\Entity\User');
        }

        // Générer la structure Symfony de base
        $projectDir = sys_get_temp_dir() . '/webforge_' . uniqid();
        $generator = new \App\Service\SymfonyProjectGenerator($projectDir);
        $symfonyStructure = $generator->generate();

        do {
            try {
                // Génération des fichiers frontend et backend
                $files = $this->aiService->generateWebsiteFromPrompt($promptContent, $existingFiles);

                if (empty($files)) {
                    throw new \RuntimeException("Aucun fichier généré.");
                }

                // Fusionner la structure Symfony avec les fichiers générés
                if ($symfonyStructure['success']) {
                    $files = array_merge($symfonyStructure['structure'], $files);
                }

                $prompt->setGeneratedFiles($files);
                $prompt->setStatus('completed');
                $user->setCount($user->getCount() + 1);
                return;
            } catch (HttpExceptionInterface|\Exception $e) {
                $lastError = $e;
                $retryCount++;
                if ($retryCount < self::MAX_RETRIES) {
                    sleep(pow(2, $retryCount));
                }
            }
        } while ($retryCount < self::MAX_RETRIES);

        throw $lastError ?? new \RuntimeException('Échec de la génération après plusieurs tentatives');
    }

    private function handleError(Prompt $prompt, \Exception $e): void
    {
        $errorMessage = $this->formatErrorMessage($e);
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
