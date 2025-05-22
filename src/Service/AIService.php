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
        $promptText = "Tu es un générateur de code HTML.TWIG/CSS/JS. Retourne UNIQUEMENT un objet JSON avec les clés index.html.twig, styles.css et script.js. ";
        
        if ($existingFiles) {
            $promptText .= "Voici le code existant que tu dois conserver et modifier uniquement selon la demande :\n";
            $promptText .= json_encode($existingFiles, JSON_PRETTY_PRINT) . "\n\nModifie uniquement ce qui est demandé dans le prompt suivant, en gardant le reste du code intact : ";
        } else {
            $promptText .= "Chaque valeur doit être une string contenant le code. Ne rajoute pas de texte autour. Pas de commentaires, pas d'explications, pas de blabla, QUE DU JSON. Voici la demande : ";
        }
        
        $promptText .= $prompt;

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

        // S'assurer que les clés existent
        return [
            'index.html.twig' => $json['index.html.twig'] ?? '',
            'styles.css' => $json['styles.css'] ?? '',
            'script.js' => $json['script.js'] ?? '',
        ];
    }
}
