<?php

namespace App\Service;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class WebsiteCloneService
{
    private $client;

    public function __construct()
    {
        $this->client = HttpClient::create(['verify_peer' => false]);
    }

    public function cloneWebsite(array $data): Response
    {
        $url = $data['website_url'];
        $projectName = $data['project_name'];
        $cloneType = $data['clone_type'] ?? 'single';
        $includeAssets = $data['include_assets'] ?? false;

        try {
            $response = $this->client->request('GET', $url);
            $html = $response->getContent();
            $crawler = new Crawler($html, $url);

            if ($includeAssets) {
                // Modifier les URLs des ressources pour les proxy via notre service
                $html = $this->processAssets($crawler, $url);
            }

            if ($cloneType === 'complete') {
                // Récupérer et traiter les sous-pages
                $html = $this->processSubPages($crawler, $url, $html);
            }

            // Générer le fichier ZIP en mémoire
            $zipContent = $this->generateZipContent($projectName, $html);

            // Créer une réponse streamée pour le téléchargement
            $response = new StreamedResponse(function () use ($zipContent) {
                echo $zipContent;
            });

            $disposition = $response->headers->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                $projectName . '.zip'
            );

            $response->headers->set('Content-Type', 'application/zip');
            $response->headers->set('Content-Disposition', $disposition);

            return $response;

        } catch (\Exception $e) {
            throw new \RuntimeException('Erreur lors du clonage du site : ' . $e->getMessage());
        }
    }

    private function processAssets(Crawler $crawler, string $baseUrl): string
    {
        $html = $crawler->html();
        $cssContent = '';

        // Récupérer et traiter les feuilles de style externes
        $crawler->filter('link[rel="stylesheet"]')->each(function ($node) use ($baseUrl, &$cssContent) {
            $href = $node->attr('href');
            if ($href) {
                $cssUrl = $this->resolveUrl($baseUrl, $href);
                try {
                    $response = $this->client->request('GET', $cssUrl);
                    $css = $response->getContent();
                    // Traiter les URLs dans le CSS (images, fonts, etc.)
                    $css = $this->processCssUrls($css, $cssUrl);
                    $cssContent .= "\n/* Source: {$cssUrl} */\n" . $css;
                } catch (\Exception $e) {
                    // Log l'erreur mais continue
                }
            }
        });

        // Récupérer les styles intégrés
        $crawler->filter('style')->each(function ($node) use ($baseUrl, &$cssContent) {
            $css = $node->html();
            $cssContent .= "\n/* Style intégré */\n" . $this->processCssUrls($css, $baseUrl);
        });

        // Supprimer les balises link CSS et style originales
        $html = preg_replace('/<link[^>]*rel=["\']stylesheet["\'][^>]*>/i', '', $html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);

        // Ajouter tous les styles dans une nouvelle balise style dans le head
        if ($cssContent) {
            $html = preg_replace('/<\/head>/i', "<style>\n{$cssContent}\n</style>\n</head>", $html);
        }

        // Traiter les scripts JS
        $html = preg_replace_callback(
            '/<script[^>]*src=["\']([^"\']*)["\']>/i',
            function ($matches) use ($baseUrl) {
                $url = $this->resolveUrl($baseUrl, $matches[1]);
                return str_replace($matches[1], '/proxy-asset?url=' . urlencode($url), $matches[0]);
            },
            $html
        );

        return $html;
    }

    private function processSubPages(Crawler $crawler, string $baseUrl, string $mainHtml): string
    {
        // Collecter les liens internes
        $subPages = [];
        $crawler->filter('a')->each(function ($node) use (&$subPages, $baseUrl) {
            $href = $node->attr('href');
            if ($href && !str_starts_with($href, '#')) {
                $url = $this->resolveUrl($baseUrl, $href);
                if (parse_url($url, PHP_URL_HOST) === parse_url($baseUrl, PHP_URL_HOST)) {
                    $subPages[$url] = true;
                }
            }
        });

        return $mainHtml;
    }

    private function generateZipContent(string $projectName, string $html): string
    {
        $zip = new \ZipArchive();
        $tempFile = tempnam(sys_get_temp_dir(), 'website_clone_');
        $zip->open($tempFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        // Ajouter le fichier HTML principal
        $zip->addFromString('index.html', $html);

        // Ajouter un fichier README
        $readme = "Site cloné : {$projectName}\n";
        $readme .= "Date de génération : " . date('Y-m-d H:i:s') . "\n";
        $zip->addFromString('README.txt', $readme);

        $zip->close();

        $content = file_get_contents($tempFile);
        unlink($tempFile);

        return $content;
    }

    private function resolveUrl(string $baseUrl, string $path): string
    {
        if (str_starts_with($path, 'http')) {
            return $path;
        }

        $baseUrlParts = parse_url($baseUrl);
        $scheme = $baseUrlParts['scheme'] ?? 'https';
        $host = $baseUrlParts['host'];

        if (str_starts_with($path, '//')) {
            return $scheme . ':' . $path;
        }

        if (str_starts_with($path, '/')) {
            return $scheme . '://' . $host . $path;
        }

        // Gérer les chemins relatifs (../)
        $basePath = isset($baseUrlParts['path']) ? dirname($baseUrlParts['path']) : '';
        while (str_starts_with($path, '../')) {
            $path = substr($path, 3);
            $basePath = dirname($basePath);
        }

        $basePath = $basePath === '/' ? '' : $basePath;
        return $scheme . '://' . $host . $basePath . '/' . $path;
    }

    private function processCssUrls(string $css, string $baseUrl): string
    {
        // Traiter les URLs dans les règles CSS (url(), @import)
        return preg_replace_callback(
            '/url\([\'"]*([^\)\'"]+)[\'"]*\)|@import[\s]+[\'"]*([^\)\'"]+)[\'"]*[\s]*;/i',
            function ($matches) use ($baseUrl) {
                $url = !empty($matches[2]) ? $matches[2] : $matches[1];
                // Ignorer les URLs data:
                if (str_starts_with($url, 'data:')) {
                    return $matches[0];
                }
                $absoluteUrl = $this->resolveUrl($baseUrl, $url);
                return str_replace($url, '/proxy-asset?url=' . urlencode($absoluteUrl), $matches[0]);
            },
            $css
        );
        
        // Modifier les chemins des ressources pour pointer vers les fichiers locaux
        $html = preg_replace(
            '/(href=["\'])https?:\/\/[^"\']*\/([^"\']*\.css)(["\'])/i',
            '$1css/$2$3',
            $html
        );

        $html = preg_replace(
            '/(src=["\'])https?:\/\/[^"\']*\/([^"\']*\.js)(["\'])/i',
            '$1js/$2$3',
            $html
        );

        return $html;
    }
}