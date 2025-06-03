<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class AIService
{
    private HttpClientInterface $client;
    private string $apiKey;

    private StubProcessor $stubProcessor;

    public function __construct(
        HttpClientInterface $client,
        StubProcessor $stubProcessor
    ) {
        $this->client = $client;
        $this->stubProcessor = $stubProcessor;
        $this->apiKey = $_ENV['GEMINI_API_KEY'] ?? throw new \RuntimeException('GEMINI_API_KEY non définie');
    }

    public function generateWebsiteFromPrompt(string $prompt, ?array $existingFiles = null): array
    {
        $frontendData = [];

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

        $promptText = "Tu es un générateur de code HTML.TWIG/CSS/JS. Retourne UNIQUEMENT un objet JSON. Le JSON peut contenir les clés suivantes :\n";
        $promptText .= "- index.html.twig : Doit commencer par {% extends 'base.html.twig' %} et définir son contenu dans {% block body %}\n";
        $promptText .= "- app.css : Le CSS doit être écrit de manière brute sans utiliser Asset, il doit être ultra moderne et complet\n";
        $promptText .= "- app.js : Le code JavaScript doit être ultra complet pour la page web\n";
        $promptText .= "- Autres fichiers .html.twig : Tu peux créer d'autres fichiers .html.twig si demandé. Ils doivent tous:\n";
        $promptText .= "  * Commencer par {% extends 'base.html.twig' %}\n";
        $promptText .= "  * Définir leur contenu dans {% block body %}\n";
        $promptText .= "  * Hériter automatiquement de app.js et app.css\n";
        $promptText .= "Ajoute beaucoup d'animations pour les boutons, textes etc\n";
        $promptText .= "Le site doit être de type glassmorphism\n";
        $promptText .= "Pour les images, assures-toi de récupérer des images sur internet avec des liens uniquement, pas de chemin relatif\n";
        $promptText .= "Pour les formulaires, assures-toi de les faire uniquement sous forme de Twig, pas HTML.\n";
        $promptText .= "Ajoute souvent des images pour rendre le site attractif\n";
        $promptText.= "Préfère les sites one page avec des liens de navbar qui mène à des ancres de la page\n";
        $promptText.= "Je veux que tu mettes des vrais images, pas de #\n";
        $promptText.= "Il faut que ce soit ULTRA MODERNE VISUELLEMENT avec du contenu ULTRA COMPLET, une mise en page ULTRA PROFESSIONNELLE !\n";
        $promptText.= "Il faut que ce soit ultra animé, avec un effet de glissement quand on va dans une encre.\n";
        $promptText .= "IMPORTANT : NE JAMAIS générer le fichier base.html.twig\n\n";

        if ($existingFiles) {
            $promptText .= "Voici le code existant que tu dois conserver et modifier uniquement selon la demande :\n";
            $promptText .= json_encode($existingFiles, JSON_PRETTY_PRINT) . "\n\nModifie uniquement ce qui est demandé dans le prompt suivant, en gardant le reste du code intact : ";
        } else {
            $promptText .= "Chaque valeur doit être une string contenant le code. Ne rajoute pas de texte autour. Pas de commentaires, pas d'explications, pas de blabla, QUE DU JSON. Voici la demande : ";
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

        $data = $response->toArray(false);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (!$text) {
            throw new \RuntimeException('Réponse vide de Gemini');
        }

        // Nettoyage initial du contenu
        $text = trim($text);
        $text = str_replace(['```json', '```'], '', $text);
        
        // Suppression des caractères de contrôle tout en préservant les espaces
        $text = preg_replace('/[\x00-\x09\x0B-\x0C\x0E-\x1F\x7F]/', '', $text);
        
        // Normalisation des sauts de ligne et espaces
        $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", " "], $text);
        $text = preg_replace('/\n\s+/', "\n", $text);
        
        // Forcer la structure en objet si nécessaire
        $text = trim($text);
        if (str_starts_with($text, '[')) {
            // Amélioration de la conversion tableau vers objet
            $decoded = json_decode($text, true);
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
                $text = json_encode($converted);
            }
        } elseif (!str_starts_with($text, '{')) {
            $text = '{' . $text . '}';
        }
        
        // Vérification et conversion du format JSON
        $preCheck = json_decode($text, true);
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
            if (!str_starts_with(trim($text), '{')) {
                $firstBrace = strpos($text, '{');
                if ($firstBrace !== false) {
                    $text = substr($text, $firstBrace);
                } else {
                    $text = '{' . $text;
                }
            }
            
            if (!str_ends_with(trim($text), '}')) {
                $lastBrace = strrpos($text, '}');
                if ($lastBrace !== false) {
                    $text = substr($text, 0, $lastBrace + 1);
                } else {
                    $text .= '}';
                }
            }
            
            // Pré-traitement du JSON
            $text = preg_replace('/,\s*([\]}])/', '$1', $text); // Supprime les virgules trailing
            $text = preg_replace('/([{,])\s*([^"\s{\[]+)\s*:/', '$1"$2":', $text); // Ajoute les guillemets aux clés non quotées
            
            // Nettoyage des caractères spéciaux et tentative de décodage
            $text = str_replace('\\', '\\\\', $text); // Double les backslashes
            $text = str_replace('\"', '"', $text); // Supprime les guillemets déjà échappés
            $text = str_replace('"', '\"', $text); // Échappe les guillemets
            
            $decoded = json_decode($text, true);
            
            if ($decoded === null || !is_array($decoded)) {
                // Si le décodage échoue, essayons de normaliser avec json_encode
                $text = str_replace('\\\\', '\\', $text); // Normalise les backslashes
                $text = str_replace('\"', '"', $text); // Supprime les guillemets échappés
                $text = json_encode(json_decode($text), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                
                $decoded = json_decode($text, true);
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

        $backendData = $backendResponse->toArray(false);
        $backendCode = $backendData['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if ($backendCode) {
            // Nettoyer et formater le code du contrôleur
            $backendCode = trim($backendCode);
            $backendCode = str_replace(['```php', '```'], '', $backendCode);
            
            // Sauvegarder le contrôleur généré
            $this->stubProcessor->setControllerCode($backendCode);
        }

        // Générer les entités
        $entityPrompt = "Tu es un expert en développement Symfony qui va générer les entités nécessaires pour le frontend suivant :\n";
        $entityPrompt .= json_encode($decoded, JSON_PRETTY_PRINT) . "\n\n";
        $entityPrompt .= "Analyse les templates Twig et génère les entités Doctrine avec :\n";
        $entityPrompt .= "- Les propriétés appropriées basées sur les formulaires\n";
        $entityPrompt .= "- Les types de données corrects\n";
        $entityPrompt .= "- Les relations entre entités\n";
        $entityPrompt .= "- Les validations nécessaires\n";
        $entityPrompt .= "Retourne un tableau JSON avec les noms des entités comme clés et leur code PHP comme valeurs.";

        $entityResponse = $this->client->request('POST', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent', [
            'headers' => ['Content-Type' => 'application/json'],
            'query' => ['key' => $this->apiKey],
            'json' => [
                'contents' => [['parts' => [['text' => $entityPrompt]]]],
                'generationConfig' => ['temperature' => 0.7, 'maxOutputTokens' => 8192]
            ],
        ]);

        $entityData = $entityResponse->toArray(false);
        $entityCode = $entityData['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if ($entityCode) {
            $entityCode = trim($entityCode);
            $entityCode = str_replace(['```json', '```'], '', $entityCode);
            $entities = json_decode($entityCode, true) ?? [];
            $this->stubProcessor->setEntityCode($entities);
        }

        // Générer les repositories
        $repoPrompt = "Tu es un expert en développement Symfony qui va générer les repositories pour les entités suivantes :\n";
        $repoPrompt .= json_encode($entities, JSON_PRETTY_PRINT) . "\n\n";
        $repoPrompt .= "Génère les repositories avec :\n";
        $repoPrompt .= "- Les méthodes de recherche courantes\n";
        $repoPrompt .= "- Les requêtes DQL optimisées\n";
        $repoPrompt .= "- La pagination si nécessaire\n";
        $repoPrompt .= "Retourne un tableau JSON avec les noms des repositories comme clés et leur code PHP comme valeurs.";

        $repoResponse = $this->client->request('POST', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent', [
            'headers' => ['Content-Type' => 'application/json'],
            'query' => ['key' => $this->apiKey],
            'json' => [
                'contents' => [['parts' => [['text' => $repoPrompt]]]],
                'generationConfig' => ['temperature' => 0.7, 'maxOutputTokens' => 8192]
            ],
        ]);

        $repoData = $repoResponse->toArray(false);
        $repoCode = $repoData['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if ($repoCode) {
            $repoCode = trim($repoCode);
            $repoCode = str_replace(['```json', '```'], '', $repoCode);
            $repositories = json_decode($repoCode, true) ?? [];
            $this->stubProcessor->setRepositoryCode($repositories);
        }

        // Générer les form types
        $formPrompt = "Tu es un expert en développement Symfony qui va générer les form types pour les entités suivantes :\n";
        $formPrompt .= json_encode($entities, JSON_PRETTY_PRINT) . "\n\n";
        $formPrompt .= "Génère les form types avec :\n";
        $formPrompt .= "- Les champs appropriés\n";
        $formPrompt .= "- Les contraintes de validation\n";
        $formPrompt .= "- Les options de champs personnalisées\n";
        $formPrompt .= "Retourne un tableau JSON avec les noms des form types comme clés et leur code PHP comme valeurs.";

        $formResponse = $this->client->request('POST', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent', [
            'headers' => ['Content-Type' => 'application/json'],
            'query' => ['key' => $this->apiKey],
            'json' => [
                'contents' => [['parts' => [['text' => $formPrompt]]]],
                'generationConfig' => ['temperature' => 0.7, 'maxOutputTokens' => 8192]
            ],
        ]);

        $formData = $formResponse->toArray(false);
        $formCode = $formData['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if ($formCode) {
            $formCode = trim($formCode);
            $formCode = str_replace(['```json', '```'], '', $formCode);
            $formTypes = json_decode($formCode, true) ?? [];
            $this->stubProcessor->setFormTypeCode($formTypes);
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

        // Générer les fichiers backend
        $backendFiles = $this->generateBackendFiles();
        
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

        return $result;
    }

    private function generateBackendFiles(): array
    {
        $backendFiles = [];
        
        // Générer MainController.php (obligatoire)
        $mainControllerReplacements = $this->stubProcessor->generateEntityReplacements('Main');
        $backendFiles['src/Controller/MainController.php'] = $this->stubProcessor->processStub('controller', $mainControllerReplacements);
        
        // Générer les fichiers pour chaque entité détectée
        foreach ($this->stubProcessor->getEntityFields() as $entityName => $fields) {
            $replacements = $this->stubProcessor->generateEntityReplacements($entityName);
            
            // Générer les fichiers pour cette entité
            $backendFiles["src/Entity/{$entityName}.php"] = $this->stubProcessor->processStub('entity', $replacements);
            $backendFiles["src/Repository/{$entityName}Repository.php"] = $this->stubProcessor->processStub('repository', $replacements);
            $backendFiles["src/Controller/{$entityName}Controller.php"] = $this->stubProcessor->processStub('controller', $replacements);
            $backendFiles["src/Form/{$entityName}Type.php"] = $this->stubProcessor->processStub('form', $replacements);
        }
        
        return $backendFiles;
    }
}
