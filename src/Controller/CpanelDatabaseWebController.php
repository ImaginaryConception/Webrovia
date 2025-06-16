<?php

namespace App\Controller;

use App\Entity\Prompt;
use App\Service\AIService;
use App\Service\CpanelService;
use App\Repository\PromptRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route('/cpanel/database')]
class CpanelDatabaseWebController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private PromptRepository $promptRepository;
    private CpanelService $cpanelService;
    private AIService $aiService;

    public function __construct(
        EntityManagerInterface $entityManager,
        PromptRepository $promptRepository,
        CpanelService $cpanelService,
        AIService $aiService
    ) {
        $this->entityManager = $entityManager;
        $this->promptRepository = $promptRepository;
        $this->cpanelService = $cpanelService;
        $this->aiService = $aiService;
    }

    /**
     * Liste des bases de données cPanel
     */
    #[Route('/', name: 'app_cpanel_database_index', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function index(Request $request): Response
    {
        try {
            // Récupérer les bases de données depuis cPanel
            $allDatabases = $this->cpanelService->listDatabases();
            
            // Liste des bases de données à cacher par défaut
            $defaultHiddenDatabases = [
                'haan7883_imaginaryconception',
                'haan7883_lhannz',
                'haan7883_webyvia'
            ];
            
            // Récupérer les bases de données cachées depuis la session
            $sessionHiddenDatabases = $request->getSession()->get('hidden_databases', []);
            $hiddenDatabases = array_merge($defaultHiddenDatabases, $sessionHiddenDatabases);
            
            // Filter cPanel databases to show only those owned by the current user and not hidden
            $cpanelDatabases = array_filter($allDatabases, function($database) use ($hiddenDatabases) {
                return !in_array($database['database'], $hiddenDatabases);
            });
            
            // Récupérer les bases de données cachées
            $hiddenDbList = array_filter($allDatabases, function($database) use ($hiddenDatabases) {
                return in_array($database['database'], $hiddenDatabases);
            });
            
            /** @var \App\Entity\User $user */
            $user = $this->getUser();
            $userPrompts = $user->getPrompts();
            $userOwnedDbNames = [];
            $promptsWithDbStatus = [];
            foreach ($userPrompts as $prompt) {
                $hasDatabase = isset($prompt->getGeneratedFiles()['db_info.txt']);
                if ($hasDatabase) {
                    $dbInfo = json_decode($prompt->getGeneratedFiles()['db_info.txt'], true);
                    if (isset($dbInfo['database_name'])) {
                        $userOwnedDbNames[] = $dbInfo['database_name'];
                    }
                }
                $promptsWithDbStatus[] = [
                    'prompt' => $prompt,
                    'hasDatabase' => $hasDatabase
                ];
            }

            // Apply user ownership filter to the already hidden-filtered databases
            $cpanelDatabases = array_filter($cpanelDatabases, function($database) use ($userOwnedDbNames) {
                return in_array($database['database'], $userOwnedDbNames);
            });

            return $this->render('cpanel_database/index.html.twig', [
                'cpanelDatabases' => $cpanelDatabases,
                'hiddenDatabases' => $hiddenDbList,
                'hiddenDatabaseNames' => $hiddenDatabases,
                'promptsWithDbStatus' => $promptsWithDbStatus
            ]);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de la récupération des bases de données: ' . $e->getMessage());
            return $this->redirectToRoute('app_cpanel_database_index');
        }
    }

    /**
     * Création d'une nouvelle base de données cPanel
     */
    #[Route('/new/{promptId}', name: 'app_cpanel_database_new', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function new(int $promptId): Response
    {
        $prompt = $this->promptRepository->find($promptId);
        
        if (!$prompt) {
            $this->addFlash('error', 'Site web non trouvé');
            return $this->redirectToRoute('app_my_sites');
        }
        
        // Vérifier que l'utilisateur est propriétaire du prompt
        if ($prompt->getUser() !== $this->getUser()) {
            $this->addFlash('error', 'Vous n\'avez pas accès à ce site web');
            return $this->redirectToRoute('app_my_sites');
        }
        
        // Générer automatiquement les noms
        // Utiliser les premiers mots du contenu pour générer un nom de site
        $content = $prompt->getContent() ?? '';
        $firstWords = substr($content, 0, 30); // Prendre les 30 premiers caractères
        $siteName = preg_replace('/[^a-z0-9]/i', '_', strtolower($firstWords));
        $siteName = substr($siteName, 0, 10); // Limiter la longueur
        error_log("CpanelDatabaseWebController: Nom de site généré dans la méthode new: '$siteName'");
        
        // Utiliser le préfixe haan7883_ pour les bases de données et utilisateurs
        $dbPrefix = 'haan7883_';
        
        $hasDatabase = isset($prompt->getGeneratedFiles()['db_info.txt']);

        return $this->render('cpanel_database/new.html.twig', [
            'prompt' => $prompt,
            'dbPrefix' => $dbPrefix,
            'hasDatabase' => $hasDatabase
        ]);
    }
    
    /**
     * Création automatique d'une base de données cPanel
     */
    #[Route('/create/{promptId}', name: 'app_cpanel_database_create', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function create(Request $request, int $promptId): Response
    {
        $prompt = $this->promptRepository->find($promptId);
        
        if (!$prompt) {
            $this->addFlash('error', 'Site web non trouvé');
            return $this->redirectToRoute('app_my_sites');
        }
        
        // Vérifier que l'utilisateur est propriétaire du prompt
        if ($prompt->getUser() !== $this->getUser()) {
            $this->addFlash('error', 'Vous n\'avez pas accès à ce site web');
            return $this->redirectToRoute('app_my_sites');
        }
        
        // Générer automatiquement les noms
        // Générer un identifiant aléatoire court pour la base de données
        $randomId = substr(md5(uniqid(mt_rand(), true)), 0, 6); // Générer un identifiant aléatoire de 6 caractères
        
        // Générer les noms de base de données et d'utilisateur
        $dbName = 'haan7883_db_' . $promptId . '_' . $randomId; // Nom avec préfixe requis
        $username = 'haan7883_u' . $promptId . '_' . substr($randomId, 0, 3); // Nom avec préfixe requis
        $password = $this->generateRandomPassword();

        error_log("CpanelDatabaseWebController: Tentative de création de la base de données: '$dbName'");
        error_log("CpanelDatabaseWebController: Tentative de création de l'utilisateur: '$username'");
        error_log("CpanelDatabaseWebController: Mot de passe généré: '$password'");
        
        try {
            // Créer la base de données via l'API cPanel
            $this->cpanelService->createDatabase($dbName);
            
            // Créer un utilisateur pour la base de données
            $this->cpanelService->createDatabaseUser($username, $password);
            
            // Attribuer les privilèges à l'utilisateur
            $this->cpanelService->setDatabaseUserPrivileges($dbName, $username);
            
            // Stocker le mot de passe en session pour les requêtes SQL ultérieures
            $request->getSession()->set('db_password_' . $dbName, $password);

            // Ajouter les informations de la base de données dans generatedFiles
            $generatedFiles = $prompt->getGeneratedFiles() ?? [];
            $dbInfoContent = json_encode([
                'database_name' => $dbName,
                'username' => $username,
                'password' => $password
            ]);
            $generatedFiles = array_merge($generatedFiles, [
                'db_info.txt' => $dbInfoContent
            ]);
            $prompt->setGeneratedFiles($generatedFiles);
            $this->entityManager->persist($prompt);
            $this->entityManager->flush();
            
            $this->addFlash('success', 'Base de données créée avec succès');
            
            // Rediriger vers la page de détails avec les informations de connexion
            return $this->render('cpanel_database/success.html.twig', [
                'dbName' => $dbName, // Le préfixe est déjà inclus dans $dbName
                'username' => $username,
                'password' => $password,
                'prompt' => $prompt
            ]);
            
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de la création de la base de données: ' . $e->getMessage());
            return $this->redirectToRoute('app_cpanel_database_new', ['promptId' => $promptId]);
        }
    }

    /**
     * Suppression d'une base de données cPanel
     */
    #[Route('/delete/{dbName}', name: 'app_cpanel_database_delete', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function delete(string $dbName): Response
    {
        try {
            // Extraire l'ID du prompt à partir du nom de la base de données
            preg_match('/haan7883_db_(\d+)_/', $dbName, $matches);
            $promptId = $matches[1] ?? null;
            
            if ($promptId) {
                $prompt = $this->promptRepository->find($promptId);
                if ($prompt) {
                    // Supprimer l'entrée du fichier db_info.txt des generatedFiles
                    $generatedFiles = $prompt->getGeneratedFiles() ?? [];
                    $dbInfoPath = 'db_info.txt';
                    if (isset($generatedFiles[$dbInfoPath])) {
                        unset($generatedFiles[$dbInfoPath]);
                        $prompt->setGeneratedFiles($generatedFiles);
                        $this->entityManager->persist($prompt);
                        $this->entityManager->flush();
                    }
                }
            }
            
            // Supprimer la base de données via l'API cPanel
            $this->cpanelService->deleteDatabase($dbName);
            
            $this->addFlash('success', 'Base de données supprimée avec succès');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de la suppression de la base de données: ' . $e->getMessage());
        }
        
        return $this->redirectToRoute('app_cpanel_database_index');
    }

    /**
     * Exécution d'une requête SQL sur une base de données cPanel
     */
    #[Route('/query/{dbName}', name: 'app_cpanel_database_query', methods: ['GET', 'POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function query(Request $request, string $dbName): Response
    {
        $result = null;
        $error = null;
        $success = null;
        
        if ($request->isMethod('POST')) {
            $query = $request->request->get('query', '');
            $dbPassword = $request->request->get('db_password', null);
            
            error_log("CpanelDatabaseWebController: Nom de la base de données reçu de la requête: '$dbName'");
            
            // Si un mot de passe est fourni, le stocker en session pour les utilisations ultérieures
            if (!empty($dbPassword)) {
                $request->getSession()->set('db_password_' . $dbName, $dbPassword);
            } else {
                // Si aucun mot de passe n'est fourni, essayer de récupérer celui stocké en session
                $dbPassword = $request->getSession()->get('db_password_' . $dbName, null);
            }
            
            if (!empty($query)) {
                try {
                    // Vérifier si la requête est une requête CREATE TABLE
                    $isCreateTableQuery = preg_match('/^\s*CREATE\s+TABLE\s+`?([\w_]+)`?/i', $query, $matches);
                    
                    // Passer le mot de passe de la base de données s'il est fourni
                    $result = $this->cpanelService->executeQuery($dbName, $query, $dbPassword);
                    
                    // Pour les requêtes CREATE TABLE, générer l'entité et le repository
                    if ($isCreateTableQuery) {
                        $tableName = $matches[1];
                        $this->generateEntityFromTable($dbName, $tableName, $dbPassword);
                        $success = 'Table créée avec succès et entité Doctrine générée.';
                    } else {
                        // Vérifier si c'est une autre requête de modification
                        $isModificationQuery = preg_match('/^\s*(INSERT|UPDATE|DELETE|ALTER|DROP|TRUNCATE)/i', $query);
                        if ($isModificationQuery) {
                            if (isset($result['message'])) {
                                $success = $result['message'];
                            } else {
                                $success = 'La requête a été exécutée avec succès. Les modifications ont été appliquées à la base de données.';
                            }
                        }
                    }
                    
                    if ($success) {
                        $this->addFlash('success', $success);
                    }
                } catch (\Exception $e) {
                    $error = $e->getMessage();
                    $this->addFlash('error', $error);
                }
            } else {
                $error = 'La requête SQL ne peut pas être vide';
                $this->addFlash('error', $error);
            }
        }
        
        // Récupérer la liste des tables de la base de données
        $tables = $this->cpanelService->listTables($dbName);
        
        return $this->render('cpanel_database/query.html.twig', [
            'dbName' => $dbName,
            'result' => $result,
            'error' => $error,
            'success' => $success,
            'query' => $request->request->get('query', ''),
            'tables' => $tables,
            'selectedTable' => $request->request->get('selectedTable', $request->query->get('selectedTable'))
        ]);
    }

    /**
     * Génère un mot de passe aléatoire
     */
    private function generateRandomPassword(int $length = 12): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_-=+;:,.?';
        $password = '';
        
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        
        return $password;
    }

    /**
     * Supprime une table d'une base de données et restaure les fichiers générés à leur état précédent
     */
    #[Route('/drop-table/{dbName}/{tableName}', name: 'app_cpanel_database_drop_table', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function dropTable(Request $request, string $dbName, string $tableName): Response
    {
        try {
            // Extraire l'ID du prompt à partir du nom de la base de données
            preg_match('/haan7883_db_(\d+)_/', $dbName, $matches);
            $promptId = $matches[1] ?? null;
            
            if ($promptId) {
                $prompt = $this->promptRepository->find($promptId);
                if ($prompt) {
                    // Sauvegarder les fichiers générés actuels
                    $currentFiles = $prompt->getGeneratedFiles() ?? [];
                    
                    // Identifier et supprimer les fichiers liés à la table
                    $tableNameLower = strtolower($tableName);
                    $tableNameUpper = ucfirst($tableNameLower);
                    $filesToRemove = [
                        "src/Entity/{$tableNameUpper}.php",
                        "src/Repository/{$tableNameUpper}Repository.php",
                        "src/Form/{$tableNameUpper}Type.php",
                        "src/Controller/{$tableNameUpper}Controller.php",
                        "templates/{$tableNameLower}/new.html.twig",
                        "templates/{$tableNameLower}/edit.html.twig",
                        "templates/{$tableNameLower}/show.html.twig"
                    ];
                    
                    foreach ($filesToRemove as $file) {
                        if (isset($currentFiles[$file])) {
                            unset($currentFiles[$file]);
                        }
                    }
                    
                    // Mettre à jour les fichiers générés
                    $prompt->setGeneratedFiles($currentFiles);
                    $this->entityManager->persist($prompt);
                    $this->entityManager->flush();
                }
            }
            
            // Récupérer le mot de passe de la base de données depuis la session
            $dbPassword = $request->getSession()->get('db_password_' . $dbName, null);
            
            // Exécuter la requête SQL pour supprimer la table
            $query = "DROP TABLE `{$tableName}`;";
            $this->cpanelService->executeQuery($dbName, $query, $dbPassword);
            
            $this->addFlash('success', "Table {$tableName} et ses fichiers associés ont été supprimés avec succès");
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de la suppression de la table: ' . $e->getMessage());
        }
        
        return $this->redirectToRoute('app_cpanel_database_query', ['dbName' => $dbName]);
    }
    
    /**
     * Gère l'affichage/masquage des bases de données
     */
    #[Route('/toggle-hidden', name: 'app_cpanel_database_toggle_hidden', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function toggleHidden(Request $request, SessionInterface $session): Response
    {
        $database = $request->request->get('database');
        $action = $request->request->get('action', 'hide'); // 'hide' ou 'show'
        
        if (!empty($database)) {
            // Récupérer la liste actuelle des bases de données cachées
            $hiddenDatabases = $session->get('hidden_databases', []);
            
            if ($action === 'hide' && !in_array($database, $hiddenDatabases)) {
                // Ajouter la base de données à la liste des cachées
                $hiddenDatabases[] = $database;
                $this->addFlash('success', 'Base de données masquée avec succès');
            } elseif ($action === 'show') {
                // Retirer la base de données de la liste des cachées
                $hiddenDatabases = array_filter($hiddenDatabases, function($db) use ($database) {
                    return $db !== $database;
                });
                $this->addFlash('success', 'Base de données affichée avec succès');
            }
            
            // Mettre à jour la session
            $session->set('hidden_databases', $hiddenDatabases);
        }
        
        // Rediriger vers la page précédente ou la page d'administration
        $referer = $request->headers->get('referer');
        if (str_contains($referer, 'admin/prompts')) {
            return $this->redirectToRoute('app_admin_prompts');
        }
        
        return $this->redirectToRoute('app_cpanel_database_index');
    }

    /**
     * Génère une entité Doctrine et son repository à partir d'une table de base de données
     */
    private function generateEntityFromTable(string $dbName, string $tableName, ?string $dbPassword = null): void
    {
        try {
            // Récupérer la structure de la table
            $describeQuery = "DESCRIBE `{$tableName}`;";
            $rawTableStructure = $this->cpanelService->executeQuery($dbName, $describeQuery, $dbPassword);
            
            if (!is_array($rawTableStructure)) {
                throw new \RuntimeException('La structure de la table n\'est pas un tableau valide');
            }
            
            if (empty($rawTableStructure)) {
                throw new \RuntimeException('La structure de la table est vide');
            }
            
            // Extraire la structure de la table du résultat
            if (isset($rawTableStructure[0]) && is_array($rawTableStructure[0])) {
                // Si le premier élément est un tableau avec la structure attendue
                $tableStructure = $rawTableStructure;
            } elseif (isset($rawTableStructure['data']) && is_array($rawTableStructure['data'])) {
                // Si la structure est dans data[]
                $tableStructure = $rawTableStructure['data'];
            } elseif (isset($rawTableStructure[0][0]) && is_array($rawTableStructure[0][0])) {
                // Si la structure est dans un tableau imbriqué
                $tableStructure = $rawTableStructure[0];
            } else {
                // Si aucun format reconnu, utiliser directement rawTableStructure
                $tableStructure = $rawTableStructure;
            }
            
            // Vérifier que chaque colonne a la structure attendue
            $requiredFields = ['Field', 'Type', 'Null', 'Key', 'Default', 'Extra'];
            foreach ($tableStructure as $index => $column) {
                // Ignorer les entrées qui ne sont pas des tableaux ou qui sont des valeurs numériques
                if (!is_array($column) || (count($column) === 1 && isset($column[0]) && is_numeric($column[0]))) {
                    continue;
                }
                
                foreach ($requiredFields as $field) {
                    if (!array_key_exists($field, $column)) {
                        throw new \RuntimeException("Le champ '{$field}' est manquant dans la colonne : " . print_r($column, true));
                    }
                }
            }
            
            // Formater les champs pour l'AIService
            $fields = [];
            foreach ($tableStructure as $column) {
                $field = [
                    'name' => (string)$column['Field'],
                    'type' => (string)$column['Type'],
                    'null' => $column['Null'] === 'YES',
                    'key' => (string)$column['Key'],
                    'default' => $column['Default'] !== null ? (string)$column['Default'] : null,
                    'extra' => (string)$column['Extra']
                ];
                
                // Vérification supplémentaire des valeurs
                if (empty($field['name'])) {
                    throw new \RuntimeException('Un nom de colonne est vide');
                }
                if (empty($field['type'])) {
                    throw new \RuntimeException("Le type est manquant pour la colonne {$field['name']}");
                }
                
                $fields[] = $field;
            }
            
            if (empty($fields)) {
                throw new \RuntimeException('Aucun champ n\'a été extrait de la structure de la table');
            }
            
            // Générer l'entité et le repository via l'AIService
            $files = $this->aiService->generateEntitiesFromTable($tableName, $fields);
            
            if (!is_array($files) || empty($files)) {
                throw new \RuntimeException('Aucun fichier n\'a été généré par l\'AIService');
            }
            
            // Récupérer le dernier prompt de l'utilisateur
            $promptRepository = $this->entityManager->getRepository(\App\Entity\Prompt::class);
            $existingPrompt = $promptRepository->findLatestByUser($this->getUser(), 1);
            
            if (!empty($existingPrompt)) {
                $existingPrompt = $existingPrompt[0];
                // Fusionner les fichiers générés avec ceux existants
                $existingFiles = $existingPrompt->getGeneratedFiles() ?? [];
                $mergedFiles = array_merge($existingFiles, $files);
                $existingPrompt->setGeneratedFiles($mergedFiles);
                $prompt = $existingPrompt;
            } else {
                // Créer un nouveau prompt si aucun n'existe
                $prompt = new \App\Entity\Prompt();
                $prompt->setContent("Génération d'entité pour la table {$tableName}");
                $prompt->setWebsiteType('entity');
                $prompt->setGeneratedFiles($files);
                $prompt->setStatus('completed');
                $prompt->setUser($this->getUser());
            }
            
            // Persister l'entité
            $this->entityManager->persist($prompt);
            $this->entityManager->flush();
            
        } catch (\Exception $e) {
            throw new \RuntimeException('Erreur lors de la génération de l\'entité: ' . $e->getMessage());
        }
    }
    
}