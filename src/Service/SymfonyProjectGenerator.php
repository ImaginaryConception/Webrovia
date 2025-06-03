<?php

namespace App\Service;

class SymfonyProjectGenerator {
    private string $projectDir;
    private string $webforgePath;
    private string $logFile;
    private string $phpPath;
    private string $composerPath;

    public function __construct(string $projectDir) {
        $this->projectDir = $projectDir;
        $this->webforgePath = "$projectDir/webforgeproject";
        $this->logFile = $projectDir . "/install_log.txt";
        $this->phpPath = 'php';
        $this->composerPath = 'composer';
    }

    private function log(string $message): void {
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($this->logFile, "[$timestamp] $message\n", FILE_APPEND);
    }

    private function executeCommand(string $command, string $workDir): array {
        $fullCommand = "cd " . escapeshellarg($workDir) . " && $command 2>&1";
        exec($fullCommand, $output, $code);
        $this->log(implode("\n", $output));
        return ['output' => $output, 'code' => $code];
    }

    private function captureFileStructure(string $dir, string $basePath = ''): array {
        $structure = [];
        $items = scandir($dir);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            $relativePath = $basePath ? $basePath . '/' . $item : $item;
            
            if (is_file($path)) {
                $content = file_get_contents($path);
                $structure[$relativePath] = [
                    'type' => 'file',
                    'content' => base64_encode($content)
                ];
            } elseif (is_dir($path)) {
                $structure[$relativePath] = [
                    'type' => 'directory',
                    'children' => $this->captureFileStructure($path, $relativePath)
                ];
            }
        }
        
        return $structure;
    }

    public function generate(): array {
        if (!is_dir($this->projectDir)) {
            mkdir($this->projectDir, 0775, true);
        }

        if (is_dir($this->webforgePath)) {
            return ['success' => false, 'message' => 'Le projet existe déjà', 'structure' => null];
        }

        $this->log("Création projet dans {$this->projectDir}");

        // 1. Créer le projet Symfony (squelette)
        $result = $this->executeCommand(
            "{$this->phpPath} {$this->composerPath} create-project symfony/skeleton webforgeproject",
            $this->projectDir
        );
        if ($result['code'] !== 0) {
            return ['success' => false, 'message' => 'Échec création projet Symfony', 'structure' => null];
        }

        // 2. Installer le pack webapp
        $result = $this->executeCommand(
            "{$this->phpPath} {$this->composerPath} require symfony/webapp-pack",
            $this->webforgePath
        );
        if ($result['code'] !== 0) {
            return ['success' => false, 'message' => 'Échec installation webapp-pack', 'structure' => null];
        }

        // 3. Préparer les dossiers
        foreach (['templates', 'src', 'assets'] as $folder) {
            $path = "{$this->webforgePath}/$folder";
            if (is_dir($path)) {
                $this->executeCommand("rm -rf " . escapeshellarg($path), dirname($path));
                $this->log("Supprimé : $path");
            }
        }

        // 4. Recréer les dossiers essentiels
        mkdir("{$this->webforgePath}/templates", 0775, true);
        mkdir("{$this->webforgePath}/public", 0775, true);

        // 5. Déplacer les fichiers générés
        $files = scandir($this->projectDir);
        foreach ($files as $file) {
            $fullPath = "{$this->projectDir}/$file";
            if (is_file($fullPath)) {
                $ext = pathinfo($file, PATHINFO_EXTENSION);
                if ($ext === 'twig') {
                    rename($fullPath, "{$this->webforgePath}/templates/$file");
                    $this->log("Déplacé $file → templates/");
                } elseif (in_array($ext, ['css', 'js'])) {
                    rename($fullPath, "{$this->webforgePath}/public/$file");
                    $this->log("Déplacé $file → public/");
                }
            }
        }

        // 6. Capturer la structure finale
        $projectStructure = $this->captureFileStructure($this->webforgePath);
        $this->log("Projet Symfony prêt dans : {$this->webforgePath}");

        return [
            'success' => true,
            'message' => 'Projet généré avec succès',
            'structure' => $projectStructure
        ];
    }
}
