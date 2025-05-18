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
Structure des fichiers à respecter, tous ces dossiers et fichiers doivent être à la racine, tu ne fais aucun dossier qui mènent à la structure suivante :

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
src/Controller (dossiers contrôleurs avec les contrôleurs à l'intérieur)
src/Entity (dossiers entités avec les entités à l'intérieur)
src/Form (dossiers formulaires avec les formulaires à l'intérieur)
src/Repository (dossiers repositories avec les repositories à l'intérieur)
.env

ULTRA IMPORTANT :
- Le contenu de chaque fichier doit être valide et fonctionnel.
- Le projet doit être fonctionnel, il ne doit pas y avoir d'erreurs PHP.
- Chacun des fichiers doivent être cohérents entre eux et les autres.

Demande utilisateur : "$prompt"
TEXT;

$promptContent = <<<PROMPT
Tu es un expert Symfony. Génère un projet Symfony complet et fonctionnel sous forme de JSON.

Règles à suivre STRICTEMENT :

- Chaque clé = chemin absolu d'un fichier ou dossier dans un projet Symfony
- Chaque valeur = contenu exact du fichier si c'est un fichier (PHP, YAML, Twig, JSON, etc.)
- Aucune explication, aucun commentaire, juste un JSON
- Le APP_SECRET doit être généré automatiquement.
- Le APP_ENV doit être "prod"
- Le projet doit être fonctionnel, il ne doit pas y avoir d'erreurs PHP
- Le "base.html.twig" doit être complet.
- Fais un CSS minimaliste et moderne dans styles.css dans le dossier public.
- Le site doit être responsive et entièrement fonctionnel.
- Les fichiers doivent être valide avec tout ce qui est nécéssaire pour un projet Symfony fonctionnel: des contrôleurs, des formulaires, des entités, des repositories,des templates, etc.
- Il faut penser à mettre un "<?php" en début de chaque fichier PHP
- Il faut penser à mettre un "{% extends 'base.html.twig' %}" en début de chaque fichier Twig.
- Les fichiers Twig doivent être ultra complets avec pleins de contenu et bien structurés.

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
