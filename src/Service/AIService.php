<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

class AIService
{
    private HttpClientInterface $client;
    private FilesystemAdapter $cache;
    private const MISTRAL_API_URL = 'https://api.mistral.ai/v1/chat/completions';
    private const API_KEY = 'ESoQIWE9LvoI7tEcPYoZxACk7j1kF6Mt';
    private const MODEL = 'mistral-small-latest';

    public function __construct(HttpClientInterface $client)
    {
        $this->client = $client;
        $this->cache = new FilesystemAdapter('mistral', 3600, __DIR__ . '/../../var/cache/mistral');
    }

    public function makeRequest(string $prompt): array
    {
        try {
            $structure = <<<TEXT
Structure des fichiers à respecter :

config/packages/cache.yaml  
config/packages/doctrine.yaml  
config/packages/doctrine_migrations.yaml  
config/packages/framework.yaml  
config/packages/mailer.yaml  
config/packages/messenger.yaml  
config/packages/monolog.yaml  
config/packages/notifier.yaml  
config/packages/routing.yaml  
config/packages/security.yaml  
config/packages/translation.yaml  
config/packages/twig.yaml  
config/packages/validator.yaml  
config/routes/framework.yaml  
config/routes/security.yaml  
config/bundles.php  
config/preload.php  
config/routes.yaml  
config/services.yaml  
public/index.php  
templates/base.html.twig  
src/Kernel.php  
src/Controller  
src/Entity  
src/Form  
src/Repository  
.env  
composer.json  
composer.lock  
symfony.lock  
TEXT;

$promptContent = <<<PROMPT
Tu es un expert Symfony. Génère un projet Symfony complet et fonctionnel sous forme de JSON.

Règles à suivre STRICTEMENT :

1. Chaque **clé** = chemin absolu d’un fichier ou dossier dans un projet Symfony
2. Chaque **valeur** = 
   - vide `""` si c’est un dossier
   - contenu exact du fichier si c’est un fichier (PHP, YAML, Twig, JSON, etc.)
3. ⚠️ Tu ne dois jamais écrire `"null"` (ni en texte, ni en valeur JSON), jamais ! Si tu ne sais pas quoi mettre, mets `""` (une chaîne vide) pour un fichier vide, ou alors du vrai code par défaut
4. Aucune explication, aucun commentaire, juste un JSON valide

Demande utilisateur : "$prompt"

Voici la structure à respecter :
$structure
PROMPT;

            $response = $this->client->request('POST', self::MISTRAL_API_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . self::API_KEY,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => self::MODEL,
                    'messages' => [
                        ['role' => 'user', 'content' => $promptContent]
                    ],
                    'temperature' => 0.7,
                ],
                'timeout' => 2000,
            ]);

            $raw = $response->getContent(false);
            if (!$raw) {
                throw new \RuntimeException('Réponse vide de Mistral.');
            }

            $data = json_decode($raw, true);
            if (!isset($data['choices'][0]['message']['content'])) {
                throw new \RuntimeException('Structure de réponse invalide de Mistral.');
            }

            return $this->parseJsonResponse($data['choices'][0]['message']['content']);

        } catch (\Exception $e) {
            error_log('Erreur Mistral : ' . $e->getMessage());
            throw $e;
        }
    }

    private function parseJsonResponse(string $content): array
    {
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*/', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);

        $decoded = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Erreur de décodage JSON : ' . json_last_error_msg());
        }

        $files = [];
        foreach ($decoded as $path => $value) {
            if (!is_string($path)) continue;

            $fileContent = null;
            if (is_array($value) && isset($value['content']) && is_string($value['content'])) {
                $fileContent = $value['content'];
            } elseif (is_string($value)) {
                $fileContent = $value;
            }

            // On skip les fichiers avec "null" (en string) ou null PHP
            if (strtolower(trim($fileContent)) === 'null') {
                error_log("[AIService] Contenu 'null' détecté pour $path → remplacé par string vide.");
                $fileContent = '';
            }
            
            if ($fileContent === null) {
                error_log("[AIService] Fichier ignoré (contenu null PHP): $path");
                continue;
            }

            $files[$path] = $fileContent;
        }

        if (empty($files)) {
            throw new \RuntimeException('Aucun fichier valide trouvé dans la réponse JSON.');
        }

        return $files;
    }
}
