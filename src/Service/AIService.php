<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class AIService
{
    private HttpClientInterface $client;
    private string $apiKey;
    private StubProcessor $stubProcessor;
    private $entityManager;
    private array $frontendData = [];
    private $logger;

    public function __construct(
        HttpClientInterface $client,
        StubProcessor $stubProcessor,
        \Doctrine\ORM\EntityManagerInterface $entityManager,
        \Psr\Log\LoggerInterface $logger = null
    ) {
        $this->client = $client;
        $this->stubProcessor = $stubProcessor;
        $this->entityManager = $entityManager;
        $this->logger = $logger;
        
        // Vérification plus robuste de la clé API
        if (empty($_ENV['GEMINI_API_KEY'])) {
            $this->logError('GEMINI_API_KEY non définie dans les variables d\'environnement');
            throw new \RuntimeException('GEMINI_API_KEY non définie dans les variables d\'environnement');
        }
        
        $this->apiKey = $_ENV['GEMINI_API_KEY'];
        
        // Vérifier que la clé API a un format valide (généralement commence par "AI" pour Gemini)
        if (!preg_match('/^[A-Za-z0-9_-]{30,}$/', $this->apiKey)) {
            $this->logError('Format de GEMINI_API_KEY invalide');
            throw new \RuntimeException('Format de GEMINI_API_KEY invalide');
        }
    }

    /**
     * Génère une entité et son repository à partir d'une table de base de données
     */
    public function generateEntitiesFromTable(string $tableName, array $fields): array
    {
        $this->logError('Début de la génération d\'entité pour la table: ' . $tableName);
        
        // Préparer le prompt pour Gemini
        $entityPrompt = "Tu es un expert en développement Symfony qui va générer une entité et son repository, ainsi que le formtype et le contrôleur à partir de la structure de table suivante:\n";
        $entityPrompt .= "\nNOM DE LA TABLE: {$tableName}\n";
        $entityPrompt .= "\nCHAMPS:\n" . json_encode($fields, JSON_PRETTY_PRINT) . "\n\n";
        $entityPrompt .= "Génère une entité Doctrine et son repository qui:\n";
        $entityPrompt .= "1. Correspondent exactement à la structure de la table\n";
        $entityPrompt .= "2. Utilisent les bons types de données Doctrine pour chaque champ\n";
        $entityPrompt .= "3. Incluent les annotations/attributs appropriés pour:\n";
        $entityPrompt .= "   - Les types de champs\n";
        $entityPrompt .= "   - Les contraintes de validation\n";
        $entityPrompt .= "   - Les options de colonne (nullable, length, etc.)\n";
        $entityPrompt .= "4. Implémentent les méthodes repository utiles pour:\n";
        $entityPrompt .= "   - Les requêtes de base (findBy, findOneBy)\n";
        $entityPrompt .= "   - Les requêtes personnalisées courantes\n";
        $entityPrompt .= "Ainsi que le formtype complet, à faire si besoin.\n";
        $entityPrompt .= "Et le contrôleur complet.\n";
        
        // Ajouter les informations sur les stubs
        $entityPrompt .= "\nUtilise les structures suivantes pour l'entité et le repository:\n";
        $entityPrompt .= "ENTITY STUB:\n" . file_get_contents(__DIR__ . '/Stubs/entity.stub') . "\n\n";
        $entityPrompt .= "REPOSITORY STUB:\n" . file_get_contents(__DIR__ . '/Stubs/repository.stub') . "\n\n";
        $entityPrompt .= "FORM STUB:\n" . file_get_contents(__DIR__ . '/Stubs/form.stub') . "\n\n";
        $entityPrompt .= "CONTROLLER STUB:\n" . file_get_contents(__DIR__ . '/Stubs/controller.stub') . "\n\n";
        
        // Ajouter les instructions pour les templates Twig
        $entityPrompt .= "Génère également les templates Twig suivants:\n";
        $entityPrompt .= "1. templates/new.html.twig : Formulaire de création\n";
        $entityPrompt .= "2. templates/edit.html.twig : Formulaire d'édition\n";
        $entityPrompt .= "3. templates/show.html.twig : Affichage détaillé\n\n";
        $entityPrompt .= "Les templates doivent:\n";
        $entityPrompt .= "- Étendre base.html.twig\n";
        $entityPrompt .= "- Utiliser le système de formulaires Symfony\n";
        $entityPrompt .= "- Avoir un design moderne et responsive\n";
        $entityPrompt .= "- Inclure des messages flash pour les actions CRUD\n";
        $entityPrompt .= "- Utiliser les classes Tailwind CSS pour le style\n";

        $entityPrompt .= "Retourne UNIQUEMENT un objet JSON valide avec les clés étant les chemins des fichiers (ex: 'src/Entity/Product.php') et les valeurs étant le contenu complet des fichiers.\n\n";
        $entityPrompt .= "IMPORTANT: Ta réponse doit être un JSON valide et bien formaté. Utilise le format suivant:\n";
        $entityPrompt .= "```json\n{\n  \"src/Entity/NomEntite.php\": \"<?php\\n\\nnamespace App\\\\Entity;\\n...\",\n  \"src/Repository/NomEntiteRepository.php\": \"<?php\\n\\nnamespace App\\\\Repository;\\n...\"\n}\n```\n\n";
        
        // Appeler Gemini pour générer l'entité et le repository
        $response = $this->client->request('POST', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent', [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'query' => [
                'key' => $this->apiKey,
            ],
            'json' => [
                'contents' => [[
                    'parts' => [[
                        'text' => $entityPrompt
                    ]]
                ]],
                'generationConfig' => [
                    'temperature' => 0.7,
                    'maxOutputTokens' => 8192,
                    'topP' => 0.8,
                    'topK' => 40
                ]
            ],
        ]);
        
        $data = $response->toArray(false);

        // Vérifications détaillées de la structure de la réponse
        if (!is_array($data)) {
            throw new \RuntimeException('La réponse de Gemini n\'est pas un tableau');
        }
        if (!isset($data['candidates']) || !is_array($data['candidates']) || empty($data['candidates'])) {
            throw new \RuntimeException('Pas de candidats dans la réponse de Gemini');
        }
        if (!isset($data['candidates'][0]) || !is_array($data['candidates'][0])) {
            throw new \RuntimeException('Premier candidat invalide dans la réponse de Gemini');
        }
        if (!isset($data['candidates'][0]['content']) || !is_array($data['candidates'][0]['content'])) {
            throw new \RuntimeException('Contenu du candidat invalide dans la réponse de Gemini');
        }
        if (!isset($data['candidates'][0]['content']['parts']) || !is_array($data['candidates'][0]['content']['parts']) || empty($data['candidates'][0]['content']['parts'])) {
            throw new \RuntimeException('Parties du contenu invalides dans la réponse de Gemini');
        }
        if (!isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            throw new \RuntimeException('Texte manquant dans la réponse de Gemini');
        }
        
        $text = $data['candidates'][0]['content']['parts'][0]['text'];
        
        if (!$text) {
            $this->logError('Réponse vide de Gemini', ['data' => json_encode($data)]);
            throw new \RuntimeException('Réponse vide de Gemini pour la génération d\'entité');
        }
        
        $this->logError('Texte extrait de la réponse', ['text' => $text]);
        
        // Extraire le JSON de la réponse
        $this->logError('Tentative d\'extraction du JSON');
        
        // Nettoyer la réponse pour extraire uniquement le JSON
        // Extraire le JSON de la réponse en utilisant une expression régulière plus robuste
        // qui capture le premier bloc JSON complet.
        if (preg_match('/```json\s*({[\s\S]*?})\s*```/', $text, $matches)) {
            $jsonString = $matches[1];
        } elseif (preg_match('/^\s*({[\s\S]*?})\s*$/', $text, $matches)) {
            $jsonString = $matches[1];
        } else {
            $this->logError('Format de réponse invalide', ['text' => $text]);
            throw new \RuntimeException('Format de réponse invalide de Gemini');
        }

        // Vérifier que le JSON extrait commence et se termine par des accolades
        if (!preg_match('/^\s*{.*}\s*$/s', $jsonString)) {
            throw new \RuntimeException('Le JSON extrait n\'est pas un objet valide');
        }

        if (empty($jsonString)) {
            $this->logError('Échec de l\'extraction du JSON', ['text' => $text]);
            throw new \RuntimeException('Aucun JSON trouvé dans la réponse de Gemini');
        }
        
        $this->logError('JSON extrait avec succès', ['json' => $jsonString]);
        
        $jsonText = $jsonString;
        
        // Nettoyer et décoder le JSON
        try {
            // Supprimer les caractères non-JSON au début et à la fin
            $jsonText = trim($jsonText);
            

            
            // Essayer de décoder le JSON
            $decoded = json_decode($jsonText, true, 512, JSON_THROW_ON_ERROR);
            
            if (!is_array($decoded) || empty($decoded)) {
                throw new \RuntimeException('Le JSON décodé n\'est pas un tableau valide ou est vide');
            }
        } catch (\JsonException $e) {
            throw new \RuntimeException('Erreur lors du décodage du JSON: ' . $e->getMessage());
        } catch (\Exception $e) {
            throw new \RuntimeException('Erreur lors du traitement du JSON: ' . $e->getMessage());
        }
        
        $files = [];
        
        try {
            // Stocker les fichiers générés
            foreach ($decoded as $filePath => $fileContent) {
                $this->logError('Traitement du fichier', ['path' => $filePath]);
                
                if (str_starts_with($filePath, 'src/Entity/')) {
                    $entityName = basename($filePath, '.php');
                    $this->logError('Enregistrement de l\'entité', ['name' => $entityName]);
                    $this->stubProcessor->setEntityCode($fileContent, $entityName);
                } elseif (str_starts_with($filePath, 'src/Repository/')) {
                    $repositoryName = basename($filePath, '.php');
                    $this->logError('Enregistrement du repository', ['name' => $repositoryName]);
                    $this->stubProcessor->setRepositoryCode($fileContent, $repositoryName);
                } elseif (str_starts_with($filePath, 'templates/')) {
                    $this->logError('Enregistrement du template Twig', ['path' => $filePath]);
                    // Les templates sont automatiquement inclus dans $files
                }
                
                $files[$filePath] = $fileContent;
            }
            
            if (empty($files)) {
                throw new \RuntimeException('Aucun fichier généré');
            }
            
            $this->logError('Génération terminée avec succès', ['files' => array_keys($files)]);
            return $files;
            
        } catch (\Exception $e) {
            $this->logError('Erreur lors du traitement des fichiers générés', [
                'error' => $e->getMessage(),
                'decoded' => $decoded
            ]);
            throw $e;
        }
    }

    /**
     * Met à jour le message de génération dans la base de données
     */
    private function updateGenerationMessage(\App\Entity\Prompt $prompt, string $message): void
    {
        $prompt->setGenerationMessage($message);
        $this->entityManager->persist($prompt);
        $this->entityManager->flush();
    }
    
    /**
     * Journalise une erreur
     */
    private function logError(string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->error('[AIService] ' . $message, $context);
        }
        
        // Journaliser également dans le fichier de log PHP standard
        error_log('[AIService] ' . $message . (empty($context) ? '' : ' - Context: ' . json_encode($context)));
    }

    public function generateWebsiteFromPrompt(string $prompt, ?array $existingFiles = null): array
    {
        $frontendData = [];
        
        // Récupérer l'entité Prompt à partir du MessageHandler
        $promptEntity = null;
        $user = null;
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 10);
        foreach ($backtrace as $trace) {
            if (isset($trace['args']) && !empty($trace['args'])) {
                foreach ($trace['args'] as $arg) {
                    if ($arg instanceof \App\Entity\Prompt) {
                        $promptEntity = $arg;
                        // Récupérer l'utilisateur associé au prompt
                        $user = $promptEntity->getUser();
                        break 2;
                    }
                }
            }
        }

        // Mettre à jour le message de génération pour indiquer le début du processus
        if ($promptEntity) {
            // Définir explicitement le message initial et le persister immédiatement
            $promptEntity->setGenerationMessage('Initialisation de la génération...');
            $this->entityManager->persist($promptEntity);
            $this->entityManager->flush();
            
            // Puis utiliser la méthode updateGenerationMessage pour le message suivant
            $this->updateGenerationMessage($promptEntity, 'Analyse de la demande en cours...');
        }
        
        // Gestion de la création et suppression des fichiers Twig
        if (preg_match('/^Créer un nouveau fichier ([\w.-]+\.html\.twig)/', $prompt, $matches)) {
            $fileName = $matches[1];
            $prompt = "Create a new Twig template file named '{$fileName}' that extends base.html.twig. The template should define its content within the {% block body %} block. Make sure the content is meaningful and follows best practices for web development. The template should inherit styles from app.css and functionality from app.js.";
        } elseif (preg_match('/^Supprimer le fichier ([\w.-]+\.html\.twig)/', $prompt, $matches)) {
            $fileName = $matches[1];
            if (isset($existingFiles[$fileName])) {
                unset($existingFiles[$fileName]);
                return $existingFiles;
            }
            throw new \RuntimeException("Le fichier {$fileName} n'existe pas");
        }

        $promptText = "Tu es un générateur de code HTML.TWIG/CSS/JS. Commence par expliquer en une ou deux phrases ce que tu vas faire (par exemple: 'Je vais créer un site de e-commerce moderne avec X, Y, Z fonctionnalités...').\n\n";
        $promptText .= "APRÈS ton explication, tu dois retourner un objet JSON contenant le code du site. Le JSON doit être clairement séparé de ton explication et commencer par une ligne contenant uniquement '{'. Le JSON peut contenir les clés suivantes :\n";
        $promptText .= "- index.html.twig : Doit commencer par {% extends 'base.html.twig' %} et définir son contenu dans {% block body %}\n";
        $promptText .= "- app.css : Le CSS doit être écrit de manière brute sans utiliser Asset, il doit être ultra moderne et complet\n";
        $promptText .= "- app.js : Le code JavaScript doit être ultra complet pour la page web\n";
        $promptText .= "- Autres fichiers .html.twig : Tu peux créer d'autres fichiers .html.twig si besoin. Ils doivent tous:\n";
        $promptText .= "  * Commencer par {% extends 'base.html.twig' %}\n";
        $promptText .= "  * Définir leur contenu dans {% block body %}\n";
        $promptText .= "  * Hériter automatiquement de app.js et app.css\n";
        $promptText .= "Ajoute beaucoup de pages Twig pour les besoins\n";
        $promptText .= "Ajoute beaucoup d'animations pour les boutons, textes etc\n";
        $promptText .= "Le design du site doit être moderne avec un affichage optimisé.\n";
        $promptText .= "Pour les images, assures-toi de récupérer des images sur internet avec des liens uniquement, pas de chemin relatif\n";
        $promptText .= "Pour les formulaires, assures-toi de les faire uniquement sous forme de Twig, pas HTML.\n";
        $promptText .= "Ajoute souvent des images pour rendre le site attractif\n";
        $promptText .= "Les liens des images, tu les récupères sur Google Images, je veux que tu mettes des vrais images, pas de #\n";
        $promptText.= "Préfère les sites one page avec des liens de navbar qui mène à des ancres de la page\n";
        $promptText.= "Il faut que ce soit ULTRA MODERNE VISUELLEMENT avec du contenu ULTRA COMPLET, une mise en page ULTRA PROFESSIONNELLE !\n";
        $promptText.= "Il faut que ce soit ultra animé, avec un effet de glissement quand on va dans une encre.\n";
        $promptText .= "IMPORTANT : NE JAMAIS générer le fichier base.html.twig\n\n";
        
        // Ajouter les préférences de l'utilisateur au prompt si disponibles
        if ($user) {
            $promptText .= "PRÉFÉRENCES UTILISATEUR:\n";
            if ($user->getPreferredStyle()) {
                $promptText .= "- Style visuel préféré: " . $user->getPreferredStyle() . "\n";
            }
            if ($user->getBusinessType()) {
                $promptText .= "- Type d'activité: " . $user->getBusinessType() . "\n";
            }
            if ($user->getColorScheme()) {
                $promptText .= "- Schéma de couleurs: " . $user->getColorScheme() . "\n";
            }
            if ($user->getAdditionalPreferences()) {
                $promptText .= "- Préférences additionnelles: " . $user->getAdditionalPreferences() . "\n";
            }
            if ($user->getContactEmail()) {
                $promptText .= "- Email de contact pour les formulaires: " . $user->getContactEmail() . "\n";
            }
            $promptText .= "\nVeuillez respecter ces préférences dans la génération du site. Assurez-vous d'utiliser l'email de contact spécifié pour tous les formulaires de contact dans le frontend.\n\n";
        }

        if ($existingFiles) {
            $promptText .= "Voici le code existant que tu dois ABSOLUMENT conserver et modifier uniquement selon la demande :\n";
            $promptText .= json_encode($existingFiles, JSON_PRETTY_PRINT) . "\n\nIMPORTANT: Tu dois ABSOLUMENT conserver TOUS les fichiers existants. Ne supprime JAMAIS un fichier existant. Modifie uniquement ce qui est explicitement demandé dans le prompt suivant, en gardant le reste du code intact. Si tu dois créer un nouveau fichier, ajoute-le sans supprimer les autres. Explique d'abord ce que tu vas modifier, puis fournis le JSON avec le code modifié : ";
        } else {
            $promptText .= "Chaque valeur dans le JSON doit être une string contenant le code. Ne rajoute pas de texte autour du JSON. Voici la demande : ";
        }

        $promptText .= $prompt . " IMPORTANT : Il faut que ce soit ULTRA MODERNE VISUELLEMENT avec du contenu ULTRA COMPLET, une mise en page ULTRA PROFESSIONNELLE !";

        try {
            // Mettre à jour le message de génération pour indiquer l'envoi de la requête
            if ($promptEntity) {
                $this->updateGenerationMessage($promptEntity, 'Envoi de la requête à l\'API Gemini...');
            }
            
            // Définir un timeout plus long pour les requêtes complexes
            $response = $this->client->request('POST', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent', [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'query' => [
                    'key' => $this->apiKey,
                ],
                'json' => [
                    'contents' => [[
                        'parts' => [[
                            'text' => $promptText
                        ]]
                    ]],
                    'generationConfig' => [
                        'temperature' => 1.0,
                        'maxOutputTokens' => 8192,
                        'topP' => 0.8,
                        'topK' => 40
                    ],
                    'safetySettings' => [
                        ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_NONE'],
                        ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_NONE'],
                        ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_NONE'],
                        ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE']
                    ]
                ],
                'timeout' => 60, // Timeout de 60 secondes
                'max_duration' => 120, // Durée maximale de 120 secondes
            ]);
            
            // Mettre à jour le message de génération pour indiquer que la réponse a été reçue
            if ($promptEntity) {
                $this->updateGenerationMessage($promptEntity, 'Réponse reçue de l\'API Gemini, traitement en cours...');
            }
        } catch (\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface $e) {
            // Erreur de connexion ou timeout
            $errorMsg = 'Erreur de connexion à l\'API Gemini: ' . $e->getMessage();
            $this->logError($errorMsg, ['exception' => get_class($e), 'code' => $e->getCode()]);
            
            if ($promptEntity) {
                $this->updateGenerationMessage($promptEntity, 'Erreur de connexion à l\'API Gemini. Veuillez réessayer.');
            }
            
            throw new \RuntimeException($errorMsg);
        } catch (\Exception $e) {
            // Autres erreurs
            $errorMsg = 'Erreur lors de l\'appel à l\'API Gemini: ' . $e->getMessage();
            $this->logError($errorMsg, ['exception' => get_class($e), 'code' => $e->getCode()]);
            
            if ($promptEntity) {
                $this->updateGenerationMessage($promptEntity, 'Erreur lors de l\'appel à l\'API Gemini. Veuillez réessayer.');
            }
            
            throw new \RuntimeException($errorMsg);
        }

        // Le message de génération est maintenant mis à jour dans le bloc try-catch ci-dessus

        try {
            $data = $response->toArray(false);
            
            // Journaliser la réponse brute pour le débogage (en mode développement uniquement)
            if ($_ENV['APP_ENV'] === 'dev') {
                $this->logError('Réponse brute de Gemini', ['response' => json_encode($data)]);
            }
            
            // Vérifier si la réponse contient une erreur
            if (isset($data['error'])) {
                $errorMessage = $data['error']['message'] ?? 'Erreur inconnue de l\'API Gemini';
                $errorCode = $data['error']['code'] ?? 'Inconnu';
                $errorDetails = $data['error']['details'] ?? [];
                
                $this->logError("Erreur Gemini", [
                    'code' => $errorCode,
                    'message' => $errorMessage,
                    'details' => $errorDetails
                ]);
                
                if ($promptEntity) {
                    $this->updateGenerationMessage($promptEntity, "Erreur de l'API Gemini: {$errorMessage}");
                }
                
                throw new \RuntimeException("Erreur Gemini (code: {$errorCode}): {$errorMessage}");
            }
            
            // Vérifier si la structure de la réponse est correcte
            if (!isset($data['candidates']) || empty($data['candidates'])) {
                $errorMsg = 'Réponse de Gemini sans candidats';
                $this->logError($errorMsg, ['response' => json_encode($data)]);
                
                if ($promptEntity) {
                    $this->updateGenerationMessage($promptEntity, "Erreur: Réponse de l'API incomplète. Veuillez réessayer.");
                }
                
                throw new \RuntimeException($errorMsg);
            }
            
            if (!isset($data['candidates'][0]['content']) || !isset($data['candidates'][0]['content']['parts'])) {
                $errorMsg = 'Structure de réponse Gemini invalide';
                $this->logError($errorMsg, ['response' => json_encode($data)]);
                
                if ($promptEntity) {
                    $this->updateGenerationMessage($promptEntity, "Erreur: Format de réponse invalide. Veuillez réessayer.");
                }
                
                throw new \RuntimeException($errorMsg);
            }
            
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

            if (!$text) {
                $errorMsg = 'Réponse vide de Gemini';
                $this->logError($errorMsg, ['response' => json_encode($data)]);
                
                if ($promptEntity) {
                    $this->updateGenerationMessage($promptEntity, "Erreur: Réponse vide de l'API. Veuillez réessayer.");
                }
                
                throw new \RuntimeException($errorMsg);
            }
        } catch (\Symfony\Contracts\HttpClient\Exception\ExceptionInterface $e) {
            $errorMsg = 'Erreur de communication avec l\'API Gemini: ' . $e->getMessage();
            $this->logError($errorMsg, ['exception' => get_class($e), 'code' => $e->getCode()]);
            
            if ($promptEntity) {
                $this->updateGenerationMessage($promptEntity, "Erreur de communication avec l'API. Veuillez réessayer.");
            }
            
            throw new \RuntimeException($errorMsg);
        } catch (\Exception $e) {
            $errorMsg = 'Erreur lors du traitement de la réponse: ' . $e->getMessage();
            $this->logError($errorMsg, ['exception' => get_class($e), 'code' => $e->getCode()]);
            
            if ($promptEntity) {
                $this->updateGenerationMessage($promptEntity, "Erreur lors du traitement de la réponse. Veuillez réessayer.");
            }
            
            throw new \RuntimeException($errorMsg);
        }

        // Extraction du message de génération et du JSON
        $text = trim($text);
        
        // Recherche du premier accolade ouvrante qui indique le début du JSON
        $jsonStartPos = strpos($text, '{');
        
        if ($jsonStartPos === false) {
            throw new \RuntimeException('Aucun JSON trouvé dans la réponse de Gemini');
        }
        
        // Extraire le message de génération (tout ce qui précède le JSON)
        $generationMessage = trim(substr($text, 0, $jsonStartPos));
        
        // Nettoyer le message de génération
        $generationMessage = str_replace(['```json', '```'], '', $generationMessage);
        $generationMessage = trim($generationMessage);
        
        // Mettre à jour le message de génération dans la base de données
        if ($promptEntity) {
            $this->updateGenerationMessage($promptEntity, $generationMessage);
        }
        
        // Extraire le JSON (tout ce qui suit, à partir de l'accolade)
        $jsonText = substr($text, $jsonStartPos);
        
        // Nettoyage du JSON
        $jsonText = str_replace(['```json', '```'], '', $jsonText);
        
        // Suppression des caractères de contrôle tout en préservant les espaces
        $jsonText = preg_replace('/[\x00-\x09\x0B-\x0C\x0E-\x1F\x7F]/', '', $jsonText);
        
        // Normalisation des sauts de ligne et espaces
        $jsonText = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", " "], $jsonText);
        $jsonText = preg_replace('/\n\s+/', "\n", $jsonText);
        
        // Forcer la structure en objet si nécessaire
        $jsonText = trim($jsonText);
        if (str_starts_with($jsonText, '[')) {
            // Amélioration de la conversion tableau vers objet
            $decoded = json_decode($jsonText, true);
            if (is_array($decoded)) {
                $converted = [];
                foreach ($decoded as $index => $item) {
                    if (is_array($item)) {
                        if (isset($item['filename'], $item['content'])) {
                            // Format {"filename": "...", "content": "..."} 
                            $converted[$item['filename']] = $item['content'];
                        } elseif (count($item) === 1) {
                            // Format [{"file.html.twig": "content"}]
                            $key = array_key_first($item);
                            if (!is_numeric($key)) {
                                $converted[$key] = $item[$key];
                            } else {
                                $converted[sprintf('template_%d.html.twig', $index + 1)] = $item[$key];
                            }
                        } else {
                            // Fallback avec un nom de fichier générique
                            $converted[sprintf('template_%d.html.twig', $index + 1)] = json_encode($item);
                        }
                    } else {
                        // Contenu direct sans structure
                        $converted[sprintf('template_%d.html.twig', $index + 1)] = $item;
                    }
                }
                $jsonText = json_encode($converted);
            }
        } elseif (!str_starts_with($jsonText, '{')) {
            $jsonText = '{' . $jsonText . '}';
        }
        
        // Vérification et conversion du format JSON
        $preCheck = json_decode($jsonText, true);
        if ($preCheck !== null) {
            if (!is_array($preCheck)) {
                throw new \RuntimeException('Le JSON doit être un objet.');
            }
            
            // Vérifier que toutes les clés sont des chaînes
            foreach ($preCheck as $key => $value) {
                if (is_numeric($key)) {
                    throw new \RuntimeException('Le JSON doit être un objet avec des clés nommées, pas un tableau indexé.');
                }
            }
            $decoded = $preCheck;
        } else {
            // Validation de la structure JSON de base
            if (!str_starts_with(trim($jsonText), '{')) {
                $firstBrace = strpos($jsonText, '{');
                if ($firstBrace !== false) {
                    $jsonText = substr($jsonText, $firstBrace);
                } else {
                    $jsonText = '{' . $jsonText;
                }
            }
            
            if (!str_ends_with(trim($jsonText), '}')) {
                $lastBrace = strrpos($jsonText, '}');
                if ($lastBrace !== false) {
                    $jsonText = substr($jsonText, 0, $lastBrace + 1);
                } else {
                    $jsonText .= '}';
                }
            }
            
            // Pré-traitement du JSON
            $jsonText = preg_replace('/,\s*([\]}])/', '$1', $jsonText); // Supprime les virgules trailing
            $jsonText = preg_replace('/([{,])\s*([^"\s{\[]+)\s*:/', '$1"$2":', $jsonText); // Ajoute les guillemets aux clés non quotées
            
            // Nettoyage des caractères spéciaux et tentative de décodage
            $jsonText = str_replace('\\', '\\\\', $jsonText); // Double les backslashes
            $jsonText = str_replace('\"', '"', $jsonText); // Supprime les guillemets déjà échappés
            $jsonText = str_replace('"', '\"', $jsonText); // Échappe les guillemets
            
            $decoded = json_decode($jsonText, true);
            
            if ($decoded === null || !is_array($decoded)) {
                // Si le décodage échoue, essayons de normaliser avec json_encode
                $jsonText = str_replace('\\\\', '\\', $jsonText); // Normalise les backslashes
                $jsonText = str_replace('\"', '"', $jsonText); // Supprime les guillemets échappés
                $jsonText = json_encode(json_decode($jsonText), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                
                $decoded = json_decode($jsonText, true);
                if ($decoded === null || !is_array($decoded)) {
                    throw new \RuntimeException('Le JSON doit être un objet avec des clés nommées, pas un tableau indexé.');
                }
                
                // Vérifie si c'est un tableau indexé et tente de le convertir
                if (array_keys($decoded) === range(0, count($decoded) - 1)) {
                    $converted = [];
                    foreach ($decoded as $index => $content) {
                        if (is_array($content) && isset($content['filename'], $content['content'])) {
                            $converted[$content['filename']] = $content['content'];
                        } else {
                            $converted['file_' . ($index + 1)] = $content;
                        }
                    }
                    if (!empty($converted)) {
                        $decoded = $converted;
                    } else {
                        throw new \RuntimeException('Impossible de convertir le tableau en objet avec des clés nommées.');
                    }
                }
            }
        }

        // Transmettre les données du front-end au StubProcessor
        $this->stubProcessor->setFrontendData($decoded);

        // Mettre à jour le message de génération pour indiquer la génération du backend
        if ($promptEntity) {
            $this->updateGenerationMessage($promptEntity, 'Génération du backend en cours...');
        }

        // Générer le backend basé sur le frontend
        $backendPrompt = "Tu es un expert en développement Symfony qui va générer un contrôleur parfaitement adapté au frontend suivant :\n";
        $backendPrompt .= json_encode($decoded, JSON_PRETTY_PRINT) . "\n\n";
        $backendPrompt .= "Analyse en détail les templates Twig fournis et génère un contrôleur Symfony qui :\n";
        $backendPrompt .= "1. Implémente toutes les routes nécessaires en analysant :\n";
        $backendPrompt .= "   - Les liens (balises <a>)\n";
        $backendPrompt .= "   - Les formulaires et leurs attributs action/method\n";
        $backendPrompt .= "   - Les redirections potentielles\n";
        $backendPrompt .= "2. Gère les formulaires en :\n";
        $backendPrompt .= "   - Créant les types de formulaire appropriés\n";
        $backendPrompt .= "   - Validant les données soumises\n";
        $backendPrompt .= "   - Traitant les fichiers uploadés si présents\n";
        $backendPrompt .= "3. Implémente la logique métier en :\n";
        $backendPrompt .= "   - Gérant les entités et leurs relations\n";
        $backendPrompt .= "   - Utilisant les repositories pour les requêtes\n";
        $backendPrompt .= "   - Respectant les bonnes pratiques Symfony\n";
        $backendPrompt .= "4. Gère la sécurité en :\n";
        $backendPrompt .= "   - Ajoutant les annotations/attributs de sécurité\n";
        $backendPrompt .= "   - Vérifiant les permissions\n";
        $backendPrompt .= "   - Protégeant contre les attaques CSRF\n";
        $backendPrompt .= "5. Améliore l'expérience utilisateur avec :\n";
        $backendPrompt .= "   - Des messages flash appropriés\n";
        $backendPrompt .= "   - Une gestion d'erreurs robuste\n";
        $backendPrompt .= "   - Des redirections pertinentes\n";
        $backendPrompt .= "6. Optimise les performances en :\n";
        $backendPrompt .= "   - Utilisant le cache quand c'est pertinent\n";
        $backendPrompt .= "   - Optimisant les requêtes database\n";
        $backendPrompt .= "   - Paginant les résultats si nécessaire\n";
        
        // Ajouter les préférences de l'utilisateur au prompt backend si disponibles
        if ($user) {
            $backendPrompt .= "\nPRÉFÉRENCES UTILISATEUR:\n";
            if ($user->getPreferredStyle()) {
                $backendPrompt .= "- Style visuel préféré: " . $user->getPreferredStyle() . "\n";
            }
            if ($user->getBusinessType()) {
                $backendPrompt .= "- Type d'activité: " . $user->getBusinessType() . "\n";
            }
            if ($user->getColorScheme()) {
                $backendPrompt .= "- Schéma de couleurs: " . $user->getColorScheme() . "\n";
            }
            if ($user->getAdditionalPreferences()) {
                $backendPrompt .= "- Préférences additionnelles: " . $user->getAdditionalPreferences() . "\n";
            }
            if ($user->getContactEmail()) {
                $backendPrompt .= "- Email de contact pour les formulaires: " . $user->getContactEmail() . "\n";
            }
            $backendPrompt .= "\nVeuillez prendre en compte ces préférences dans la génération du contrôleur, notamment pour les formulaires de contact qui doivent utiliser l'email de contact spécifié.\n";
        }
        
        $backendPrompt .= "\nRetourne UNIQUEMENT le code PHP du contrôleur, sans commentaires ni explications. Le code doit être complet et fonctionnel, incluant toutes les importations nécessaires.";

        $backendResponse = $this->client->request('POST', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent', [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'query' => [
                'key' => $this->apiKey,
            ],
            'json' => [
                'contents' => [[
                    'parts' => [[
                        'text' => $backendPrompt
                    ]]
                ]],
                'generationConfig' => [
                    'temperature' => 0.7,
                    'maxOutputTokens' => 8192,
                    'topP' => 0.8,
                    'topK' => 40
                ]
            ],
        ]);

        // Mettre à jour le message de génération pour indiquer la génération des entités
        if ($promptEntity) {
            $this->updateGenerationMessage($promptEntity, 'Génération des entités et repositories...');
        }

        $backendData = $backendResponse->toArray(false);
        $backendCode = $backendData['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if ($backendCode) {
            // Nettoyer et formater le code du contrôleur
            $backendCode = trim($backendCode);
            $backendCode = str_replace(['```php', '```'], '', $backendCode);
            
            // Remplacer les annotations @Route par des attributs #[Route]
            $backendCode = preg_replace('/@Route\((.+?)\)/', '#[Route($1)]', $backendCode);
            
            // Sauvegarder le code du contrôleur
            $this->stubProcessor->setControllerCode($backendCode);
        }

        // Initialiser le résultat avec les fichiers existants si disponibles
        $result = $existingFiles ? $existingFiles : [];
        
        // Ajouter ou mettre à jour les fichiers frontend générés par l'IA
        foreach ($decoded as $filename => $content) {
            if ($filename === 'app.css' || $filename === 'app.js' || str_ends_with($filename, '.html.twig')) {
                $result[$filename] = $content;
            }
        }

        // S'assurer que les fichiers requis existent
        if (!isset($result['index.html.twig'])) $result['index.html.twig'] = '';
        if (!isset($result['app.css'])) $result['app.css'] = '';
        if (!isset($result['app.js'])) $result['app.js'] = '';

        // Mettre à jour le message de génération pour indiquer la génération des fichiers backend
        if ($promptEntity) {
            $this->updateGenerationMessage($promptEntity, 'Génération des fichiers backend...');
        }

        // Générer les fichiers backend
        $backendFiles = $this->generateBackendFiles($existingFiles);
        
        // Mettre à jour le message de génération pour indiquer la finalisation
        if ($promptEntity) {
            $this->updateGenerationMessage($promptEntity, 'Finalisation de la génération...');
        }
        
        // Ajouter ou mettre à jour les fichiers backend générés
        // Si nous avons des fichiers existants, nous ne remplaçons que les fichiers backend qui ont été modifiés
        // pour s'adapter aux changements du frontend
        foreach ($backendFiles as $path => $content) {
            // Si le fichier backend existe déjà et que nous avons des fichiers existants,
            // nous ne le remplaçons que s'il a été modifié pour s'adapter aux changements du frontend
            if ($existingFiles && isset($existingFiles[$path])) {
                // Vérifier si le fichier a été modifié pour s'adapter aux changements du frontend
                // Pour l'instant, nous remplaçons toujours les fichiers backend pour s'assurer qu'ils sont compatibles
                // avec les modifications du frontend
                $result[$path] = $content;
            } else {
                // Si le fichier n'existe pas encore, nous l'ajoutons
                $result[$path] = $content;
            }
        }

        // Ajouter ou mettre à jour les fichiers frontend
        foreach ($decoded as $filename => $content) {
            if ($filename === 'app.css' || $filename === 'app.js' || str_ends_with($filename, '.html.twig')) {
                $result[$filename] = $content;
                // Stocker les données frontend dans la propriété de classe
                $this->frontendData[$filename] = $content;
            }
        }

        // S'assurer que les fichiers requis existent
        if (!isset($result['index.html.twig'])) $result['index.html.twig'] = '';
        if (!isset($result['app.css'])) $result['app.css'] = '';
        if (!isset($result['app.js'])) $result['app.js'] = '';
        
        // Stocker le message de génération
        $result['__generation_message__'] = $generationMessage;

        // Créer une base de données par défaut pour le site généré si un prompt entity existe
        if ($promptEntity) {
            $this->updateGenerationMessage($promptEntity, 'Création de la base de données...');
        }

        return $result;
    }

    /**
     * Génère les fichiers backend (contrôleur, entités, repositories, formtypes)
     * @param array|null $existingFiles Les fichiers existants à préserver
     */
    private function generateBackendFiles(?array $existingFiles = null): array
    {
        $backendFiles = [];

        // Récupérer le code du contrôleur généré
        $controllerCode = $this->stubProcessor->getControllerCode();
        if (!$controllerCode) {
            return $backendFiles;
        }

        // Générer MainController.php (obligatoire)
        $mainControllerReplacements = $this->stubProcessor->generateEntityReplacements('Main');
        $mainControllerCode = $this->stubProcessor->processStub('controller', $mainControllerReplacements);

        // Nettoyer le code du MainController en supprimant toutes les balises PHP sauf la première
        $mainControllerCode = $this->cleanPhpTags($mainControllerCode);
        $backendFiles['src/Controller/MainController.php'] = $mainControllerCode;
        
        // Générer les entités et repositories
        $entitiesAndRepositories = $this->generateEntitiesAndRepositories($controllerCode, $existingFiles);
        $backendFiles = array_merge($backendFiles, $entitiesAndRepositories);
        
        // Générer les formtypes
        $formTypes = $this->generateFormTypes($controllerCode, $existingFiles);
        $backendFiles = array_merge($backendFiles, $formTypes);

        return $backendFiles;
    }

    /**
     * Nettoie le code en supprimant toutes les balises PHP sauf la première
     */
    private function cleanPhpTags(string $code): string
    {
        // S'assurer que le code commence par <?php
        if (!str_starts_with($code, '<?php')) {
            // Supprimer toutes les balises PHP
            $code = preg_replace('/<\?php\s*/', '', $code);
            // Ajouter la balise PHP au début
            $code = "<?php\n" . $code;
        } else {
            // Supprimer toutes les balises PHP sauf la première
            $code = preg_replace('/(?<!^)<\?php\s*/', '', $code);
        }
        
        // Forcer le nom de la classe à MainController
        $code = preg_replace('/class\s+([A-Za-z0-9_]+)\s+extends\s+AbstractController/', 'class MainController extends AbstractController', $code);
        
        return $code;
    }
    
    /**
     * Génère les entités et repositories en fonction du contrôleur et du frontend
     * @param array|null $existingFiles Les fichiers existants à préserver
     */
    private function generateEntitiesAndRepositories(string $controllerCode, ?array $existingFiles = null): array
    {
        $files = [];
        $promptEntity = null;
        
        // Récupérer l'entité Prompt à partir du MessageHandler
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 10);
        foreach ($backtrace as $trace) {
            if (isset($trace['args']) && !empty($trace['args'])) {
                foreach ($trace['args'] as $arg) {
                    if ($arg instanceof \App\Entity\Prompt) {
                        $promptEntity = $arg;
                        break 2;
                    }
                }
            }
        }
        
        // Mettre à jour le message de génération pour indiquer la génération des entités
        if ($promptEntity) {
            $this->updateGenerationMessage($promptEntity, 'Génération des entités et repositories...');
        }
        
        // Préparer le prompt pour Gemini
        $entityPrompt = "Tu es un expert en développement Symfony qui va générer des entités et repositories parfaitement adaptés au contrôleur et au frontend suivants :\n";
        $entityPrompt .= "\nCONTRÔLEUR:\n" . $controllerCode . "\n\n";
        $entityPrompt .= "FRONTEND:\n" . json_encode($this->frontendData, JSON_PRETTY_PRINT) . "\n\n";
        $entityPrompt .= "Analyse en détail le contrôleur et les templates Twig fournis et génère les entités et repositories Symfony qui :\n";
        $entityPrompt .= "1. Correspondent exactement aux noms d'entités utilisés dans le contrôleur\n";
        $entityPrompt .= "2. Incluent tous les champs nécessaires en analysant :\n";
        $entityPrompt .= "   - Les méthodes du contrôleur\n";
        $entityPrompt .= "   - Les formulaires dans les templates Twig\n";
        $entityPrompt .= "   - Les relations entre entités\n";
        $entityPrompt .= "3. Définissent les bonnes annotations Doctrine pour :\n";
        $entityPrompt .= "   - Les types de champs\n";
        $entityPrompt .= "   - Les relations (OneToMany, ManyToOne, etc.)\n";
        $entityPrompt .= "   - Les contraintes de validation\n";
        $entityPrompt .= "4. Implémentent les méthodes repository utiles pour :\n";
        $entityPrompt .= "   - Les requêtes personnalisées\n";
        $entityPrompt .= "   - Les filtres et tris\n";
        $entityPrompt .= "   - La pagination si nécessaire\n";
        
        // Ajouter les informations sur les stubs
        $entityPrompt .= "\nUtilise les structures suivantes pour les entités et repositories :\n";
        $entityPrompt .= "ENTITY STUB:\n" . file_get_contents(__DIR__ . '/Stubs/entity.stub') . "\n\n";
        $entityPrompt .= "REPOSITORY STUB:\n" . file_get_contents(__DIR__ . '/Stubs/repository.stub') . "\n\n";
        
        $entityPrompt .= "Retourne UNIQUEMENT un objet JSON valide avec les clés étant les chemins des fichiers (ex: 'src/Entity/Product.php') et les valeurs étant le contenu complet des fichiers. Assure-toi que chaque entité a son repository correspondant.\n\n";
        $entityPrompt .= "IMPORTANT: Ta réponse doit être un JSON valide et bien formaté. Utilise le format suivant:\n";
        $entityPrompt .= "```json\n{\n  \"src/Entity/NomEntite.php\": \"<?php\\n\\nnamespace App\\\\Entity;\\n...\"\n}\n```\n\n";
        $entityPrompt .= "Assure-toi que toutes les barres obliques inverses (\\) et les guillemets (\") dans les valeurs sont correctement échappés pour que le JSON soit valide.";
        $entityPrompt .= "\n\nIMPORTANT: Si des entités ou repositories existants sont fournis, tu dois ABSOLUMENT les conserver et les modifier uniquement si nécessaire pour s'adapter aux changements du frontend ou du contrôleur. Ne supprime JAMAIS un fichier existant.";
        
        // Appeler Gemini pour générer les entités et repositories
        $response = $this->client->request('POST', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent', [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'query' => [
                'key' => $this->apiKey,
            ],
            'json' => [
                'contents' => [[
                    'parts' => [[
                        'text' => $entityPrompt
                    ]]
                ]],
                'generationConfig' => [
                    'temperature' => 0.7,
                    'maxOutputTokens' => 8192,
                    'topP' => 0.8,
                    'topK' => 40
                ],
                'safetySettings' => [
                    ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_NONE'],
                    ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_NONE'],
                    ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_NONE'],
                    ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE']
                ]
            ],
        ]);
        
        $data = $response->toArray(false);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        
        if (!$text) {
            throw new \RuntimeException('Réponse vide de Gemini pour les entités et repositories');
        }
        
        // Enregistrer la réponse brute pour le débogage
        error_log("Réponse brute de Gemini pour les entités et repositories:\n" . $text);
        
        // Extraire le JSON de la réponse en recherchant un bloc de code JSON markdown
        if (preg_match("/```(?:json)?\s*\n(.+?)\n```/s", $text, $matches)) {
            $jsonText = $matches[1];
            error_log("JSON extrait via regex de bloc de code: " . substr($jsonText, 0, 500) . "...");
        } else {
            // Méthode de secours: chercher le premier { et le dernier }
            $jsonStartPos = strpos($text, '{');
            $jsonEndPos = strrpos($text, '}');
            
            if ($jsonStartPos === false || $jsonEndPos === false) {
                error_log("Aucun JSON trouvé dans la réponse de Gemini");
                throw new \RuntimeException('Aucun JSON trouvé dans la réponse de Gemini pour les entités et repositories');
            }
            
            $jsonText = substr($text, $jsonStartPos, $jsonEndPos - $jsonStartPos + 1);
            error_log("JSON extrait via méthode de secours: " . substr($jsonText, 0, 500) . "...");
        }
        
        // Essayer de nettoyer le JSON avant décodage
        $jsonText = preg_replace('/\\\\n/', '\n', $jsonText); // Remplacer les \n littéraux par de vrais sauts de ligne
        $jsonText = preg_replace('/\\\\/', '\\', $jsonText); // Gérer les backslashes échappés
        
        error_log("JSON après nettoyage: " . substr($jsonText, 0, 500) . "...");
        
        $decoded = json_decode($jsonText, true);
        
        if ($decoded === null) {
            error_log("Erreur de décodage JSON: " . json_last_error_msg());
            throw new \RuntimeException('Impossible de décoder le JSON pour les entités et repositories: ' . json_last_error_msg());
        }
        
        // Stocker les fichiers générés
        foreach ($decoded as $filePath => $fileContent) {
            // Nettoyer le contenu si nécessaire
            if (str_starts_with($filePath, 'src/Entity/')) {
                // Stocker le code de l'entité dans le StubProcessor
                $entityName = basename($filePath, '.php');
                $this->stubProcessor->setEntityCode($fileContent, $entityName);
            } elseif (str_starts_with($filePath, 'src/Repository/')) {
                // Stocker le code du repository dans le StubProcessor
                $repositoryName = basename($filePath, '.php');
                $this->stubProcessor->setRepositoryCode($fileContent, $repositoryName);
            }
            
            // Ajouter le fichier à la liste des fichiers générés
            // Si nous avons des fichiers existants, nous ne remplaçons que les fichiers qui ont été modifiés
            if ($existingFiles && isset($existingFiles[$filePath])) {
                // Pour l'instant, nous remplaçons toujours les entités et repositories pour s'assurer qu'ils sont compatibles
                // avec les modifications du frontend et du contrôleur
                $files[$filePath] = $fileContent;
            } else {
                // Si le fichier n'existe pas encore, nous l'ajoutons
                $files[$filePath] = $fileContent;
            }
        }
        
        return $files;
    }
    
    /**
     * Génère les formtypes en fonction du contrôleur, du frontend et des entités
     * @param array|null $existingFiles Les fichiers existants à préserver
     */
    private function generateFormTypes(string $controllerCode, ?array $existingFiles = null): array
    {
        $files = [];
        $promptEntity = null;
        
        // Récupérer l'entité Prompt à partir du MessageHandler
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 10);
        foreach ($backtrace as $trace) {
            if (isset($trace['args']) && !empty($trace['args'])) {
                foreach ($trace['args'] as $arg) {
                    if ($arg instanceof \App\Entity\Prompt) {
                        $promptEntity = $arg;
                        break 2;
                    }
                }
            }
        }
        
        // Mettre à jour le message de génération pour indiquer la génération des formtypes
        if ($promptEntity) {
            $this->updateGenerationMessage($promptEntity, 'Génération des formtypes...');
        }
        
        // Récupérer les entités générées
        $entityCode = [];
        foreach ($this->stubProcessor->getEntityFields() as $entityName => $fields) {
            $entityCode[$entityName] = $this->stubProcessor->getEntityCode($entityName) ?? '';
        }
        
        // Préparer le prompt pour Gemini
        $formPrompt = "Tu es un expert en développement Symfony qui va générer des formtypes parfaitement adaptés au contrôleur, au frontend et aux entités suivants :\n";
        $formPrompt .= "\nCONTRÔLEUR:\n" . $controllerCode . "\n\n";
        $formPrompt .= "FRONTEND:\n" . json_encode($this->frontendData, JSON_PRETTY_PRINT) . "\n\n";
        $formPrompt .= "ENTITÉS:\n" . json_encode($entityCode, JSON_PRETTY_PRINT) . "\n\n";
        $formPrompt .= "Analyse en détail le contrôleur, les templates Twig et les entités fournis et génère les formtypes Symfony qui :\n";
        $formPrompt .= "1. Correspondent exactement aux noms de formulaires utilisés dans le contrôleur\n";
        $formPrompt .= "2. Incluent tous les champs nécessaires en analysant :\n";
        $formPrompt .= "   - Les méthodes du contrôleur\n";
        $formPrompt .= "   - Les formulaires dans les templates Twig\n";
        $formPrompt .= "   - Les propriétés des entités\n";
        $formPrompt .= "3. Utilisent les bons types de champs Symfony pour :\n";
        $formPrompt .= "   - Les types de données (TextType, NumberType, etc.)\n";
        $formPrompt .= "   - Les relations (EntityType, CollectionType, etc.)\n";
        $formPrompt .= "   - Les contraintes de validation\n";
        $formPrompt .= "4. Configurent correctement les options des champs pour :\n";
        $formPrompt .= "   - Les labels\n";
        $formPrompt .= "   - Les placeholders\n";
        $formPrompt .= "   - Les classes CSS\n";
        $formPrompt .= "   - Les contraintes de validation\n";
        
        // Ajouter les informations sur le stub
        $formPrompt .= "\nUtilise la structure suivante pour les formtypes :\n";
        $formPrompt .= "FORM STUB:\n" . file_get_contents(__DIR__ . '/Stubs/form.stub') . "\n\n";
        
        $formPrompt .= "IMPORTANT: Si des FormTypes existants sont fournis, tu dois ABSOLUMENT les conserver et les modifier uniquement si nécessaire pour s'adapter aux changements des entités ou du contrôleur. Ne supprime JAMAIS un fichier existant.\n\n";
        $formPrompt .= "Retourne UNIQUEMENT un objet JSON valide avec les clés étant les chemins des fichiers (ex: 'src/Form/ProductType.php') et les valeurs étant le contenu complet des fichiers.\n\n";
        $formPrompt .= "IMPORTANT: Ta réponse doit être un JSON valide et bien formaté. Utilise le format suivant:\n";
        $formPrompt .= "```json\n{\n  \"src/Form/NomFormType.php\": \"<?php\\n\\nnamespace App\\\\Form;\\n...\"\n}\n```\n\n";
        $formPrompt .= "Assure-toi que toutes les barres obliques inverses (\\) et les guillemets (\") dans les valeurs sont correctement échappés pour que le JSON soit valide.";
        
        // Appeler Gemini pour générer les formtypes
        $response = $this->client->request('POST', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent', [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'query' => [
                'key' => $this->apiKey,
            ],
            'json' => [
                'contents' => [[
                    'parts' => [[
                        'text' => $formPrompt
                    ]]
                ]],
                'generationConfig' => [
                    'temperature' => 0.7,
                    'maxOutputTokens' => 8192,
                    'topP' => 0.8,
                    'topK' => 40
                ],
                'safetySettings' => [
                    ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_NONE'],
                    ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_NONE'],
                    ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_NONE'],
                    ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE']
                ]
            ],
        ]);
        
        $data = $response->toArray(false);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        
        if (!$text) {
            throw new \RuntimeException('Réponse vide de Gemini pour les formtypes');
        }
        
        // Enregistrer la réponse brute pour le débogage
        error_log("Réponse brute de Gemini pour les formtypes:\n" . $text);
        
        // Extraire le JSON de la réponse en recherchant un bloc de code JSON markdown
        if (preg_match("/```(?:json)?\s*\n(.+?)\n```/s", $text, $matches)) {
            $jsonText = $matches[1];
            error_log("JSON extrait via regex de bloc de code: " . substr($jsonText, 0, 500) . "...");
        } else {
            // Méthode de secours: chercher le premier { et le dernier }
            $jsonStartPos = strpos($text, '{');
            $jsonEndPos = strrpos($text, '}');
            
            if ($jsonStartPos === false || $jsonEndPos === false) {
                error_log("Aucun JSON trouvé dans la réponse de Gemini");
                throw new \RuntimeException('Aucun JSON trouvé dans la réponse de Gemini pour les formtypes');
            }
            
            $jsonText = substr($text, $jsonStartPos, $jsonEndPos - $jsonStartPos + 1);
            error_log("JSON extrait via méthode de secours: " . substr($jsonText, 0, 500) . "...");
        }
        
        // Essayer de nettoyer le JSON avant décodage
        $jsonText = preg_replace('/\\\\n/', '\n', $jsonText); // Remplacer les \n littéraux par de vrais sauts de ligne
        $jsonText = preg_replace('/\\\\/', '\\', $jsonText); // Gérer les backslashes échappés
        
        error_log("JSON après nettoyage: " . substr($jsonText, 0, 500) . "...");
        
        $decoded = json_decode($jsonText, true);
        
        if ($decoded === null) {
            error_log("Erreur de décodage JSON: " . json_last_error_msg());
            throw new \RuntimeException('Impossible de décoder le JSON pour les formtypes: ' . json_last_error_msg());
        }
        
        // Stocker les fichiers générés
        foreach ($decoded as $filePath => $fileContent) {
            // Nettoyer le contenu si nécessaire
            if (str_starts_with($filePath, 'src/Form/')) {
                // Stocker le code du formtype dans le StubProcessor
                $formTypeName = basename($filePath, '.php');
                $this->stubProcessor->setFormTypeCode($fileContent, $formTypeName);
            }
            
            // Ajouter le fichier à la liste des fichiers générés
            // Si le fichier existe déjà et que nous avons des fichiers existants,
            // nous vérifions s'il a été modifié
            if ($existingFiles && isset($existingFiles[$filePath])) {
                // Pour l'instant, nous remplaçons toujours les formtypes pour s'assurer qu'ils sont compatibles
                // avec les modifications des entités et du contrôleur
                $files[$filePath] = $fileContent;
            } else {
                // Si le fichier n'existe pas encore, nous l'ajoutons
                $files[$filePath] = $fileContent;
            }
        }
        
        return $files;
    }
}
