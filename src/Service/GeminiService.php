<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\DomCrawler\Crawler;

class GeminiService
{
    private HttpClientInterface $client;
    private string $apiKey;
    private FilesystemAdapter $cache;
    private string $baseUrl;
    private const MAX_RETRIES = 8;
    private const INITIAL_RETRY_DELAY = 5000;
    private const MAX_RETRY_DELAY = 60000;
    private const URL_PATTERN = '/^https?:\/\/(?:www\.)?[-a-zA-Z0-9@:%._\+~#=]{1,256}\.[a-zA-Z0-9()]{1,6}\b(?:[-a-zA-Z0-9()@:%_\+.~#?&\/=]*)$/i';

    public function __construct(HttpClientInterface $client)
    {
        $this->client = $client;

        if (!isset($_ENV['GEMINI_API_KEY']) || empty($_ENV['GEMINI_API_KEY'])) {
            error_log('ERREUR CRITIQUE: La clé API Gemini n\'est pas définie dans les variables d\'environnement');
            throw new \RuntimeException('La clé API Gemini n\'est pas configurée. Veuillez définir la variable d\'environnement GEMINI_API_KEY.');
        }

        $this->apiKey = $_ENV['GEMINI_API_KEY'];

        // Masquage de la clé pour l'affichage
        $maskedKey = substr($this->apiKey, 0, 5) . str_repeat('*', strlen($this->apiKey) - 9) . substr($this->apiKey, -4);
        error_log(sprintf('Gemini Service initialisé avec la clé API: %s (longueur: %d caractères)', $maskedKey, strlen($this->apiKey)));

        $this->cache = new FilesystemAdapter('gemini', 3600, __DIR__ . '/../../var/cache/gemini');
    }

    private const TEMPLATE_DIR = __DIR__ . '/../../WebForgeSymfonyProject/generator-template';
    // Les fichiers requis sont déjà présents dans le template, pas besoin de les valider

    private function cleanHtmlContent(string $content): string
    {
        // Supprimer les commentaires HTML
        $content = preg_replace('/<!--[\s\S]*?-->/', '', $content);
        
        // Supprimer les balises HTML
        $content = preg_replace('/<[^>]*>/', '', $content);
        
        // Nettoyer les espaces et les retours à la ligne superflus
        $content = preg_replace('/\s+/', ' ', $content);
        
        return trim($content);
    }

    private function validateGeneratedContent(string $content): bool
    {
        // Nettoyage basique du contenu
        $content = trim($content);
        
        // Vérifier si le contenu est vide
        if (empty($content)) {
            error_log("Le contenu est vide après nettoyage");
            return false;
        }
        
        return true;
    }

    private function validateTemplateStructure(): void
    {
        if (!is_dir(self::TEMPLATE_DIR)) {
            throw new \RuntimeException('Le répertoire template n\'existe pas : ' . self::TEMPLATE_DIR);
        }

        // $missingFiles = [];
        // foreach (self::REQUIRED_FILES as $file) {
        //     $filePath = self::TEMPLATE_DIR . DIRECTORY_SEPARATOR . $file;
        //     if (!file_exists($filePath)) {
        //         $missingFiles[] = $file;
        //     }
        // }

        // if (!empty($missingFiles)) {
        //     error_log('Fichiers requis manquants dans le template : ' . implode(', ', $missingFiles));
        //     throw new \RuntimeException('Structure Symfony incomplète. Fichiers requis manquants : ' . implode(', ', $missingFiles));
        // }
    }



    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $maxRetries = 3;
        $retryDelay = 100000; // 100ms

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $path = $item->getRealPath();
            $success = false;
            $attempts = 0;

