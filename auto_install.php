<?php

// === CONFIG === //
$baseDir = __DIR__;
$maxProjects = 100;
$phpPath = '/usr/local/bin/php';
$composerPath = '/opt/cpanel/composer/bin/composer';
$logFile = $baseDir . "/install_log.txt";

// Fonction pour supprimer uniquement les dossiers dans un dossier (sans supprimer les fichiers)
function deleteOnlyDirs(string $dir, string $logFile) {
    if (!is_dir($dir)) return;
    $it = new DirectoryIterator($dir);
    foreach ($it as $fileinfo) {
        if ($fileinfo->isDot()) continue;
        $path = $fileinfo->getRealPath();
        if ($fileinfo->isDir()) {
            // Supprime récursivement le dossier
            $itSub = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
            $filesSub = new RecursiveIteratorIterator($itSub, RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($filesSub as $subFile) {
                if ($subFile->isDir()) rmdir($subFile->getRealPath());
                else unlink($subFile->getRealPath());
            }
            rmdir($path);
            file_put_contents($logFile, "Supprimé dossier $path\n", FILE_APPEND);
        } else {
            // fichier : on laisse (Kernel.php par ex)
        }
    }
}

// Fonction pour supprimer un dossier récursivement
function deleteDir(string $dir) {
    if (!is_dir($dir)) return;
    $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir()) rmdir($file->getRealPath());
        else unlink($file->getRealPath());
    }
    rmdir($dir);
}

