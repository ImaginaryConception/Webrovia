<?php

namespace App\Service;

use App\Entity\Prompt;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use App\Message\GenerateWebsiteMessage;

class PromptRestorationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus
    ) {}

    public function handleErrorAndRestore(Prompt $prompt): array
    {
        $response = [
            'status' => $prompt->getStatus(),
            'error' => $prompt->getError(),
            'restoration_initiated' => false
        ];

        // Mettre à jour le statut en archived pour le prompt en erreur
        $prompt->setStatus('archived');
        $this->entityManager->flush();

        // Trouver la version précédente du même site
        $previousPrompt = $this->entityManager->getRepository(Prompt::class)->findOneBy([
            'websiteIdentification' => $prompt->getWebsiteIdentification(),
            'version' => $prompt->getVersion() - 1
        ]);

        if ($previousPrompt) {
            // Mettre à jour le statut du prompt précédent en completed
            $previousPrompt->setStatus('completed');
            $this->entityManager->flush();

            $response['restoration_initiated'] = true;
        }

        return $response;
    }

    public function getPromptFiles(Prompt $prompt): array
    {
        $files = [];
        $generatedFiles = $prompt->getGeneratedFiles();

        if ($generatedFiles) {
            foreach ($generatedFiles as $filename => $content) {
                // Nettoyer et décoder correctement le contenu JSON
                $cleanContent = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $decodedContent = json_decode($cleanContent, true);
                $files[$filename] = $decodedContent !== null ? $decodedContent : $cleanContent;
            }
        }

        return $files;
    }
}