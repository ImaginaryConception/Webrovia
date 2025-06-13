<?php

namespace App\Controller;

use App\Entity\Prompt;
use App\Repository\PromptRepository;
use App\Service\CpanelService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

#[Route('/cpanel/database')]
class CpanelDatabaseWebController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private PromptRepository $promptRepository;
    private CpanelService $cpanelService;

    public function __construct(
        EntityManagerInterface $entityManager,
        PromptRepository $promptRepository,
        CpanelService $cpanelService
    ) {
        $this->entityManager = $entityManager;
        $this->promptRepository = $promptRepository;
        $this->cpanelService = $cpanelService;
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
            
            // Filtrer les bases de données visibles
            $visibleDatabases = array_filter($allDatabases, function($database) use ($hiddenDatabases) {
                return !in_array($database['database'], $hiddenDatabases);
            });
            
            // Récupérer les bases de données cachées
            $hiddenDbList = array_filter($allDatabases, function($database) use ($hiddenDatabases) {
                return in_array($database['database'], $hiddenDatabases);
            });
            
            return $this->render('cpanel_database/index.html.twig', [
                'cpanelDatabases' => $visibleDatabases,
                'hiddenDatabases' => $hiddenDbList,
                'hiddenDatabaseNames' => $hiddenDatabases
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
        
        return $this->render('cpanel_database/new.html.twig', [
            'prompt' => $prompt,
            'dbPrefix' => $dbPrefix
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
                    // Vérifier si la requête est une requête de modification (INSERT, UPDATE, DELETE, etc.)
                    $isModificationQuery = preg_match('/^\s*(INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|TRUNCATE)/i', $query);
                    
                    // Passer le mot de passe de la base de données s'il est fourni
                    $result = $this->cpanelService->executeQuery($dbName, $query, $dbPassword);
                    
                    // Pour les requêtes de modification, ajouter un message de succès
                    if ($isModificationQuery) {
                        if (isset($result['message'])) {
                            $success = $result['message'];
                        } else {
                            $success = 'La requête a été exécutée avec succès. Les modifications ont été appliquées à la base de données.';
                        }
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
     * Supprime une table d'une base de données
     */
    #[Route('/drop-table/{dbName}/{tableName}', name: 'app_cpanel_database_drop_table', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function dropTable(Request $request, string $dbName, string $tableName): Response
    {
        try {
            // Récupérer le mot de passe de la base de données depuis la session, s'il existe
            $dbPassword = $request->getSession()->get('db_password_' . $dbName, null);
            
            // Exécuter la requête SQL pour supprimer la table
            $query = "DROP TABLE `{$tableName}`;";
            $this->cpanelService->executeQuery($dbName, $query, $dbPassword);
            
            $this->addFlash('success', "Table {$tableName} supprimée avec succès");
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
}