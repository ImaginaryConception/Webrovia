<?php

namespace App\Service;

use App\Entity\Database;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AIService
{
    private HttpClientInterface $client;
    private string $apiKey;
    private StubProcessor $stubProcessor;
    private $entityManager;

    public function __construct(
        HttpClientInterface $client,
        StubProcessor $stubProcessor,
        \Doctrine\ORM\EntityManagerInterface $entityManager
    ) {
        $this->client = $client;
        $this->stubProcessor = $stubProcessor;
        $this->entityManager = $entityManager;
        $this->apiKey = $_ENV['GEMINI_API_KEY'] ?? throw new \RuntimeException('GEMINI_API_KEY non définie');
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
        $promptText.= "Préfère les sites one page avec des liens de navbar qui mène à des ancres de la page\n";
        $promptText.= "Je veux que tu mettes des vrais images, pas de #\n";
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
            $promptText .= "Voici le code existant que tu dois conserver et modifier uniquement selon la demande :\n";
            $promptText .= json_encode($existingFiles, JSON_PRETTY_PRINT) . "\n\nModifie uniquement ce qui est demandé dans le prompt suivant, en gardant le reste du code intact. Explique d'abord ce que tu vas modifier, puis fournis le JSON avec le code modifié : ";
        } else {
            $promptText .= "Chaque valeur dans le JSON doit être une string contenant le code. Ne rajoute pas de texte autour du JSON. Voici la demande : ";
        }

        $promptText .= $prompt . " IMPORTANT : Il faut que ce soit ULTRA MODERNE VISUELLEMENT avec du contenu ULTRA COMPLET, une mise en page ULTRA PROFESSIONNELLE !";

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
        ]);

        // Mettre à jour le message de génération pour indiquer que la réponse a été reçue
        if ($promptEntity) {
            $this->updateGenerationMessage($promptEntity, 'Traitement de la réponse de l\'IA...');
        }

        $data = $response->toArray(false);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (!$text) {
            throw new \RuntimeException('Réponse vide de Gemini');
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
                    if (is_array($item) && isset($item['filename'], $item['content'])) {
                        // Format {"filename": "...", "content": "..."} 
                        $converted[$item['filename']] = $item['content'];
                    } elseif (is_array($item) && count($item) === 1) {
                        // Format [{"file.html.twig": "content"}]
                        $key = array_key_first($item);
                        $converted[$key] = $item[$key];
                    } else {
                        // Fallback avec un nom de fichier générique
                        $converted[sprintf('%s_%d.html.twig', 'template', $index + 1)] = is_array($item) ? json_encode($item) : $item;
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

        // Filtrer les clés pour ne garder que les fichiers frontend
        $result = [];
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
        $backendFiles = $this->generateBackendFiles();
        
        // Mettre à jour le message de génération pour indiquer la finalisation
        if ($promptEntity) {
            $this->updateGenerationMessage($promptEntity, 'Finalisation de la génération...');
        }
        
        // Ajouter tous les fichiers backend générés au résultat
        foreach ($backendFiles as $path => $content) {
            $result[$path] = $content;
        }

        // Ajouter les fichiers frontend
        foreach ($decoded as $filename => $content) {
            if ($filename === 'app.css' || $filename === 'app.js' || str_ends_with($filename, '.html.twig')) {
                $result[$filename] = $content;
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
            $this->createDefaultDatabase($promptEntity);
        }

        return $result;
    }

    private function generateBackendFiles(): array
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

        return $backendFiles;
    }

    /**
     * Crée une base de données par défaut pour un prompt
     */
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
    
    private function createDefaultDatabase(\App\Entity\Prompt $prompt): void
    {
        // Vérifier si une base de données existe déjà pour ce prompt
        $databaseRepository = $this->entityManager->getRepository(\App\Entity\Database::class);
        $existingDatabase = $databaseRepository->findByPrompt($prompt);
        
        if ($existingDatabase) {
            // Une base de données existe déjà, pas besoin d'en créer une nouvelle
            return;
        }
        
        // Créer une nouvelle base de données
        $database = new \App\Entity\Database();
        $database->setName('database_' . $prompt->getId());
        $database->setPrompt($prompt);
        
        // Ajouter quelques tables par défaut
        $database->addTable('users', [
            'id' => 'integer',
            'username' => 'string',
            'email' => 'string',
            'password' => 'string',
            'created_at' => 'datetime'
        ]);
        
        $database->addTable('products', [
            'id' => 'integer',
            'name' => 'string',
            'description' => 'text',
            'price' => 'float',
            'image_url' => 'string',
            'created_at' => 'datetime'
        ]);
        
        $database->addTable('orders', [
            'id' => 'integer',
            'user_id' => 'integer',
            'total' => 'float',
            'status' => 'string',
            'created_at' => 'datetime'
        ]);
        
        // Persister la base de données
        $this->entityManager->persist($database);
        $this->entityManager->flush();
    }
}
