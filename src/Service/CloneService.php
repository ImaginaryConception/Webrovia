<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\DomCrawler\Crawler;

class CloneService
{
    private HttpClientInterface $client;

    public function __construct(HttpClientInterface $client)
    {
        $this->client = $client;
    }

    public function makeRequest(string $url): array
    {
        try {
            // 1. Télécharger le HTML
            $response = $this->client->request('GET', $url);
            $html = $response->getContent();

            // 2. Parse HTML avec DomCrawler
            $crawler = new Crawler($html, $url);
            $files = [
                'index.html' => $html
            ];

            // 3. Récupère les liens CSS
            $crawler->filter('link[rel="stylesheet"]')->each(function ($node) use (&$files, $url) {
                $href = $node->attr('href');
                if ($href) {
                    $absoluteUrl = $this->resolveUrl($url, $href);
                    $content = $this->fetchFileContent($absoluteUrl);
                    $name = 'styles/' . basename(parse_url($href, PHP_URL_PATH));
                    $files[$name] = $content;
                }
            });

            // 4. Récupère les scripts JS
            $crawler->filter('script[src]')->each(function ($node) use (&$files, $url) {
                $src = $node->attr('src');
                if ($src) {
                    $absoluteUrl = $this->resolveUrl($url, $src);
                    $content = $this->fetchFileContent($absoluteUrl);
                    $name = 'scripts/' . basename(parse_url($src, PHP_URL_PATH));
                    $files[$name] = $content;
                }
            });

            // 5. Retourne le tableau
            return $files;

        } catch (\Exception $e) {
            error_log('[CloneService] Erreur : ' . $e->getMessage());
            throw $e;
        }
    }

    private function resolveUrl(string $base, string $relative): string
    {
        // Si l'URL est absolue, la retourner directement
        if (filter_var($relative, FILTER_VALIDATE_URL)) {
            return $relative;
        }

        // Parser l'URL de base
        $baseParts = parse_url($base);
        
        // Si l'URL relative commence par '//', c'est un lien protocol-relative
        if (str_starts_with($relative, '//')) {
            return $baseParts['scheme'] . ':' . $relative;
        }

        // Si l'URL relative commence par '/', c'est relatif à la racine
        if (str_starts_with($relative, '/')) {
            return $baseParts['scheme'] . '://' . $baseParts['host'] . $relative;
        }

        // Construire le chemin de base
        $path = isset($baseParts['path']) ? dirname($baseParts['path']) : '/';
        if ($path !== '/') {
            $path .= '/';
        }

        // Combiner avec l'URL relative
        $absolutePath = $path . $relative;
        
        // Nettoyer le chemin (gérer '../' et './')
        $parts = array_filter(explode('/', $absolutePath), 'strlen');
        $absolutes = [];
        
        foreach ($parts as $part) {
            if ($part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($absolutes);
            } else {
                $absolutes[] = $part;
            }
        }

        $absolutePath = '/' . implode('/', $absolutes);
        
        // Construire l'URL finale
        return $baseParts['scheme'] . '://' . $baseParts['host'] . $absolutePath;
    }

    private function fetchFileContent(string $url): string
    {
        try {
            $response = $this->client->request('GET', $url);
            return $response->getContent();
        } catch (\Throwable $e) {
            return '';
        }
    }
}
