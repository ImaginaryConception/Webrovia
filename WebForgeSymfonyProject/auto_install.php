<?php
$baseDir = __DIR__;
$projects = scandir($baseDir);

foreach ($projects as $project) {
    if ($project === '.' || $project === '..' || $project === 'auto_install.php') continue;

    $projectPath = $baseDir . '/' . $project;

    // Conditions pour détecter un projet Symfony valide et pas encore installé
    if (
        is_dir($projectPath) &&
        file_exists($projectPath . '/composer.json') &&
        !is_dir($projectPath . '/vendor')
    ) {
        echo "Installation de $projectPath\n";

        // Lancement de composer install
        $cmd = "cd $projectPath && /usr/local/bin/composer install --no-interaction";
        shell_exec($cmd);
    }
}
?>
