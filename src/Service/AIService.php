<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class AIService
{
    private HttpClientInterface $client;
    private string $apiKey;

    public function __construct(HttpClientInterface $client)
    {
        $this->client = $client;
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

        // Nettoyage du contenu
        $text = trim($text);
        $text = preg_replace('/```(json)?\s*/', '', $text);
        $text = preg_replace('/```$/', '', $text);
        $text = str_replace('\n', '', $text);
        $text = str_replace('\r', '', $text);

        $json = json_decode($text, true);
        if (!is_array($json)) {
            throw new \RuntimeException('Réponse JSON invalide: ' . json_last_error_msg());
        }

        // Filtrer les clés pour ne garder que les fichiers frontend et backend
        $result = [];
        foreach ($json as $filename => $content) {
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
}
