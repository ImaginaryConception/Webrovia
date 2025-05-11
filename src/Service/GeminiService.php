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
    private const TARGET_DIR = __DIR__ . '/../../generated_site';
    private const REQUIRED_FILES = [
        'templates/base.html.twig',
        'assets/app.js',
        'config/services.yaml',
        'config/routes.yaml',
        'config/packages/security.yaml',
        'config/packages/doctrine.yaml',
        'src/Kernel.php'
    ];

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
        // Supprimer tout contenu HTML potentiel avant le JSON
        $content = preg_replace('/<[^>]*>.*?<\/[^>]*>/', '', $content);
        
        // Trouver le premier caractère JSON valide
        if (preg_match('/[{\[]/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            $startPos = $matches[0][1];
            $content = substr($content, $startPos);
        } else {
            error_log("Aucun début de JSON valide trouvé dans la réponse");
            return false;
        }
        
        // Nettoyer les caractères non-JSON à la fin
        $content = preg_replace('/[\s\S]*$/m', '', $content);
        
        // Vérifier si le contenu est vide après nettoyage
        if (empty($content)) {
            error_log("Le contenu est vide après nettoyage");
            return false;
        }
        
        // Tenter de décoder le JSON
        $decoded = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Erreur de décodage JSON du contenu généré : " . json_last_error_msg());
            error_log("Contenu problématique : " . substr($content, 0, 1000));
            return false;
        }
        
        // Vérifier si le JSON décodé contient des fichiers générés
        if (!is_array($decoded) || empty($decoded)) {
            error_log("Le contenu JSON décodé est vide ou n'est pas un tableau");
            return false;
        }
        
        // Vérifier la structure attendue du JSON
        if (!isset($decoded['contents']) || !isset($decoded['contents'][0]['parts'])) {
            error_log("Structure JSON invalide : manque de champs requis");
            return false;
        }
        
        return true;
    }

    private function validateTemplateStructure(): void
    {
        if (!is_dir(self::TEMPLATE_DIR)) {
            throw new \RuntimeException('Le répertoire template n\'existe pas : ' . self::TEMPLATE_DIR);
        }

        $missingFiles = [];
        foreach (self::REQUIRED_FILES as $file) {
            $filePath = self::TEMPLATE_DIR . DIRECTORY_SEPARATOR . $file;
            if (!file_exists($filePath)) {
                $missingFiles[] = $file;
            }
        }

        if (!empty($missingFiles)) {
            error_log('Fichiers requis manquants dans le template : ' . implode(', ', $missingFiles));
            throw new \RuntimeException('Structure Symfony incomplète. Fichiers requis manquants : ' . implode(', ', $missingFiles));
        }
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
        foreach (self::REQUIRED_FILES as $file) {
            $sourcePath = self::TEMPLATE_DIR . DIRECTORY_SEPARATOR . $file;
            $targetPath = $targetDir . DIRECTORY_SEPARATOR . $file;
            
            // Créer le répertoire parent si nécessaire
            $targetDir = dirname($targetPath);
            if (!is_dir($targetDir)) {
                if (!mkdir($targetDir, 0777, true)) {
                    throw new \RuntimeException('Impossible de créer le répertoire : ' . $targetDir);
                }
            }
            
            if (file_exists($sourcePath)) {
                if (!copy($sourcePath, $targetPath)) {
                    throw new \RuntimeException('Impossible de copier le fichier requis : ' . $file);
                }
            } else {
                error_log('Fichier requis non trouvé dans le template : ' . $file);
            }
        }

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



    public function makeRequest(string $prompt, int $retryCount = 0): array
    {
        try {
            // Vérifier la structure du template avant de procéder
            $this->validateTemplateStructure();

            // Copier la structure du template vers le répertoire cible
            $targetDir = self::TARGET_DIR;
            $this->copyTemplateStructure($targetDir);
            $cacheKey = md5($prompt);
            $cacheItem = $this->cache->getItem($cacheKey);

            if ($cacheItem->isHit()) {
                $files = $cacheItem->get();
                $this->validateSymfonyStructure($files);
                return $files;
            }

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
                                ['text' => "Tu es un générateur de code web professionnel spécialisé dans la création de sites web Symfony modernes et multi-fichiers. Tu dois créer une structure de site web complète et organisée selon les standards Symfony, en gérant à la fois le frontend et le backend. Tu dois générer TOUS les fichiers nécessaires pour une application Symfony fonctionnelle, en respectant strictement les conventions de nommage et l\'organisation des fichiers Symfony. Voici la structure COMPLÈTE à reproduire :

1. Structure racine :
   - .env : Configuration des variables d'environnement
   - composer.json : Dépendances du projet
   - composer.lock : Versions exactes des dépendances
   - symfony.lock : Verrouillage des versions Symfony
   - phpunit.xml.dist : Configuration des tests
   - importmap.php : Configuration des imports JavaScript

2. Dossiers principaux :
   /assets
   - app.js : Point d'entrée JavaScript
   - bootstrap.js : Configuration Bootstrap
   - controllers.json : Configuration des contrôleurs
   - /controllers : Contrôleurs JavaScript
   - /styles/app.css : Styles CSS
   - /vendor : Dépendances front-end

   /bin
   - console : Console Symfony
   - phpunit : Exécutable PHPUnit

   /config
   - bundles.php : Activation des bundles
   - preload.php : Configuration du préchargement
   - services.yaml : Configuration des services
   - routes.yaml : Routes principales
   - /packages : Configuration des bundles
     * asset_mapper.yaml
     * cache.yaml
     * debug.yaml
     * doctrine.yaml
     * framework.yaml
     * mailer.yaml
     * messenger.yaml
     * monolog.yaml
     * notifier.yaml
     * routing.yaml
     * security.yaml
     * translation.yaml
     * twig.yaml
     * validator.yaml
     * web_profiler.yaml
   - /routes
     * framework.yaml
     * security.yaml
     * web_profiler.yaml

   /migrations
   - Version*.php : Migrations de base de données

   /public
   - index.php : Point d'entrée de l'application

   /src
   - Kernel.php : Noyau Symfony
   - /Controller : Contrôleurs PHP
   - /Entity : Entités Doctrine
   - /Repository : Repositories Doctrine
   - /Form : Types de formulaire
   - /Service : Services métier

   /templates
   - base.html.twig : Template de base
   - /components : Composants réutilisables
   - /pages : Pages principales
   - /partials : Fragments de template
   - /forms : Templates de formulaire
   - /bundles : Surcharges de templates

   /tests
   - bootstrap.php : Configuration des tests

   /translations
   - messages.{locale}.yaml : Traductions

   /var
   - /cache : Cache de l'application
   - /log : Logs de l'application

   /vendor
   - Toutes les dépendances PHP installées via Composer
   
3. Configuration détaillée :
   /config/packages/
   - framework.yaml :
     * Paramètres du framework Symfony
     * Configuration des sessions
     * Configuration du cache
   - doctrine.yaml :
     * Configuration de la base de données
     * Mapping des entités
     * Configuration du cache
   - security.yaml :
     * Providers d'authentification
     * Firewalls et access_control
     * Encodeurs de mot de passe
   - twig.yaml :
     * Chemins des templates
     * Variables globales
     * Extensions Twig

4. Dépendances principales (composer.json) :
   - symfony/framework-bundle : ^6.3
   - symfony/runtime : ^6.3
   - doctrine/doctrine-bundle : ^2.10
   - doctrine/doctrine-migrations-bundle : ^3.2
   - doctrine/orm : ^2.16
   - symfony/asset-mapper : ^6.3
   - symfony/console : ^6.3
   - symfony/dotenv : ^6.3
   - symfony/flex : ^2
   - symfony/form : ^6.3
   - symfony/mailer : ^6.3
   - symfony/security-bundle : ^6.3
   - symfony/twig-bundle : ^6.3
   - symfony/validator : ^6.3
   - symfony/yaml : ^6.3

5. Structure des composants :
   /src/Controller/
   - AbstractController comme classe de base
   - Un contrôleur par fonctionnalité
   - Actions CRUD complètes (index, show, new, edit, delete)
   - Injection des services via constructeur
   - Validation des données entrantes
   - Gestion des erreurs et exceptions

   /src/Entity/
   - Annotations Doctrine pour le mapping
   - Validations avec Symfony Assert
   - Relations entre entités
   - Getters et setters
   - Méthodes de cycle de vie (@ORM\PrePersist, etc.)

   /src/Repository/
   - Extension de ServiceEntityRepository
   - Méthodes de requêtage personnalisées
   - Pagination des résultats
   - Filtres de recherche
   - Cache des requêtes

   /src/Form/
   - Types de formulaire par entité
   - Validation constraints
   - Personnalisation des champs
   - Gestion des uploads
   - Transformers de données

6. Configuration des assets et du front-end :
   /assets/
   - app.js :
     * Import des dépendances JavaScript
     * Configuration des plugins
     * Initialisation des composants
     * Gestion des événements

   - bootstrap.js :
     * Configuration de Stimulus
     * Import des contrôleurs
     * Configuration des plugins Bootstrap

   - controllers.json :
     * Définition des contrôleurs Stimulus
     * Configuration des dépendances
     * Lazy loading des composants

   - /styles/app.css :
     * Variables CSS personnalisées
     * Thèmes clair/sombre
     * Classes utilitaires
     * Composants personnalisés
     * Responsive design

7. Structure des templates :
   /templates/
   - base.html.twig :
     * Structure HTML de base
     * Méta-tags SEO
     * Import des assets
     * Blocs principaux

   - /components/ :
     * header.html.twig : En-tête du site
     * footer.html.twig : Pied de page
     * sidebar.html.twig : Barre latérale
     * navigation.html.twig : Menu principal

   - /pages/ :
     * home.html.twig : Page d'accueil
     * about.html.twig : À propos
     * contact.html.twig : Contact
     * error.html.twig : Pages d'erreur

   - /forms/ :
     * _form.html.twig : Base des formulaires
     * fields.html.twig : Champs personnalisés
     * validation.html.twig : Messages d'erreur

8. Configuration de la sécurité :
   /config/packages/security.yaml :
   ```yaml
   security:
     password_hashers:
       Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface: 'auto'
     providers:
       app_user_provider:
         entity:
           class: App\Entity\User
           property: email
     firewalls:
       dev:
         pattern: ^/(_(profiler|wdt)|css|images|js)/
         security: false
       main:
         lazy: true
         provider: app_user_provider
         form_login:
           login_path: app_login
           check_path: app_login
         logout:
           path: app_logout
     access_control:
       - { path: ^/admin, roles: ROLE_ADMIN }
       - { path: ^/profile, roles: ROLE_USER }
   ```

9. Configuration de la base de données :
   /config/packages/doctrine.yaml :
   ```yaml
   doctrine:
     dbal:
       url: '%env(resolve:DATABASE_URL)%'
     orm:
       auto_generate_proxy_classes: true
       enable_lazy_ghost_objects: true
       report_fields_where_declared: true
       validate_xml_mapping: true
       naming_strategy: doctrine.orm.naming_strategy.underscore_number_aware
       auto_mapping: true
       mappings:
         App:
           is_bundle: false
           dir: '%kernel.project_dir%/src/Entity'
           prefix: 'App\Entity'
           alias: App
   ```

10. Configuration des routes :
    /config/routes.yaml :
    ```yaml
    controllers:
      resource:
        path: ../src/Controller/
        namespace: App\Controller
      type: attribute

    kernel:
      resource: ../src/Kernel.php
      type: attribute
    ```

6. Structure des entités :
   Exemple de Post.php :
   ```php
   #[ORM\Entity(repositoryClass: PostRepository::class)]
   class Post
   {
       #[ORM\Id]
       #[ORM\GeneratedValue]
       #[ORM\Column]
       private ?int \$id = null;
       
       #[ORM\Column(length: 255)]
       #[Assert\NotBlank]
       private ?string \$title = null;
       
       #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'posts')]
       #[ORM\JoinColumn(nullable: false)]
       private ?User \$author = null;
   }
   ```

7. Format de réponse :
   Pour chaque fichier généré, utiliser STRICTEMENT ce format :
   ```nom_du_fichier
   contenu complet du fichier avec imports et namespace
   ```

8. Exigences techniques minimales :
   Frontend :
   - Base Twig avec héritage et blocks
   - Composants réutilisables
   - Assets compilés et optimisés
   - Responsive design mobile-first
   
   Backend :
   - Architecture en couches (Controller, Service, Repository)
   - Validation des données
   - Gestion des erreurs
   - Tests unitaires et fonctionnels
   - Documentation API (si applicable)
   
   Sécurité :
   - Authentification utilisateur
   - Contrôle d'accès RBAC
   - Protection CSRF
   - Validation des entrées
   - Échappement des sorties

Si une structure Symfony est demandée, inclus les fichiers selon les bonnes pratiques Symfony.
Ne commence pas ta réponse par du texte explicatif, génère directement les fichiers.
Assure-toi que chaque fichier est complet et fonctionnel individuellement.

Voici la demande spécifique à retourner obligatoirement en JSON : " . $prompt]
                            ]
                        ]
                    ],
                    'generationConfig' => [
                        'temperature' => 0.8,
                        'maxOutputTokens' => 2500
                    ]
                ]
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                error_log("Réponse HTTP $statusCode de l'API Gemini");
                throw new \RuntimeException("Erreur de communication avec l'API Gemini (HTTP $statusCode)");
            }

            $content = $response->getContent();
            if (empty($content)) {
                error_log("Réponse vide reçue de l'API Gemini");
                throw new \RuntimeException("Réponse vide reçue de l'API Gemini");
            }

            // Nettoyage initial du contenu
            $content = $this->cleanHtmlContent($content);
            $content = preg_replace('/[\x00-\x1F\x7F]/u', '', $content);

            // Décodage et validation du JSON
            $jsonResponse = json_decode($content, true);
            if ($jsonResponse === null) {
                error_log("Erreur de décodage JSON : " . json_last_error_msg());
                throw new \RuntimeException("Format de réponse invalide de l'API Gemini");
            }

            // Validation de la structure de la réponse
            if (!isset($jsonResponse['candidates'][0]['content']['parts'][0]['text'])) {
                error_log("Structure de réponse invalide : " . json_encode($jsonResponse));
                throw new \RuntimeException("Format de réponse inattendu de l'API Gemini");
            }

            $generatedContent = $jsonResponse['candidates'][0]['content']['parts'][0]['text'];
            if (!$this->validateGeneratedContent($generatedContent)) {
                error_log("Contenu généré invalide");
                throw new \RuntimeException("Le contenu généré ne respecte pas le format attendu");
            }

            // Nettoyage et extraction du contenu généré
            $generatedContent = html_entity_decode($generatedContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $files = [];
            
            // Extraction simplifiée des blocs de code
            if (preg_match_all('/```(?:.*?)\s*([\w\-\.\/]+)\s*\n([\s\S]*?)```/m', $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $filename = trim($match[1]);
                    $fileContent = trim($match[2]);
                    
                    if (!empty($filename) && !empty($fileContent)) {
                        $files[$filename] = $fileContent;
                    }
                }
            }
            
            if (empty($files)) {
                error_log("Aucun bloc de code valide trouvé dans la réponse");
                throw new \RuntimeException("La réponse ne contient pas de code généré valide");
            }

            $cacheItem->set($files);
            $this->cache->save($cacheItem);
            
            return $files;

        } catch (HttpExceptionInterface $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            if ($statusCode === 401) {
                error_log("Erreur d'authentification Gemini: Clé API invalide ou expirée");
                throw new \RuntimeException("Erreur d'authentification: Veuillez vérifier votre clé API Gemini");
            } elseif ($statusCode === 429) {
                if ($retryCount >= self::MAX_RETRIES) {
                    error_log("Échec après " . self::MAX_RETRIES . " tentatives. Limite de requêtes Gemini atteinte.");
                    throw new \RuntimeException("Limite de requêtes Gemini atteinte. Veuillez réessayer dans quelques minutes.");
                }

                $delay = min(self::INITIAL_RETRY_DELAY * pow(2, $retryCount), self::MAX_RETRY_DELAY);
                $jitter = rand(0, 2000);
                $totalDelay = $delay + $jitter;

                error_log(sprintf(
                    "Rate-limit Gemini (429) - Tentative %d/%d - Délai: %d ms - Prochain essai dans %.1f secondes",
                    $retryCount + 1,
                    self::MAX_RETRIES,
                    $totalDelay,
                    $totalDelay / 1000
                ));

                usleep($totalDelay * 1000);
                return $this->makeRequest($prompt, $retryCount + 1);
            }
            throw new \RuntimeException(
                'Erreur lors de la communication avec l\'API Gemini: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
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
        $requiredFiles = [
            'templates/base.html.twig',
            'assets/styles/app.css',
            'assets/app.js',
            'config/services.yaml',
            'config/routes.yaml',
            'config/packages/security.yaml',
            'config/packages/doctrine.yaml',
            'src/Kernel.php'
        ];

        $missingFiles = array_diff($requiredFiles, array_keys($files));
        if (!empty($missingFiles)) {
            throw new \RuntimeException('Structure Symfony incomplète. Fichiers requis manquants : ' . implode(', ', $missingFiles));
        }

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