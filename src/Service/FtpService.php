<?php

namespace App\Service;

use App\Entity\Prompt;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class FtpService
{
    private $curlHandle = null;
    private string $projectRoot;
    private string $ftpBasePath = '/WebroviaProjects';
    private string $ftpHost;
    private string $ftpUser;
    private string $ftpPassword;

    public function __construct(ParameterBagInterface $params)
    {
        $this->projectRoot = $params->get('kernel.project_dir');
        $this->ftpHost = $params->get('app.ftp_host');
        $this->ftpUser = $params->get('app.ftp_user');
        $this->ftpPassword = $params->get('app.ftp_password');
    }

    private function connect(): void
    {
        if ($this->curlHandle) {
            return;
        }

        $this->curlHandle = curl_init();
        if (!$this->curlHandle) {
            throw new \RuntimeException('Impossible d\'initialiser cURL');
        }

        curl_setopt($this->curlHandle, CURLOPT_URL, "ftp://{$this->ftpHost}");
        curl_setopt($this->curlHandle, CURLOPT_FTPLISTONLY, true);
        curl_setopt($this->curlHandle, CURLOPT_USERPWD, $this->ftpUser . ':' . $this->ftpPassword);
        curl_setopt($this->curlHandle, CURLOPT_FTP_CREATE_MISSING_DIRS, true);
        curl_setopt($this->curlHandle, CURLOPT_RETURNTRANSFER, true);

        // Créer le répertoire de base directement
        $this->createDirectoryIfNotExists($this->ftpBasePath);
    }

    private function createDirectoryIfNotExists(string $directory): void
    {
        // Créer directement le répertoire sans vérification préalable
        curl_setopt($this->curlHandle, CURLOPT_POSTQUOTE, array("MKD {$directory}"));
        curl_exec($this->curlHandle);
        
        // Réinitialiser les options
        curl_setopt($this->curlHandle, CURLOPT_POSTQUOTE, array());
    }

    public function deploySite(Prompt $prompt): array
    {
        try {
            // Augmenter le temps d'exécution maximum
            set_time_limit(300);

            // Récupérer les informations nécessaires
            $promptId = $prompt->getId();
            $generatedFiles = $prompt->getGeneratedFiles();
            $domain = $prompt->getDomainName();

            // Vérifications de base
            if (empty($promptId)) {
                throw new \RuntimeException('L\'identifiant du prompt ne peut pas être vide.');
            }
            if (empty($generatedFiles)) {
                throw new \RuntimeException('Aucun fichier généré n\'a été fourni.');
            }
            // if (empty($domain)) {
            //     throw new \RuntimeException('Le nom de domaine ne peut pas être vide.');
            // }

            // Établir la connexion FTP
            $this->connect();

            // Construire et créer le chemin de destination
            $remotePath = $this->ftpBasePath . '/' . $promptId;
            $this->createDirectoryIfNotExists($remotePath);

            // Déployer les fichiers
            foreach ($generatedFiles as $filePath => $content) {
                $fullPath = $remotePath . '/' . ltrim($filePath, '/');
                $directory = dirname($fullPath);

                // Créer la structure de répertoires
                $this->createNestedDirectories($directory);

                // Créer un fichier temporaire local avec un nom spécifique
                $tempFilePath = tempnam(sys_get_temp_dir(), 'ftp_upload_');
                if ($tempFilePath === false) {
                    throw new \RuntimeException("Impossible de créer un fichier temporaire pour: $filePath");
                }

                // Écrire le contenu dans le fichier temporaire
                if (file_put_contents($tempFilePath, $content) === false) {
                    unlink($tempFilePath);
                    throw new \RuntimeException("Impossible d'écrire dans le fichier temporaire pour: $filePath");
                }

                // Ouvrir le fichier en lecture
                $fileHandle = fopen($tempFilePath, 'r');
                if ($fileHandle === false) {
                    unlink($tempFilePath);
                    throw new \RuntimeException("Impossible d'ouvrir le fichier temporaire pour: $filePath");
                }

                try {
                    // Configurer et exécuter le transfert FTP
                    $url = "ftp://{$this->ftpHost}/{$fullPath}";
                    curl_setopt($this->curlHandle, CURLOPT_URL, $url);
                    curl_setopt($this->curlHandle, CURLOPT_UPLOAD, true);
                    curl_setopt($this->curlHandle, CURLOPT_INFILE, $fileHandle);
                    curl_setopt($this->curlHandle, CURLOPT_INFILESIZE, filesize($tempFilePath));
                    curl_setopt($this->curlHandle, CURLOPT_FTP_CREATE_MISSING_DIRS, true);

                    if (curl_exec($this->curlHandle) === false) {
                        throw new \RuntimeException("Échec du transfert du fichier: $fullPath - " . curl_error($this->curlHandle));
                    }
                    curl_setopt($this->curlHandle, CURLOPT_INFILE, null);

                    // Vérifier que le fichier a bien été transféré
                    curl_setopt($this->curlHandle, CURLOPT_NOBODY, true);
                    curl_setopt($this->curlHandle, CURLOPT_URL, $url);
                    curl_setopt($this->curlHandle, CURLOPT_UPLOAD, false);

                    if (curl_exec($this->curlHandle) === false) {
                        throw new \RuntimeException("Impossible de vérifier l'existence du fichier après transfert: $fullPath");
                    }

                    curl_setopt($this->curlHandle, CURLOPT_NOBODY, false);
                } finally {
                    // Toujours fermer le fichier et supprimer le fichier temporaire
                    fclose($fileHandle);
                    unlink($tempFilePath);
                }
            }

            return [
                'success' => true,
                'url' => 'https://' . rtrim($domain, '/') . '/WebroviaProjects/' . $promptId . '/'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        } finally {
            if ($this->curlHandle) {
                curl_close($this->curlHandle);
                $this->curlHandle = null;
            }
        }
    }

    private function createNestedDirectories(string $path): void
    {
        $parts = explode('/', trim($path, '/'));
        $currentPath = '';

        foreach ($parts as $part) {
            $currentPath .= '/' . $part;
            $this->createDirectoryIfNotExists($currentPath);
        }
    }
}