<?php

// === CONFIG === //
$baseDir = __DIR__;
$maxProjects = 100;
$phpPath = '/usr/local/bin/php'; // à adapter si besoin
$composerPath = '/opt/cpanel/composer/bin/composer'; // à adapter si besoin
$logFile = $baseDir . "/install_log.txt";

// === Fonction principale === //
for ($i = 1; $i <= $maxProjects; $i++) {
    $projectDir = $baseDir . "/$i";
    $webforgePath = "$projectDir/webforgeproject";

    if (is_dir($projectDir) && !is_dir($webforgePath)) {
        file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Création projet dans $projectDir\n", FILE_APPEND);

        // 1. Créer le projet Symfony (squelette)
        $cmd = "$phpPath $composerPath create-project symfony/skeleton webforgeproject";
        exec("cd " . escapeshellarg($projectDir) . " && $cmd 2>&1", $output1, $code1);
        file_put_contents($logFile, implode("\n", $output1) . "\n", FILE_APPEND);

        // 2. Installer le pack webapp
        $cmdWebapp = "$phpPath $composerPath require symfony/webapp-pack";
        exec("cd " . escapeshellarg($webforgePath) . " && $cmdWebapp 2>&1", $output2, $code2);
        file_put_contents($logFile, implode("\n", $output2) . "\n", FILE_APPEND);

        // 3. Supprimer dossiers inutiles
        $foldersToDelete = ['templates', 'src', 'assets'];
        foreach ($foldersToDelete as $folder) {
            $path = "$webforgePath/$folder";
            if (is_dir($path)) {
                exec("rm -rf " . escapeshellarg($path));
                file_put_contents($logFile, "Supprimé : $path\n", FILE_APPEND);
            }
        }

        // 4. Créer les dossiers cibles
        @mkdir("$webforgePath/templates", 0775, true);
        @mkdir("$webforgePath/public", 0775, true);

        // 5. Déplacer les fichiers twig/css/js
        $files = scandir($projectDir);
        foreach ($files as $file) {
            $fullPath = "$projectDir/$file";
            if (is_file($fullPath)) {
                $ext = pathinfo($file, PATHINFO_EXTENSION);
                if ($ext === 'twig') {
                    rename($fullPath, "$webforgePath/templates/$file");
                    file_put_contents($logFile, "Déplacé $file → templates/\n", FILE_APPEND);
                } elseif (in_array($ext, ['css', 'js'])) {
                    rename($fullPath, "$webforgePath/public/$file");
                    file_put_contents($logFile, "Déplacé $file → public/\n", FILE_APPEND);
                }
            }
        }

        file_put_contents($logFile, "Projet Symfony prêt dans : $webforgePath\n\n", FILE_APPEND);
        break;
    }
}