            while (!$success && $attempts < $maxRetries) {
                try {
                    if ($item->isDir()) {
                        $success = @rmdir($path);
                    } else {
                        $success = @unlink($path);
                    }

                    if (!$success) {
                        $attempts++;
                        if ($attempts < $maxRetries) {
                            usleep($retryDelay);
                        }
                    }
                } catch (\Exception $e) {
                    error_log(sprintf('Erreur lors de la suppression de %s: %s', $path, $e->getMessage()));
                    $attempts++;
                    if ($attempts < $maxRetries) {
                        usleep($retryDelay);
                    }
                }
            }

            if (!$success) {
                error_log(sprintf('Impossible de supprimer %s après %d tentatives', $path, $maxRetries));
            }
        }

        // Tentative de suppression du répertoire principal
        $success = false;
        $attempts = 0;
        while (!$success && $attempts < $maxRetries) {
            try {
                $success = @rmdir($dir);
                if (!$success) {
                    $attempts++;
                    if ($attempts < $maxRetries) {
                        usleep($retryDelay);
                    }
                }
            } catch (\Exception $e) {
                error_log(sprintf('Erreur lors de la suppression du répertoire %s: %s', $dir, $e->getMessage()));
                $attempts++;
                if ($attempts < $maxRetries) {
                    usleep($retryDelay);
                }
            }
        }

        if (!$success) {
            error_log(sprintf('Impossible de supprimer le répertoire %s après %d tentatives', $dir, $maxRetries));
        }
    }

    private function copyTemplateStructure(string $targetDir): void
    {
        if (!is_dir(self::TEMPLATE_DIR)) {
            throw new \RuntimeException('Le répertoire template n\'existe pas : ' . self::TEMPLATE_DIR);
        }

        // Vérifier si le répertoire cible existe déjà et le supprimer si c'est le cas
        if (is_dir($targetDir)) {
            $this->removeDirectory($targetDir);
            // Attendre un court instant pour s'assurer que le système de fichiers est synchronisé
            usleep(100000); // 100ms
        }

        // Vérifier à nouveau si le répertoire existe après la suppression
        if (!is_dir($targetDir)) {
            if (!@mkdir($targetDir, 0777, true)) {
                $error = error_get_last();
                throw new \RuntimeException(sprintf(
                    'Impossible de créer le répertoire cible %s : %s',
                    $targetDir,
                    $error ? $error['message'] : 'Erreur inconnue'
                ));
            }
        }

        // Copier d'abord les fichiers requis pour garantir leur présence
        // foreach (self::REQUIRED_FILES as $file) {
        //     $sourcePath = self::TEMPLATE_DIR . DIRECTORY_SEPARATOR . $file;
        //     $targetPath = $targetDir . DIRECTORY_SEPARATOR . $file;
            
        //     // Créer le répertoire parent si nécessaire
        //     $targetDir = dirname($targetPath);
        //     if (!is_dir($targetDir)) {
        //         if (!mkdir($targetDir, 0777, true)) {
        //             throw new \RuntimeException('Impossible de créer le répertoire : ' . $targetDir);
        //         }
        //     }
            
        //     if (file_exists($sourcePath)) {
        //         if (!copy($sourcePath, $targetPath)) {
        //             throw new \RuntimeException('Impossible de copier le fichier requis : ' . $file);
        //         }
        //     } else {
        //         error_log('Fichier requis non trouvé dans le template : ' . $file);
        //     }
        // }

        // Copier le reste des fichiers
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::TEMPLATE_DIR, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = str_replace(self::TEMPLATE_DIR . DIRECTORY_SEPARATOR, '', $item->getPathname());
            $target = $targetDir . DIRECTORY_SEPARATOR . $relativePath;
            
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    if (!mkdir($target, 0777, true)) {
                        throw new \RuntimeException('Impossible de créer le répertoire : ' . $target);
                    }
                }
            } else {
                if (!copy($item->getPathname(), $target)) {
                    throw new \RuntimeException('Impossible de copier le fichier : ' . $item->getPathname());
                }
            }
        }
    }



    private function saveRawResponse(array $response): void
    {
        $timestamp = date('Y-m-d_H-i-s');
        $filename = sprintf('%s/raw_responses/gemini_response_%s.json', __DIR__ . '/../../var/log', $timestamp);
        
        // Créer le répertoire s'il n'existe pas
        $directory = dirname($filename);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        
        // Extraire uniquement le texte de la réponse
        $rawText = '';
        if (isset($response['candidates'][0]['content']['parts'][0]['text'])) {
            $rawText = $response['candidates'][0]['content']['parts'][0]['text'];
            
            // Nettoyer les délimiteurs de bloc de code JSON
            $rawText = preg_replace('/^```json\s*/', '', $rawText);
            $rawText = preg_replace('/\s*```$/', '', $rawText);
        }
        
        // Sauvegarder uniquement la réponse brute nettoyée
        file_put_contents($filename, $rawText);
    }

    private function buildPrompt(string $prompt): string
    {
        $templateStructure = $this->getTemplateStructure();
        return sprintf(
            "Structure du template Symfony à utiliser comme base :\n%s\n\nDemande de modification :\n%s",
            json_encode($templateStructure, JSON_PRETTY_PRINT),
            $prompt
        );
    }

    private function getTemplateStructure(): array
    {
        $templatePath = __DIR__ . '/../../WebForgeSymfonyProject/generator-template';
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

    public function makeRequest(string $prompt, int $retryCount = 0)
    {
        try {
            $templateStructure = $this->getTemplateStructure();
            $promptContent = "Tu es un expert Symfony spécialisé dans la modification de projets existants. Voici la structure complète d'un projet Symfony avec tous ses fichiers : \n" . 
                json_encode($templateStructure, JSON_PRETTY_PRINT) . 
                "\n\nModifie cette structure selon la demande suivante, en conservant la même organisation mais en adaptant le contenu des fichiers. Retourne la structure modifiée au format JSON avec le même format que l'entrée. Règle importante : Ne supprime aucun fichier existant, modifie uniquement leur contenu ou ajoute de nouveaux fichiers si nécessaire. Assure-toi que chaque fichier a un contenu valide et complet. Voici la demande de site que tu dois refaire : " . $prompt;

            $response = $this->client->request('POST', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent', [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'query' => [
                    'key' => $this->apiKey
                ],
                'json' => [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $promptContent]
                            ]
                        ]
                    ],
                    'generationConfig' => [
                        'temperature' => 0.3,
                        'maxOutputTokens' => 8192
                    ]
                ]
            ]);

            file_put_contents('debug_prompt.txt', $promptContent);

            $responseData = $response->toArray();
            if (!isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
                error_log("Structure de réponse Gemini invalide");
                throw new \RuntimeException("Format de réponse Gemini invalide");
            }

            $this->saveRawResponse($responseData);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                error_log("Réponse HTTP $statusCode de l'API Gemini");
                throw new \RuntimeException("Erreur de communication avec l'API Gemini (HTTP $statusCode): " . $response->getContent(false));
            }

            $content = $response->getContent();
            if (empty($content)) {
                error_log("Réponse vide reçue de l'API Gemini");
                throw new \RuntimeException("Réponse vide reçue de l'API Gemini");
            }

            try {
                $files = $this->parseJsonResponse($content);
                // if (empty($files)) {
                //     throw new \RuntimeException("Aucun fichier généré par l'API Gemini");
                // }
                
                $cacheKey = md5($prompt);
                $cacheItem = $this->cache->getItem($cacheKey);
                $cacheItem->set($files);
                $this->cache->save($cacheItem);
                return $files;
            } catch (\Exception $e) {
                error_log("Erreur lors du parsing du JSON ou de la validation : " . $e->getMessage());
                throw $e;
            }

        } catch (HttpExceptionInterface $e) {
            error_log("Erreur HTTP lors de la requête à l'API Gemini : " . $e->getMessage());
            throw $e;
        }
    }


    private function parseJsonResponse(string $content): array
    {
        try {
            $content = trim($content);
            $jsonResponse = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            
            if (!isset($jsonResponse['candidates'][0]['content']['parts'][0]['text'])) {
                error_log('Erreur: Structure de réponse Gemini invalide - texte manquant');
                return ['a'];
            }
            
            $text = $jsonResponse['candidates'][0]['content']['parts'][0]['text'];
            if (!is_string($text)) {
                error_log('Erreur: Le texte de la réponse n\'est pas une chaîne valide');
                return ['b'];
            }
            
            // Nettoyer les délimiteurs de bloc de code JSON
            $text = preg_replace('/^```(?:json)?\s*/', '', $text);
            $text = preg_replace('/\s*```$/', '', $text);
            
            // Tenter de décoder le texte comme JSON
            $decodedContent = json_decode($text, true);
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
            return ['c'];
            
        } catch (\JsonException $e) {
            error_log('Erreur lors du décodage JSON: ' . $e->getMessage());
            return ['d'];
        }

    }

    private function isValidSymfonyFile(string $filename): bool
    {
        $symfonyPaths = [
            'templates/' => '/^templates\/[\w\-\.\/_]+\.twig$/',
            'src/Controller/' => '/^src\/Controller\/[\w]+Controller\.php$/',
            'src/Entity/' => '/^src\/Entity\/[\w]+\.php$/',
            'src/Repository/' => '/^src\/Repository\/[\w]+Repository\.php$/',
            'src/Form/' => '/^src\/Form\/[\w]+Type\.php$/',
            'src/Service/' => '/^src\/Service\/[\w]+(?:Service|Interface)\.php$/',
            'config/' => '/^config\/(?:routes|services|security)\.yaml$/',
            'assets/' => '/^assets\/(?:styles|js)\/[\w\-\.]+\.[css|js]+$/',
            'migrations/' => '/^migrations\/Version[\d]{14}\.php$/'
        ];

        foreach ($symfonyPaths as $path => $pattern) {
            if (str_starts_with($filename, $path) && preg_match($pattern, $filename)) {
                return true;
            }
        }

        return false;
    }

    private function cleanAndValidateFileContent(string $filename, string $content): string|false
    {
        // Nettoyer le contenu
        $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content);
        $content = preg_replace('/\r\n|\r/', '\n', $content);

        // Validation spécifique selon le type de fichier
        if (str_ends_with($filename, '.php')) {
            if (!str_contains($content, 'namespace') || (!str_contains($content, 'class') && !str_contains($content, 'interface'))) {
                throw new \RuntimeException(sprintf('Fichier PHP invalide (namespace ou class/interface manquant) : %s', $filename));
            }
            
            // Vérifier la présence des use statements essentiels
            if (str_contains($filename, '/Controller/') && !str_contains($content, 'use Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController')) {
                throw new \RuntimeException(sprintf('Contrôleur invalide (AbstractController non importé) : %s', $filename));
            }
            if (str_contains($filename, '/Entity/') && !str_contains($content, 'use Doctrine\\ORM\\Mapping as ORM')) {
                throw new \RuntimeException(sprintf('Entité invalide (annotations Doctrine manquantes) : %s', $filename));
            }
            if (str_contains($filename, '/Form/') && !str_contains($content, 'use Symfony\\Component\\Form\\AbstractType')) {
                throw new \RuntimeException(sprintf('Type de formulaire invalide (AbstractType non importé) : %s', $filename));
            }
        } elseif (str_ends_with($filename, '.twig')) {
            if (!str_contains($content, '{% extends') && !str_contains($content, '{% block')) {
                throw new \RuntimeException(sprintf('Template Twig invalide (extends ou block manquant) : %s', $filename));
            }
            if (!preg_match('/{%\s*block\s+\w+\s*%}.*?{%\s*endblock\s*%}/s', $content)) {
                throw new \RuntimeException(sprintf('Template Twig invalide (structure de bloc incorrecte) : %s', $filename));
            }
        } elseif (str_ends_with($filename, '.yaml')) {
            if (empty(trim($content))) {
                throw new \RuntimeException(sprintf('Fichier YAML vide : %s', $filename));
            }
            if (str_contains($filename, 'security.yaml') && !str_contains($content, 'security:')) {
                throw new \RuntimeException('Configuration de sécurité invalide : structure security: manquante');
            }
            if (str_contains($filename, 'doctrine.yaml') && !str_contains($content, 'doctrine:')) {
                throw new \RuntimeException('Configuration Doctrine invalide : structure doctrine: manquante');
            }
        }

        return $content;
    }

    private function validateSymfonyStructure(array $files): void
    {
        // Vérifier la cohérence entre les entités et les repositories
        $entities = array_filter(array_keys($files), fn($f) => str_starts_with($f, 'src/Entity/'));
        $missingRepos = [];
        foreach ($entities as $entity) {
            $entityName = basename($entity, '.php');
            $expectedRepo = 'src/Repository/' . $entityName . 'Repository.php';
            if (!isset($files[$expectedRepo])) {
                $missingRepos[] = $entityName;
            }
        }

        if (!empty($missingRepos)) {
            throw new \RuntimeException('Repositories manquants pour les entités : ' . implode(', ', $missingRepos));
        }

        // Vérifier la présence des contrôleurs pour chaque entité
        $missingControllers = [];
        foreach ($entities as $entity) {
            $entityName = basename($entity, '.php');
            $expectedController = 'src/Controller/' . $entityName . 'Controller.php';
            if (!isset($files[$expectedController])) {
                $missingControllers[] = $entityName;
            }
        }

        if (!empty($missingControllers)) {
            throw new \RuntimeException('Contrôleurs manquants pour les entités : ' . implode(', ', $missingControllers));
        }
    }

    private function analyzeWebsite(string $url): array
    {
        try {
            $response = $this->client->request('GET', $url);
            $html = $response->getContent();
            $this->baseUrl = $url;
            $crawler = new Crawler($html, $url);

            // Extraire le HTML
            $htmlContent = $crawler->html();

            // Extraire les styles CSS internes
            $internalStyles = $crawler->filter('style')->each(function (Crawler $node) {
                return $node->text();
            });

            // Extraire les scripts JS internes
            $internalScripts = $crawler->filter('script:not([src])')->each(function (Crawler $node) {
                return $node->text();
            });

            // Extraire les liens CSS externes
            $externalStyles = $crawler->filter('link[rel="stylesheet"]')->each(function (Crawler $node) {
                $href = $node->attr('href');
                if ($href && !preg_match('/^https?:\/\//i', $href)) {
                    $href = rtrim($this->baseUrl, '/') . '/' . ltrim($href, '/');
                }
                return $this->client->request('GET', $href)->getContent();
            });

            // Extraire les scripts JS externes
            $externalScripts = $crawler->filter('script[src]')->each(function (Crawler $node) {
                $src = $node->attr('src');
                if ($src && !preg_match('/^https?:\/\//i', $src)) {
                    $src = rtrim($this->baseUrl, '/') . '/' . ltrim($src, '/');
                }
                return $this->client->request('GET', $src)->getContent();
            });

            return [
                'html' => $htmlContent,
                'internalStyles' => implode("\n", $internalStyles),
                'externalStyles' => implode("\n", $externalStyles),
                'internalScripts' => implode("\n", $internalScripts),
                'externalScripts' => implode("\n", $externalScripts)
            ];
        } catch (\Exception $e) {
            error_log("Erreur lors de l'analyse du site web : " . $e->getMessage());
            throw new \RuntimeException("Impossible d'analyser le site web : " . $e->getMessage());
        }
    }

    public function generateWebsiteFromPrompt(string $prompt, ?array $existingFiles = null): array
    {
        // Vérifier si le prompt est une URL
        if (preg_match(self::URL_PATTERN, trim($prompt))) {
            $websiteData = $this->analyzeWebsite(trim($prompt));
            $promptText = sprintf(
                "Analyse ce site web et crée une réplique fidèle en conservant la structure, le style et les fonctionnalités.\n\nContenu HTML :\n```html\n%s\n```\n\nStyles CSS :\n```css\n%s\n%s\n```\n\nScripts JS :\n```javascript\n%s\n%s\n```",
                $websiteData['html'],
                $websiteData['internalStyles'],
                $websiteData['externalStyles'],
                $websiteData['internalScripts'],
                $websiteData['externalScripts']
            );
        } else {
            $promptText = $existingFiles
                ? sprintf(
                    "Voici les fichiers existants à modifier selon la demande suivante : %s\n\nFichiers existants :\n%s",
                    $prompt,
                    implode("\n", array_map(fn($name, $content) => "```$name\n$content\n```", array_keys($existingFiles), $existingFiles))
                )
                : $prompt;
        }

        $files = $this->makeRequest($promptText);

        // Vérification et ajout des fichiers obligatoires
        $templateDir = __DIR__ . '/../../WebForgeSymfonyProject/generator-template';
        $requiredFiles = [
            'templates/base.html.twig' => file_get_contents($templateDir . '/templates/base.html.twig'),
            'assets/styles/app.css' => "/* Styles personnalisés */\n:root {\n    --primary-color: #3490dc;\n    --secondary-color: #ffed4a;\n    --dark-color: #2d3748;\n    --light-color: #f7fafc;\n}\n\n/* Styles de base */\nbody {\n    font-family: 'Inter', sans-serif;\n    line-height: 1.6;\n    color: var(--dark-color);\n    background-color: var(--light-color);\n}\n\n/* Mode sombre */\n@media (prefers-color-scheme: dark) {\n    body {\n        color: var(--light-color);\n        background-color: var(--dark-color);\n    }\n}",
            'assets/app.js' => file_get_contents($templateDir . '/assets/app.js'),
            'config/services.yaml' => file_get_contents($templateDir . '/config/services.yaml'),
            'src/Controller/.gitignore' => '',
            'src/Entity/.gitignore' => '',
            'src/Repository/.gitignore' => ''
        ];
        
        // Copier les fichiers de configuration essentiels
        $configFiles = glob($templateDir . '/config/packages/*.yaml');
        foreach ($configFiles as $configFile) {
            $relativePath = 'config/packages/' . basename($configFile);
            $requiredFiles[$relativePath] = file_get_contents($configFile);
        }

        // Vérification et ajout des fichiers obligatoires s'ils sont manquants
        foreach ($requiredFiles as $filename => $defaultContent) {
            if (!isset($files[$filename])) {
                $files[$filename] = $defaultContent;
                error_log(sprintf('Fichier %s manquant - Ajout du contenu par défaut', $filename));
            }
        }

        // Organisation des fichiers par type
        $fileTypes = [
            'html' => [],
            'css' => [],
            'js' => [],
            'other' => []
        ];

        foreach ($files as $filename => $content) {
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            if (isset($fileTypes[$extension])) {
                $fileTypes[$extension][$filename] = $content;
            } else {
                $fileTypes['other'][$filename] = $content;
            }
        }

        error_log(sprintf(
            'Structure du site générée : %d fichiers HTML, %d fichiers CSS, %d fichiers JS, %d autres fichiers',
            count($fileTypes['html']),
            count($fileTypes['css']),
            count($fileTypes['js']),
            count($fileTypes['other'])
        ));

        // Si nous modifions des fichiers existants, fusionner les résultats
        if ($existingFiles) {
            $files = array_merge($existingFiles, $files);
        }

        return $files;
    }
}