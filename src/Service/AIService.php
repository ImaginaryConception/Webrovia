<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

class AIService
{
    private HttpClientInterface $client;
    private FilesystemAdapter $cache;
    private const TEMPLATE_DIR = __DIR__ . '/../../WebForgeSymfonyProject/generator-template';
    private const OLLAMA_URL = 'http://localhost:11434/api/generate';
    private const MODEL_NAME = 'codellama:13b-instruct';

    public function __construct(HttpClientInterface $client)
    {
        $this->client = $client;
        $this->cache = new FilesystemAdapter('ollama', 3600, __DIR__ . '/../../var/cache/ollama');
    }

    public function makeRequest(string $prompt): array
    {
        try {
            $this->validateTemplateStructure();
            $templateStructure = $this->getTemplateStructure();

            $promptContent = "Tu es un expert Symfony spécialisé dans la modification de projets existants. Voici la structure complète d'un projet Symfony avec tous ses fichiers : \n" . 
                json_encode($templateStructure, JSON_PRETTY_PRINT) . 
                "\n\nModifie cette structure selon la demande suivante, en conservant la même organisation mais en adaptant le contenu des fichiers. Retourne la structure modifiée au format JSON avec le même format que l'entrée. Règle importante : Ne supprime aucun fichier existant, modifie uniquement leur contenu ou ajoute de nouveaux fichiers si nécessaire. Assure-toi que chaque fichier a un contenu valide et complet. Voici la demande de site que tu dois refaire : " . $prompt;
            $response = $this->client->request('POST', self::OLLAMA_URL, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json'
                ],
                'json' => [
                    'model' => self::MODEL_NAME,
                    'prompt' => $promptContent,
                    'stream' => false
                ],
                'timeout' => 2000,
            ]);

            file_put_contents('prompt.txt', $promptContent);

            $content = $response->getContent(false);
            if (empty($content)) {
                error_log('Réponse vide reçue de l\'API Ollama');
                throw new \RuntimeException('Réponse vide reçue de l\'API Ollama');
            }

            file_put_contents('ollamaResponse.json', $content);

            $responseData = json_decode($content, true);
            if (!isset($responseData['response'])) {
                error_log('Structure de réponse Ollama invalide');
                throw new \RuntimeException('Format de réponse Ollama invalide');
            }

            return $this->parseJsonResponse($responseData['response']);

        } catch (HttpExceptionInterface $e) {
            error_log('Erreur HTTP lors de la requête à l\'API Ollama : ' . $e->getMessage());
            throw $e;
        }
    }

    private function parseJsonResponse(string $content): array
    {
        try {
            $content = trim($content);
            
            // Nettoyer les délimiteurs de bloc de code JSON
            $content = preg_replace('/^```(?:json)?\s*/', '', $content);
            $content = preg_replace('/\s*```$/', '', $content);
            
            // Tenter de décoder le texte comme JSON
            $decodedContent = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decodedContent)) {
                // Valider et transformer la structure des fichiers
                $files = [];
                foreach ($decodedContent as $path => $fileInfo) {
                    if (is_array($fileInfo) && isset($fileInfo['content'])) {
                        $files[$path] = $fileInfo['content'];
                    } elseif (is_string($fileInfo)) {
                        $files[$path] = $fileInfo;
                    }
                }
                return $files;
            }
            
            error_log('Erreur: La réponse n\'est pas un JSON valide contenant des fichiers');
            return ['a'];
            
        } catch (\JsonException $e) {
            error_log('Erreur lors du décodage JSON: ' . $e->getMessage());
            return ['b'];
        }
    }

    private function validateGeneratedContent(string $content): bool
    {
        $content = trim($content);
        if (empty($content)) {
            error_log('Le contenu est vide après nettoyage');
            return false;
        }
        return true;
    }

    private function cleanHtmlContent(string $content): string
    {
        $content = preg_replace('/<!--[\s\S]*?-->/', '', $content);
        $content = preg_replace('/<[^>]*>/', '', $content);
        $content = preg_replace('/\s+/', ' ', $content);
        return trim($content);
    }

    private function validateTemplateStructure(): void
    {
        if (!is_dir(self::TEMPLATE_DIR)) {
            throw new \RuntimeException('Le répertoire template n\'existe pas : ' . self::TEMPLATE_DIR);
        }
    }

    private function getTemplateStructure(): array
    {
        $templatePath = self::TEMPLATE_DIR;
        return $this->scanDirectory($templatePath);
    }

    private function scanDirectory(string $path, string $relativePath = ''): array
    {
        $result = [];
        $items = scandir($path);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === 'vendor' || $item === 'var' || $item === '.git') {
                continue;
            }

            $fullPath = $path . '/' . $item;
            $relativeItemPath = $relativePath ? $relativePath . '/' . $item : $item;

            if (is_dir($fullPath)) {
                $subDirContent = $this->scanDirectory($fullPath, $relativeItemPath);
                if (!empty($subDirContent)) {
                    $result[$relativeItemPath] = ['type' => 'directory', 'children' => $subDirContent];
                }
            } else {
                $content = file_get_contents($fullPath);
                if ($content !== false) {
                    $result[$relativeItemPath] = [
                        'type' => 'file',
                        'content' => $content
                    ];
                }
            }
        }

        return $result;
    }
}