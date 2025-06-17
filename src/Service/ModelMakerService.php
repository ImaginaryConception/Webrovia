<?php

namespace App\Service;

use App\Entity\ModelMaker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ModelMakerService
{
    private HttpClientInterface $client;
    private string $apiKey;
    private EntityManagerInterface $entityManager;

    public function __construct(
        HttpClientInterface $client,
        EntityManagerInterface $entityManager
    ) {
        $this->client = $client;
        $this->entityManager = $entityManager;
        $this->apiKey = 'FPSX5d2efdb41974ed50fae3ec7566c25300';
    }

    private function updateGenerationMessage(ModelMaker $modelMaker, string $message): void
    {
        $modelMaker->setGenerationMessage($message);
        $this->entityManager->persist($modelMaker);
        $this->entityManager->flush();
    }

    public function generateModelFromPrompt(ModelMaker $modelMaker): ?string
    {
        $this->updateGenerationMessage($modelMaker, 'Initialisation de la génération de la maquette...');


        $promptText = "Create a professional website mockup for {$modelMaker->getTitle()} with the following details: {$modelMaker->getPrompt()}. Make it modern, clean, and professional with a responsive design. Include navigation, header, content sections, and footer. Use a professional color scheme and typography.";

        $this->updateGenerationMessage($modelMaker, 'Envoi de la demande à Freepik AI...');

        try {

            $response = $this->client->request('POST', 'https://api.freepik.com/v1/ai/text-to-image', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-freepik-api-key' => $this->apiKey,
                ],
                'json' => [
                    'prompt' => $promptText,
                    'num_images' => 1,
                    'size' => '1024x576',
                ],
            ]);

            $this->updateGenerationMessage($modelMaker, 'Traitement de la réponse...');

            $data = $response->toArray(false);


            if (isset($data['data'][0]['base64'])) {

                $imageUrl = $this->saveBase64Image($data['data'][0]['base64'], $modelMaker->getId());
            } else {

                throw new \RuntimeException('Aucune image n\'a été générée par Freepik AI. Réponse: ' . json_encode($data));
            }

            $modelMaker->setStatus('completed');
            $modelMaker->setImageUrl($imageUrl);
            $modelMaker->setGenerationMessage('Maquette générée avec succès');
            $this->entityManager->persist($modelMaker);
            $this->entityManager->flush();

            return $imageUrl;

        } catch (\Exception $e) {

            $modelMaker->setStatus('error');
            $modelMaker->setError($e->getMessage());
            $modelMaker->setGenerationMessage('Erreur lors de la génération de la maquette');
            $this->entityManager->persist($modelMaker);
            $this->entityManager->flush();

            return null;
        }
    }

    private function saveBase64Image(string $base64Data, int $modelId): string
    {
        $uploadDir = 'uploads/models/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $filename = 'model_' . $modelId . '_' . uniqid() . '.png';
        $filePath = $uploadDir . $filename;

        $imageData = base64_decode($base64Data);
        file_put_contents($filePath, $imageData);

        return '/' . $filePath;
    }
}