// === Fonction principale === //
for ($i = 1; $i <= $maxProjects; $i++) {
    $projectDir = $baseDir . "/$i";
    $webyviaPath = "$projectDir/webyviaproject";

    if (is_dir($projectDir) && !is_dir($webyviaPath)) {
        file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Création projet dans $projectDir\n", FILE_APPEND);

        // 1. Créer le projet Symfony (squelette)
        $cmd = "$phpPath $composerPath create-project symfony/skeleton webyviaproject";
        exec("cd " . escapeshellarg($projectDir) . " && $cmd 2>&1", $output1, $code1);
        file_put_contents($logFile, implode("\n", $output1) . "\n", FILE_APPEND);

        // 2. Installer le pack webapp
        $cmdWebapp = "$phpPath $composerPath require symfony/webapp-pack";
        exec("cd " . escapeshellarg($webyviaPath) . " && $cmdWebapp 2>&1", $output2, $code2);
        file_put_contents($logFile, implode("\n", $output2) . "\n", FILE_APPEND);

        // 3. Déplacer les fichiers .twig, app.js, app.css
        $files = scandir($projectDir);
        foreach ($files as $file) {
            $fullPath = "$projectDir/$file";
            if (is_file($fullPath)) {
                $ext = pathinfo($file, PATHINFO_EXTENSION);
                if ($ext === 'twig') {
                    rename($fullPath, "$webyviaPath/templates/$file");
                    file_put_contents($logFile, "Déplacé $file → templates/\n", FILE_APPEND);
                } elseif ($file === 'app.js') {
                    rename($fullPath, "$webyviaPath/assets/app.js");
                    file_put_contents($logFile, "Déplacé app.js → assets/\n", FILE_APPEND);
                } elseif ($file === 'app.css') {
                    @mkdir("$webyviaPath/assets/styles", 0775, true);
                    rename($fullPath, "$webyviaPath/assets/styles/app.css");
                    file_put_contents($logFile, "Déplacé app.css → assets/styles/\n", FILE_APPEND);
                }
            }
        }

        // 4. Déplacer dossiers Controller, Entity, Repository, Form depuis $projectDir/src vers $webyviaPath/src
        $dirsToMove = ['Controller', 'Entity', 'Repository', 'Form'];
        foreach ($dirsToMove as $dirName) {
            $srcPath = "$projectDir/src/$dirName";
            $destPath = "$webyviaPath/src/$dirName";

            if (is_dir($srcPath)) {
                if (is_dir($destPath)) {
                    // Supprime entièrement le dossier si Controller, Entity ou Repository
                    if (in_array($dirName, ['Controller', 'Entity', 'Repository'])) {
                        deleteDir($destPath);
                        file_put_contents($logFile, "Supprimé dossier entier $destPath\n", FILE_APPEND);
                    } else {
                        // Pour Form, supprime juste les sous-dossiers (comme avant)
                        deleteOnlyDirs($destPath, $logFile);
                    }
                }
                rename($srcPath, $destPath);
                file_put_contents($logFile, "Déplacé $srcPath → $destPath\n", FILE_APPEND);
            }
        }

        // Supprimer le dossier src vide du dossier source après déplacement
        $srcDir = "$projectDir/src";
        if (is_dir($srcDir)) {
            $filesLeft = scandir($srcDir);
            if (count($filesLeft) <= 2) { // . et ..
                rmdir($srcDir);
                file_put_contents($logFile, "Supprimé dossier vide $srcDir\n", FILE_APPEND);
            } else {
                file_put_contents($logFile, "Dossier $srcDir non vide, pas supprimé\n", FILE_APPEND);
            }
        }

        // 5. Modifier config/packages/mailer.yaml
        $mailerYamlPath = "$webyviaPath/config/packages/mailer.yaml";
        $mailerContent = <<<YAML
framework:
    mailer:
        dsn: '%env(MAILER_DSN)%'
        message_bus: false

YAML;
        file_put_contents($mailerYamlPath, $mailerContent, LOCK_EX);
        file_put_contents($logFile, "Mis à jour mailer.yaml\n" . "Contenu mailer.yaml généré :\n" . file_get_contents($mailerYamlPath), FILE_APPEND);

        // 6. Modifier templates/base.html.twig pour assets (app.css et app.js)
        $baseTwigPath = "$webyviaPath/templates/base.html.twig";
        if (file_exists($baseTwigPath)) {
            $baseTwigContent = file_get_contents($baseTwigPath);

            $insertion = <<<TWIG

    <link rel="stylesheet" href="{{ asset('styles/app.css') }}">
    <script src="{{ asset('app.js') }}" defer></script>
TWIG;

            $baseTwigContent = preg_replace('/<\/head>/', $insertion . "\n</head>", $baseTwigContent, 1);

            file_put_contents($baseTwigPath, $baseTwigContent);
            file_put_contents($logFile, "Modifié base.html.twig pour assets\n", FILE_APPEND);
        } else {
            file_put_contents($logFile, "base.html.twig non trouvé, assets non ajoutés\n", FILE_APPEND);
        }

        // 7. Modifier .env
        $envPath = "$webyviaPath/.env";
        if (file_exists($envPath)) {
            $envContent = file_get_contents($envPath);

            if (preg_match('/^APP_ENV=.*/m', $envContent)) {
                $envContent = preg_replace('/^APP_ENV=.*/m', 'APP_ENV=prod', $envContent);
            } else {
                $envContent .= "\nAPP_ENV=prod\n";
            }

            if (preg_match('/^MAILER_DSN=.*/m', $envContent)) {
                $envContent = preg_replace(
                    '/^MAILER_DSN=.*/m',
                    'MAILER_DSN=smtp://support@imaginaryconception.com:Edogame00_@moustache.o2switch.net:465',
                    $envContent
                );
            } else {
                $envContent .= "\nMAILER_DSN=smtp://support@imaginaryconception.com:Edogame00_@moustache.o2switch.net:465\n";
            }

            $databaseUrl = sprintf(
                'DATABASE_URL="mysql://root:@127.0.0.1:3306/webyviaprojectdatabase%d?serverVersion=5.7&charset=utf8mb4"',
                $i
            );
            if (preg_match('/^DATABASE_URL=.*/m', $envContent)) {
                $envContent = preg_replace('/^DATABASE_URL=.*/m', $databaseUrl, $envContent);
            } else {
                $envContent .= "\n$databaseUrl\n";
            }

            file_put_contents($envPath, $envContent);
            file_put_contents($logFile, "Mis à jour .env\n", FILE_APPEND);
        } else {
            file_put_contents($logFile, ".env non trouvé\n", FILE_APPEND);
        }

        file_put_contents($logFile, "Projet Symfony prêt dans : $webyviaPath\n\n", FILE_APPEND);
        break;
    }
}
