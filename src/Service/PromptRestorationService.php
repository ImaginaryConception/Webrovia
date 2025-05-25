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

    public function handleError(Prompt $prompt): array
    {
        $response = [
            'status' => $prompt->getStatus(),
            'error' => $prompt->getError(),
            'restoration_available' => false
        ];

        // Mettre à jour le statut en archived pour le prompt en erreur
        $prompt->setStatus('archived');
        $this->entityManager->flush();

        // Vérifier si une version précédente existe
        $previousPrompt = $this->entityManager->getRepository(Prompt::class)->findOneBy([
            'websiteIdentification' => $prompt->getWebsiteIdentification(),
            'version' => $prompt->getVersion() - 1
        ]);

        if ($previousPrompt) {
            $response['restoration_available'] = true;
            $response['previous_version'] = $previousPrompt->getId();
        }

        return $response;
    }

    public function restorePreviousVersion(Prompt $prompt): array
    {
        $response = [
            'success' => false,
            'message' => 'Aucune version précédente trouvée'
        ];

        $previousPrompt = $this->entityManager->getRepository(Prompt::class)->findOneBy([
            'websiteIdentification' => $prompt->getWebsiteIdentification(),
            'version' => $prompt->getVersion() - 1
        ]);

        if ($previousPrompt) {
            $previousPrompt->setStatus('completed');
            $this->entityManager->flush();

            $response = [
                'success' => true,
                'message' => 'Version précédente restaurée avec succès'
            ];
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