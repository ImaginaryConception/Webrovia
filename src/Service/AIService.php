<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class AIService
{
    private HttpClientInterface $client;
    private string $apiKey;
    private BackendGeneratorService $backendGenerator;

    public function __construct(
        HttpClientInterface $client,
        BackendGeneratorService $backendGenerator
    ) {
        $this->client = $client;
        $this->backendGenerator = $backendGenerator;
        $this->apiKey = $_ENV['GEMINI_API_KEY'] ?? throw new \RuntimeException('GEMINI_API_KEY non définie');
    }

    public function generateWebsiteFromPrompt(string $prompt, ?array $existingFiles = null): array
    {
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
        $promptText .= "Pour les images, assures-toi de récupérer des images sur internet avec des liens uniquement, pas de chemin relatif\n";
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
                    'temperature' => 1.5,
                ],
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
            // Convertir le tableau en objet avant le décodage
            $text = preg_replace('/^\[\s*{/', '{"file_1":{', $text);
            $text = preg_replace('/}\s*,\s*{/', '},"file_2":{', $text);
            $text = preg_replace('/}\s*\]$/', '}', $text);
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
        
        // Filtrer les clés pour ne garder que les fichiers frontend et backend
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

        // Génération du backend après la génération des fichiers Twig
        $twigFiles = array_filter($result, function ($filename) {
            return str_ends_with($filename, '.html.twig');
        }, ARRAY_FILTER_USE_KEY);

        // Récupérer les fichiers backend générés et les fusionner avec les fichiers frontend
        $backendFiles = $this->backendGenerator->generateBackend($twigFiles);
        return array_merge($result, $backendFiles);
    }
}
