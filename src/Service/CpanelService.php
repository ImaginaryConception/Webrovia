<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;

class CpanelService
{
    private const API_BASE_PATH = '/execute/'; // Chemin de base pour l'API UAPI
    private $params;
    private $apiUrl;
    private $apiToken;
    private $apiUsername;
    private $apiPassword;
    private $httpClient;
    private $createdDbCredentials = null; // Stocke les informations d'identification de la base de données créée

    public function __construct(ParameterBagInterface $params)
    {
        $this->params = $params;
        $this->apiUrl = $_ENV['CPANEL_API_URL'] ?? 'https://moustache.o2switch.net:2083/'; // Format: https://hostname:2083
        $this->apiUsername = $_ENV['APP_FTP_USER'] ?? 'haan7883'; // Utilisation du nom d'utilisateur FTP
        $this->apiPassword = $_ENV['APP_FTP_PASSWORD'] ?? 'XDQ4-8FHv-T6z!'; // Utilisation du mot de passe FTP
        $this->httpClient = HttpClient::create();
    }

    /**
     * Crée une nouvelle base de données MySQL via l'API cPanel
     *
     * @param string $dbName Nom de la base de données (sans préfixe)
     * @return array Réponse de l'API
     * @throws \Exception En cas d'erreur lors de la création
     */
    public function createDatabase(string $dbName): array
    {
        // Le nom de la base de données sera préfixé par le nom d'utilisateur cPanel
        // Format typique: username_dbname
        try {
            $response = $this->makeApiRequest('Mysql/create_database', [
                'name' => $dbName
            ]);

            if (!$response['status']) {
                throw new \Exception($response['errors'][0] ?? 'Erreur lors de la création de la base de données');
            }

            return $response;
        } catch (\Exception $e) {
            throw new \Exception('Erreur lors de la création de la base de données: ' . $e->getMessage());
        }
    }

    /**
     * Crée un utilisateur MySQL via l'API cPanel
     *
     * @param string $username Nom d'utilisateur (sans préfixe)
     * @param string $password Mot de passe
     * @return array Réponse de l'API
     * @throws \Exception En cas d'erreur lors de la création
     */
    public function createDatabaseUser(string $username, string $password): array
    {
        try {
            $response = $this->makeApiRequest('Mysql/create_user', [
                'name' => $username,
                'password' => $password
            ]);

            if (!$response['status']) {
                throw new \Exception($response['errors'][0] ?? 'Erreur lors de la création de l\'utilisateur');
            }
            return $response;
        } catch (\Exception $e) {
            throw new \Exception('Erreur lors de la création de l\'utilisateur: ' . $e->getMessage());
        }
    }

    /**
     * Accorde tous les privilèges à un utilisateur sur une base de données
     *
     * @param string $dbName Nom de la base de données (sans préfixe)
     * @param string $username Nom d'utilisateur (sans préfixe)
     * @return array Réponse de l'API
     * @throws \Exception En cas d'erreur
     */
    public function setDatabaseUserPrivileges(string $dbName, string $username): array
    {
        try {
            $response = $this->makeApiRequest('Mysql/set_privileges_on_database', [
                'user' => $username,
                'database' => $dbName,
                'privileges' => 'ALL PRIVILEGES'
            ]);

            if (!$response['status']) {
                throw new \Exception($response['errors'][0] ?? 'Erreur lors de l\'attribution des privilèges');
            }

            return $response;
        } catch (\Exception $e) {
            throw new \Exception('Erreur lors de l\'attribution des privilèges: ' . $e->getMessage());
        }
    }

    /**
     * Liste toutes les bases de données
     *
     * @return array Liste des bases de données
     * @throws \Exception En cas d'erreur
     */
    public function listDatabases(): array
    {
        try {
            $response = $this->makeApiRequest('Mysql/list_databases', []);

            if (!$response['status']) {
                throw new \Exception($response['errors'][0] ?? 'Erreur lors de la récupération des bases de données');
            }

            return $response['data'] ?? [];
        } catch (\Exception $e) {
            throw new \Exception('Erreur lors de la récupération des bases de données: ' . $e->getMessage());
        }
    }

    /**
     * Supprime une base de données et ses utilisateurs associés
     *
     * @param string $dbName Nom de la base de données (sans préfixe)
     * @return array Réponse de l'API
     * @throws \Exception En cas d'erreur
     */
    public function deleteDatabase(string $dbName): array
    {
        try {
            // Récupérer le nom complet de la base de données avec le préfixe si nécessaire
            $fullDbName = $this->getFullDatabaseName($dbName);
            
            // Récupérer les utilisateurs associés à cette base de données avant de la supprimer
            $privilegesResponse = $this->makeApiRequest('Mysql/get_privileges_on_database', [
                'database' => $fullDbName
            ]);
            
            error_log("Réponse de get_privileges_on_database pour $fullDbName: " . json_encode($privilegesResponse));
            
            $dbUsers = [];
            if ($privilegesResponse['status'] && !empty($privilegesResponse['data'])) {
                $dbUsers = $privilegesResponse['data'];
                error_log("Nombre d'utilisateurs associés à la base de données $fullDbName: " . count($dbUsers));
                foreach ($dbUsers as $index => $userPrivilege) {
                    error_log("Utilisateur #$index: " . json_encode($userPrivilege));
                }
            } else {
                error_log("Aucun utilisateur associé à la base de données $fullDbName ou erreur dans la réponse");
            }
            
            // Supprimer la base de données
            $response = $this->makeApiRequest('Mysql/delete_database', [
                'name' => $dbName
            ]);

            if (!$response['status']) {
                throw new \Exception($response['errors'][0] ?? 'Erreur lors de la suppression de la base de données');
            }
            
            // Supprimer les utilisateurs associés à cette base de données
            $deletedUsers = [];
            $failedUsers = [];
            
            // Récupérer la liste complète des utilisateurs MySQL
            $allUsersResponse = $this->makeApiRequest('Mysql/list_users', []);
            $allUsers = [];
            
            if ($allUsersResponse['status'] && !empty($allUsersResponse['data'])) {
                $allUsers = $allUsersResponse['data'];
                error_log("Nombre total d'utilisateurs MySQL trouvés: " . count($allUsers));
            } else {
                error_log("Impossible de récupérer la liste des utilisateurs MySQL");
            }
            
            foreach ($dbUsers as $userPrivilege) {
                if (isset($userPrivilege['user'])) {
                    $user = $userPrivilege['user'];
                    error_log("Tentative de suppression de l'utilisateur: $user");
                    
                    // Vérifier si l'utilisateur existe dans la liste complète
                    $userExists = false;
                    $userInfo = null;
                    
                    foreach ($allUsers as $mysqlUser) {
                        if (isset($mysqlUser['user']) && $mysqlUser['user'] === $user) {
                            $userExists = true;
                            $userInfo = $mysqlUser;
                            break;
                        }
                    }
                    
                    if (!$userExists) {
                        error_log("L'utilisateur $user n'existe pas dans la liste des utilisateurs MySQL");
                        continue;
                    }
                    
                    // Conserver le nom d'utilisateur complet avec le préfixe cPanel
                    $username = $user;
                    error_log("Utilisation du nom d'utilisateur complet pour la suppression: $username");
                    
                    // Vérifier si l'utilisateur a des privilèges sur d'autres bases de données
                    $otherPrivilegesResponse = $this->makeApiRequest('Mysql/get_user_privileges', [
                        'user' => $username
                    ]);
                    error_log("Vérification des privilèges pour l'utilisateur: $username");
                    
                    $hasOtherDatabases = false;
                    if ($otherPrivilegesResponse['status'] && !empty($otherPrivilegesResponse['data'])) {
                        foreach ($otherPrivilegesResponse['data'] as $privilege) {
                            if (isset($privilege['database']) && $privilege['database'] !== $fullDbName) {
                                $hasOtherDatabases = true;
                                error_log("L'utilisateur $user a également des privilèges sur la base de données: " . $privilege['database']);
                                break;
                            }
                        }
                    }
                    
                    if ($hasOtherDatabases) {
                        error_log("L'utilisateur $user a des privilèges sur d'autres bases de données, il ne sera pas supprimé");
                        continue;
                    }
                    
                    try {
                        // Essayer d'abord avec le nom complet
                        error_log("Tentative de suppression de l'utilisateur avec le nom complet: $username");
                        $deleteUserResponse = $this->makeApiRequest('Mysql/delete_user', [
                            'name' => $username
                        ]);
                        
                        // Si la suppression échoue avec le nom complet, essayer sans le préfixe
                        if (!$deleteUserResponse['status'] && strpos($username, $this->apiUsername . '_') === 0) {
                            $usernameWithoutPrefix = substr($username, strlen($this->apiUsername) + 1);
                            error_log("Échec avec le nom complet, tentative avec le nom sans préfixe: $usernameWithoutPrefix");
                            $deleteUserResponse = $this->makeApiRequest('Mysql/delete_user', [
                                'name' => $usernameWithoutPrefix
                            ]);
                        }
                        
                        error_log("Réponse de delete_user: " . json_encode($deleteUserResponse));
                        
                        if ($deleteUserResponse['status']) {
                            $deletedUsers[] = $user;
                            error_log("Utilisateur $user supprimé avec succès");
                        } else {
                            $failedUsers[] = $user;
                            $errorMessage = $deleteUserResponse['errors'][0] ?? 'Erreur inconnue';
                            error_log("Échec de la suppression de l'utilisateur $user: $errorMessage");
                            
                            // Vérifier si l'erreur indique que l'utilisateur n'existe pas
                            if (strpos($errorMessage, 'does not exist') !== false || 
                                strpos($errorMessage, 'n\'existe pas') !== false) {
                                error_log("L'utilisateur $user n'existe pas selon l'API cPanel");
                            }
                        }
                    } catch (\Exception $e) {
                        $failedUsers[] = $user;
                        error_log("Exception lors de la suppression de l'utilisateur $user: " . $e->getMessage());
                    }
                }
            }
            
            // Ajouter des informations sur les utilisateurs supprimés à la réponse
            $response['deleted_users'] = $deletedUsers;
            $response['failed_users'] = $failedUsers;

            return $response;
        } catch (\Exception $e) {
            throw new \Exception('Erreur lors de la suppression de la base de données: ' . $e->getMessage());
        }
    }

    /**
     * Exécute une requête SQL sur une base de données
     *
     * @param string $dbName Nom de la base de données (sans préfixe)
     * @param string $query Requête SQL à exécuter
     * @param string|null $dbPassword Mot de passe de la base de données (optionnel)
     * @return array Réponse de l'API
     * @throws \Exception En cas d'erreur
     */
    public function executeQuery(string $dbName, string $query, ?string $dbPassword = null): array
    {
        try {
            // Vérifier si la requête est une requête de modification (INSERT, UPDATE, DELETE, etc.)
            $isModificationQuery = preg_match('/^\s*(INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|TRUNCATE)/i', $query);
            
            // Ajouter le préfixe de l'utilisateur cPanel si nécessaire
            $fullDbName = $dbName;
            if (strpos($dbName, $this->apiUsername . '_') !== 0) {
                $fullDbName = $this->apiUsername . '_' . $dbName;
            }
            
            error_log("Nom de base de données original: '$dbName', avec préfixe: '$fullDbName'");
            
            // Nous n'appliquons plus de nettoyage au nom de la base de données pour éviter les problèmes
            // Le nom de la base de données doit être utilisé tel quel
            
            // Conserver le nom original de la base de données
            $cleanDbName = $fullDbName;
            
            error_log("Nom de base de données final utilisé: '$fullDbName'");
            
            error_log('Tentative d\'exécution de requête SQL via connexion directe');
            
            try {
                // Récupérer les informations de connexion MySQL depuis l'API cPanel
                $dbInfoResponse = $this->makeApiRequest('Mysql/get_server_information', []);
                
                if (!$dbInfoResponse['status']) {
                    throw new \Exception('Impossible de récupérer les informations du serveur MySQL');
                }
                
                $dbInfo = $dbInfoResponse['data'];
                // Essayer d'abord avec l'adresse IP du serveur O2SWITCH si définie
                $host = $_ENV['O2SWITCH_IP'] ?? $dbInfo['host'] ?? 'localhost';
                
                error_log("Tentative de connexion à la base de données avec l'hôte: " . $host);
                
                // Récupérer les privilèges des utilisateurs pour cette base de données
                $privilegesResponse = $this->makeApiRequest('Mysql/get_privileges_on_database', [
                    'database' => $fullDbName
                ]);
                
                $dbUsers = [];
                if ($privilegesResponse['status'] && !empty($privilegesResponse['data'])) {
                    $dbUsers = $privilegesResponse['data'];
                }
                
                // Tentatives de connexion avec différents utilisateurs
                $connectionErrors = [];
                $mysqli = null;
                
                // Si un mot de passe spécifique a été fourni, l'utiliser avec le premier utilisateur disponible
                if ($dbPassword !== null) {
                    $user = null;
                    
                    // Trouver le premier utilisateur avec des privilèges
                    foreach ($dbUsers as $userPrivilege) {
                        if (isset($userPrivilege['user']) && !empty($userPrivilege['privileges'])) {
                            $user = $userPrivilege['user'];
                            break;
                        }
                    }
                    
                    // Si aucun utilisateur spécifique n'est trouvé, utiliser l'utilisateur cPanel
                    if ($user === null) {
                        $user = $this->apiUsername;
                    }
                    
                    error_log("Tentative de connexion avec l'utilisateur: " . $user . " et le mot de passe fourni");
                    
                    // Essayer différents hôtes pour la connexion
                    $hostsToTry = [
                        $host,                    // L'hôte récupéré de l'API ou l'IP O2SWITCH
                        'localhost',              // Nom d'hôte local standard
                        '127.0.0.1',              // Adresse IP locale standard
                        $_ENV['O2SWITCH_IP'] ?? '' // IP du serveur O2SWITCH si définie
                    ];
                    
                    // Filtrer les hôtes vides ou dupliqués
                    $hostsToTry = array_filter(array_unique($hostsToTry));
                    
                    foreach ($hostsToTry as $tryHost) {
                        try {
                            error_log("Tentative de connexion à l'hôte: " . $tryHost . " avec l'utilisateur: " . $user);
                            $mysqli = new \mysqli($tryHost, $user, $dbPassword, $fullDbName);
                            if (!$mysqli->connect_error) {
                                // Connexion réussie
                                error_log("Connexion réussie avec l'hôte: " . $tryHost . " et l'utilisateur: " . $user);
                                break; // Sortir de la boucle si la connexion réussit
                            } else {
                                $connectionErrors[] = "Échec avec l'hôte $tryHost et l'utilisateur $user: " . $mysqli->connect_error;
                                $mysqli = null; // Réinitialiser pour les tentatives suivantes
                            }
                        } catch (\Exception $e) {
                            $connectionErrors[] = "Exception avec l'hôte $tryHost et l'utilisateur $user: " . $e->getMessage();
                        }
                    }
                }
                
                // Si la connexion avec le mot de passe fourni a échoué ou si aucun mot de passe n'a été fourni
                if ($mysqli === null || $mysqli->connect_error) {
                    // 1. Essayer avec les utilisateurs spécifiques à la base de données
                    foreach ($dbUsers as $userPrivilege) {
                        if (isset($userPrivilege['user']) && !empty($userPrivilege['privileges'])) {
                            $user = $userPrivilege['user'];
                            error_log("Tentative de connexion avec l'utilisateur de base de données: " . $user);
                            
                            // Essayer avec différentes combinaisons de mots de passe
                            $passwordsToTry = [
                                $this->apiPassword, // Mot de passe cPanel
                                $dbName, // Nom de la base de données comme mot de passe
                                $user, // Nom d'utilisateur comme mot de passe
                                $this->apiUsername . '_' . $dbName, // Nom complet de la base de données
                            ];
                            
                            // Essayer différents hôtes pour la connexion
                            $hostsToTry = [
                                $host,                    // L'hôte récupéré de l'API ou l'IP O2SWITCH
                                'localhost',              // Nom d'hôte local standard
                                '127.0.0.1',              // Adresse IP locale standard
                                $_ENV['O2SWITCH_IP'] ?? '' // IP du serveur O2SWITCH si définie
                            ];
                            
                            // Filtrer les hôtes vides ou dupliqués
                            $hostsToTry = array_filter(array_unique($hostsToTry));
                            
                            $userSuccess = false;
                            
                            foreach ($passwordsToTry as $password) {
                                foreach ($hostsToTry as $tryHost) {
                                    try {
                                        error_log("Tentative de connexion à l'hôte: " . $tryHost . " avec l'utilisateur: " . $user . " et mot de passe: " . substr($password, 0, 3) . '***');
                                        $mysqli = new \mysqli($tryHost, $user, $password, $fullDbName);
                                        if (!$mysqli->connect_error) {
                                            error_log("Connexion réussie avec l'hôte: " . $tryHost . " et l'utilisateur: " . $user);
                                            $userSuccess = true;
                                            break 2; // Sortir des deux boucles si la connexion réussit
                                        }
                                    } catch (\Exception $e) {
                                        // Continuer avec l'hôte suivant
                                        error_log("Échec de connexion à l'hôte: " . $tryHost . " avec l'utilisateur: " . $user . ": " . $e->getMessage());
                                    }
                                }
                            }
                            
                            if (!$userSuccess) {
                                $connectionErrors[] = "Échec avec l'utilisateur $user: Tous les mots de passe et hôtes essayés ont échoué";
                            } else {
                                break; // Sortir de la boucle des utilisateurs si un a réussi
                            }
                        }
                    }
                    
                    // 2. Si aucun utilisateur spécifique n'a fonctionné, essayer avec l'utilisateur cPanel principal
                    if (!$mysqli || $mysqli->connect_error) {
                        error_log("Tentative de connexion avec l'utilisateur cPanel principal: " . $this->apiUsername);
                        
                        // Essayer différents hôtes pour la connexion
                        $hostsToTry = [
                            $host,                    // L'hôte récupéré de l'API ou l'IP O2SWITCH
                            'localhost',              // Nom d'hôte local standard
                            '127.0.0.1',              // Adresse IP locale standard
                            $_ENV['O2SWITCH_IP'] ?? '' // IP du serveur O2SWITCH si définie
                        ];
                        
                        // Filtrer les hôtes vides ou dupliqués
                        $hostsToTry = array_filter(array_unique($hostsToTry));
                        
                        $cpanelSuccess = false;
                        
                        foreach ($hostsToTry as $tryHost) {
                            try {
                                error_log("Tentative de connexion à l'hôte: " . $tryHost . " avec l'utilisateur cPanel: " . $this->apiUsername);
                                $mysqli = new \mysqli($tryHost, $this->apiUsername, $this->apiPassword, $fullDbName);
                                if (!$mysqli->connect_error) {
                                    error_log("Connexion réussie avec l'hôte: " . $tryHost . " et l'utilisateur cPanel principal");
                                    $cpanelSuccess = true;
                                    break; // Sortir de la boucle si la connexion réussit
                                } else {
                                    $connectionErrors[] = "Échec avec l'hôte $tryHost et l'utilisateur cPanel: " . $mysqli->connect_error;
                                }
                            } catch (\Exception $e) {
                                $connectionErrors[] = "Exception avec l'hôte $tryHost et l'utilisateur cPanel: " . $e->getMessage();
                            }
                        }
                        
                        if ($cpanelSuccess) {
                            error_log("Connexion réussie avec l'utilisateur cPanel principal");
                        }
                    }
                }
                
                // Essayer avec les identifiants de la base de données du fichier .env en dernier recours
                if (!$mysqli || $mysqli->connect_error) {
                    error_log("Tentative de connexion avec les identifiants du fichier .env");
                    
                    // Extraire les informations de connexion de DATABASE_URL
                    $dbUrl = $_ENV['DATABASE_URL'] ?? '';
                    if (!empty($dbUrl)) {
                        try {
                            // Analyser DATABASE_URL pour extraire les identifiants
                            preg_match('/mysql:\/\/([^:]+):([^@]*)@([^:]+):(\d+)\/([^?]+)/', $dbUrl, $matches);
                            
                            if (count($matches) >= 6) {
                                $dbUser = $matches[1];
                                $dbPass = $matches[2];
                                $dbHost = $matches[3];
                                $dbPort = $matches[4];
                                $dbName = $matches[5];
                                
                                error_log("Tentative de connexion avec les identifiants du fichier .env: utilisateur=$dbUser, hôte=$dbHost");
                                
                                try {
                                    // Essayer d'abord sans spécifier de base de données
                                    $mysqli = new \mysqli($dbHost, $dbUser, $dbPass);
                                    if (!$mysqli->connect_error) {
                                        error_log("Connexion réussie avec les identifiants du fichier .env sans spécifier de base de données");
                                        
                                        // Essayer de sélectionner la base de données
                                        if ($mysqli->select_db($fullDbName)) {
                                            error_log("Sélection de la base de données réussie avec les identifiants du fichier .env");
                                        } else {
                                            // Si la sélection échoue, essayer de créer la base de données
                                            error_log("Tentative de création de la base de données: " . $fullDbName);
                                            $createDbQuery = "CREATE DATABASE IF NOT EXISTS `" . $mysqli->real_escape_string($fullDbName) . "`";
                                            
                                            if ($mysqli->query($createDbQuery)) {
                                                error_log("Base de données créée avec succès: " . $fullDbName);
                                                if ($mysqli->select_db($fullDbName)) {
                                                    error_log("Sélection de la base de données créée réussie");
                                                } else {
                                                    error_log("Échec de la sélection de la base de données créée: " . $mysqli->error);
                                                    $mysqli->close();
                                                    $mysqli = null;
                                                }
                                            } else {
                                                error_log("Échec de la création de la base de données: " . $mysqli->error);
                                                $mysqli->close();
                                                $mysqli = null;
                                            }
                                        }
                                    } else {
                                        $connectionErrors[] = "Échec avec les identifiants du fichier .env: " . $mysqli->connect_error;
                                    }
                                } catch (\Exception $e) {
                                    $connectionErrors[] = "Exception avec les identifiants du fichier .env: " . $e->getMessage();
                                }
                            }
                        } catch (\Exception $e) {
                            error_log("Erreur lors de l'analyse de DATABASE_URL: " . $e->getMessage());
                        }
                    }
                }
                
                // Essayer de se connecter sans spécifier de base de données, puis sélectionner la base de données
                if (!$mysqli || $mysqli->connect_error) {
                    error_log("Tentative de connexion sans spécifier de base de données");
                    
                    // Essayer différents hôtes pour la connexion
                    $hostsToTry = [
                        $host,                    // L'hôte récupéré de l'API ou l'IP O2SWITCH
                        'localhost',              // Nom d'hôte local standard
                        '127.0.0.1',              // Adresse IP locale standard
                        $_ENV['O2SWITCH_IP'] ?? '' // IP du serveur O2SWITCH si définie
                    ];
                    
                    // Filtrer les hôtes vides ou dupliqués
                    $hostsToTry = array_filter(array_unique($hostsToTry));
                    
                    // Utilisateurs à essayer
                    $usersToTry = [$this->apiUsername];
                    foreach ($dbUsers as $userPrivilege) {
                        if (isset($userPrivilege['user']) && !empty($userPrivilege['privileges'])) {
                            $usersToTry[] = $userPrivilege['user'];
                        }
                    }
                    
                    // Mots de passe à essayer
                    $passwordsToTry = [$this->apiPassword];
                    if ($dbPassword !== null) {
                        $passwordsToTry[] = $dbPassword;
                    }
                    
                    foreach ($usersToTry as $user) {
                        foreach ($passwordsToTry as $password) {
                            foreach ($hostsToTry as $tryHost) {
                                try {
                                    error_log("Tentative de connexion sans base de données à l'hôte: " . $tryHost . " avec l'utilisateur: " . $user);
                                    
                                    // Connexion sans spécifier de base de données
                                    $tempMysqli = new \mysqli($tryHost, $user, $password);
                                    
                                    if (!$tempMysqli->connect_error) {
                                        error_log("Connexion sans base de données réussie. Tentative de sélection de la base de données: " . $fullDbName);
                                        
                                        // Sélectionner la base de données après la connexion
                                        if ($tempMysqli->select_db($fullDbName)) {
                                            error_log("Sélection de la base de données réussie");
                                            $mysqli = $tempMysqli;
                                            break 3; // Sortir des trois boucles si la connexion réussit
                                        } else {
                                            // Si la sélection échoue, essayer de créer la base de données
                                            error_log("Tentative de création de la base de données: " . $fullDbName);
                                            $createDbQuery = "CREATE DATABASE IF NOT EXISTS `" . $tempMysqli->real_escape_string($fullDbName) . "`";
                                            
                                            try {
                                                if ($tempMysqli->query($createDbQuery)) {
                                                    error_log("Base de données créée avec succès: " . $fullDbName);
                                                    if ($tempMysqli->select_db($fullDbName)) {
                                                        error_log("Sélection de la base de données créée réussie");
                                                        $mysqli = $tempMysqli;
                                                        break 3; // Sortir des trois boucles si la connexion réussit
                                                    } else {
                                                        error_log("Échec de la sélection de la base de données créée: " . $tempMysqli->error);
                                                        $tempMysqli->close();
                                                    }
                                                } else {
                                                    // Si la création échoue, vérifier si c'est un problème de privilèges
                                                    $errorMsg = $tempMysqli->error;
                                                    error_log("Échec de la création de la base de données: " . $errorMsg);
                                                    
                                                    // Si l'erreur est liée aux privilèges, essayer avec l'API cPanel
                                                    if (strpos($errorMsg, 'access denied') !== false || strpos($errorMsg, 'permission') !== false) {
                                                        error_log("Tentative de création de la base de données via l'API cPanel");
                                                        try {
                                                            // Extraire le nom de la base de données sans le préfixe de l'utilisateur cPanel
                                                            $dbNameWithoutPrefix = $fullDbName;
                                                            if (strpos($fullDbName, $this->apiUsername . '_') === 0) {
                                                                $dbNameWithoutPrefix = substr($fullDbName, strlen($this->apiUsername) + 1);
                                                            }
                                                            
                                                            // Créer la base de données via l'API cPanel
                                                            $response = $this->makeApiRequest('Mysql/create_database', [
                                                                'name' => $dbNameWithoutPrefix
                                                            ]);
                                                            
                                                            // Si la base de données est créée avec succès, accorder tous les privilèges à l'utilisateur
                                                            if (isset($response['status']) && $response['status']) {
                                                                error_log("Tentative d'attribution des privilèges à l'utilisateur sur la base de données");
                                                                try {
                                                                    // Récupérer l'utilisateur MySQL associé au compte cPanel
                                                                    $mysqlUsers = $this->makeApiRequest('Mysql/list_users');
                                                                    
                                                                    if (isset($mysqlUsers['data']) && !empty($mysqlUsers['data'])) {
                                                                        // Utiliser le premier utilisateur MySQL disponible ou celui qui correspond au nom d'utilisateur cPanel
                                                                        $mysqlUser = null;
                                                                        foreach ($mysqlUsers['data'] as $user) {
                                                                            if (isset($user['user']) && $user['user'] === $this->apiUsername) {
                                                                                $mysqlUser = $user['user'];
                                                                                break;
                                                                            }
                                                                        }
                                                                        
                                                                        // Si aucun utilisateur correspondant n'est trouvé, utiliser le premier de la liste
                                                                        if (!$mysqlUser && isset($mysqlUsers['data'][0]['user'])) {
                                                                            $mysqlUser = $mysqlUsers['data'][0]['user'];
                                                                        }
                                                                        
                                                                        if ($mysqlUser) {
                                                                            // Accorder tous les privilèges à l'utilisateur sur la base de données
                                                                            $privilegesResponse = $this->makeApiRequest('Mysql/set_privileges_on_database', [
                                                                                'user' => $mysqlUser,
                                                                                'database' => $dbNameWithoutPrefix,
                                                                                'privileges' => 'ALL PRIVILEGES'
                                                                            ]);
                                                                            
                                                                            if (isset($privilegesResponse['status']) && $privilegesResponse['status']) {
                                                                                error_log("Privilèges accordés avec succès à l'utilisateur {$mysqlUser} sur la base de données {$dbNameWithoutPrefix}");
                                                                            } else {
                                                                                error_log("Échec de l'attribution des privilèges: " . json_encode($privilegesResponse));
                                                                            }
                                                                        } else {
                                                                            error_log("Aucun utilisateur MySQL trouvé pour accorder les privilèges, tentative de création d'un nouvel utilisateur");
                                                                            
                                                                            // Générer un nom d'utilisateur et un mot de passe pour le nouvel utilisateur MySQL
                                                                            $newMysqlUser = substr($this->apiUsername . '_' . uniqid(), 0, 16); // Limité à 16 caractères
                                                                            $newMysqlPassword = bin2hex(random_bytes(8)); // Générer un mot de passe aléatoire
                                                                            
                                                                            // Créer le nouvel utilisateur MySQL
                                                                            $createUserResponse = $this->makeApiRequest('Mysql/create_user', [
                                                                                'name' => $newMysqlUser,
                                                                                'password' => $newMysqlPassword
                                                                            ]);
                                                                            
                                                                            if (isset($createUserResponse['status']) && $createUserResponse['status']) {
                                                                                error_log("Nouvel utilisateur MySQL créé avec succès: {$newMysqlUser}");
                                                                                
                                                                                // Accorder tous les privilèges au nouvel utilisateur sur la base de données
                                                                                $privilegesResponse = $this->makeApiRequest('Mysql/set_privileges_on_database', [
                                                                                    'user' => $newMysqlUser,
                                                                                    'database' => $dbNameWithoutPrefix,
                                                                                    'privileges' => 'ALL PRIVILEGES'
                                                                                ]);
                                                                                
                                                                                if (isset($privilegesResponse['status']) && $privilegesResponse['status']) {
                                                                                    error_log("Privilèges accordés avec succès au nouvel utilisateur {$newMysqlUser} sur la base de données {$dbNameWithoutPrefix}");
                                                                                    
                                                                                    // Stocker les informations de connexion pour une utilisation ultérieure
                                                                                    $this->createdDbCredentials = [
                                                                                        'database' => $fullDbName,
                                                                                        'username' => $newMysqlUser,
                                                                                        'password' => $newMysqlPassword
                                                                                    ];
                                                                                    
                                                                                    // Définir les hôtes à essayer pour la connexion
                                                                                    $hosts = [
                                                                                        $host,                    // L'hôte récupéré de l'API ou l'IP O2SWITCH
                                                                                        'localhost',              // Nom d'hôte local standard
                                                                                        '127.0.0.1',              // Adresse IP locale standard
                                                                                        $_ENV['O2SWITCH_IP'] ?? '' // IP du serveur O2SWITCH si définie
                                                                                    ];
                                                                                    
                                                                                    // Filtrer les hôtes vides ou dupliqués
                                                                                    $hosts = array_filter(array_unique($hosts));
                                                                                    
                                                                                    // Définir les hôtes à essayer pour la connexion
                                                                                    $hosts = [
                                                                                        $host,                    // L'hôte récupéré de l'API ou l'IP O2SWITCH
                                                                                        'localhost',              // Nom d'hôte local standard
                                                                                        '127.0.0.1',              // Adresse IP locale standard
                                                                                        $_ENV['O2SWITCH_IP'] ?? '' // IP du serveur O2SWITCH si définie
                                                                                    ];
                                                                                    
                                                                                    // Filtrer les hôtes vides ou dupliqués
                                                                                    $hosts = array_filter(array_unique($hosts));
                                                                                    
                                                                                    // Essayer de se connecter avec le nouvel utilisateur
                                                                                    foreach ($hosts as $testHost) {
                                                                                        error_log("Tentative de connexion avec le nouvel utilisateur MySQL sur l'hôte: " . $testHost);
                                                                                        try {
                                                                                            $newUserMysqli = new \mysqli($testHost, $newMysqlUser, $newMysqlPassword, $fullDbName);
                                                                                            if (!$newUserMysqli->connect_error) {
                                                                                                error_log("Connexion réussie avec le nouvel utilisateur MySQL");
                                                                                                $mysqli = $newUserMysqli;
                                                                                                break 4; // Sortir de toutes les boucles si la connexion réussit
                                                                                            } else {
                                                                                                error_log("Échec de la connexion avec le nouvel utilisateur MySQL: " . $newUserMysqli->connect_error);
                                                                                                $newUserMysqli->close();
                                                                                            }
                                                                                        } catch (\Exception $e) {
                                                                                            error_log("Exception lors de la connexion avec le nouvel utilisateur MySQL: " . $e->getMessage());
                                                                                        }
                                                                                    }
                                                                                } else {
                                                                                    error_log("Échec de l'attribution des privilèges au nouvel utilisateur: " . json_encode($privilegesResponse));
                                                                                }
                                                                            } else {
                                                                                error_log("Échec de la création du nouvel utilisateur MySQL: " . json_encode($createUserResponse));
                                                                            }
                                                                        }
                                                                    } else {
                                                                        error_log("Aucun utilisateur MySQL trouvé dans le compte cPanel");
                                                                    }
                                                                } catch (\Exception $e) {
                                                                    error_log("Exception lors de l'attribution des privilèges: " . $e->getMessage());
                                                                }
                                                            }
                                                            
                                                            if (isset($response['status']) && $response['status']) {
                                                                error_log("Base de données créée avec succès via l'API cPanel");
                                                                
                                                                // Réessayer de sélectionner la base de données
                                                                if ($tempMysqli->select_db($fullDbName)) {
                                                                    error_log("Sélection de la base de données créée via API réussie");
                                                                    $mysqli = $tempMysqli;
                                                                    break 3; // Sortir des trois boucles si la connexion réussit
                                                                } else {
                                                                    error_log("Échec de la sélection de la base de données créée via API: " . $tempMysqli->error);
                                                                    $tempMysqli->close();
                                                                }
                                                            } else {
                                                                error_log("Échec de la création de la base de données via l'API cPanel: " . json_encode($response));
                                                                $tempMysqli->close();
                                                            }
                                                        } catch (\Exception $e) {
                                                            error_log("Exception lors de la création de la base de données via l'API cPanel: " . $e->getMessage());
                                                            $tempMysqli->close();
                                                        }
                                                    } else {
                                                        $tempMysqli->close();
                                                    }
                                                }
                                            } catch (\Exception $e) {
                                                error_log("Exception lors de la création de la base de données: " . $e->getMessage());
                                                $tempMysqli->close();
                                            }
                                        }
                                    } else {
                                        error_log("Échec de la connexion sans base de données: " . $tempMysqli->connect_error);
                                    }
                                } catch (\Exception $e) {
                                    error_log("Exception lors de la connexion sans base de données: " . $e->getMessage());
                                }
                            }
                        }
                    }
                }
                
                // Si toutes les tentatives ont échoué
                if (!$mysqli || $mysqli->connect_error) {
                    $errorMsg = "Impossible de se connecter à la base de données. Erreurs: " . implode("; ", $connectionErrors);
                    error_log($errorMsg);
                    throw new \Exception($errorMsg);
                }
                
                error_log("Connexion à la base de données réussie. Vérification de la sélection de la base de données: '$fullDbName'");
                
                // Vérifier que la base de données est bien sélectionnée
                if (!$mysqli->select_db($fullDbName)) {
                    // Essayer de lister les bases de données disponibles pour le débogage
                    $showDbsResult = $mysqli->query("SHOW DATABASES");
                    $availableDbs = [];
                    if ($showDbsResult) {
                        while ($row = $showDbsResult->fetch_row()) {
                            $availableDbs[] = $row[0];
                        }
                        $showDbsResult->free();
                        error_log("Bases de données disponibles: " . implode(", ", $availableDbs));
                    }
                    
                    // Vérifier si la base de données existe dans la liste
                    if (in_array($fullDbName, $availableDbs)) {
                        error_log("La base de données '$fullDbName' existe mais ne peut pas être sélectionnée");
                        
                        // Vérifier les privilèges de l'utilisateur sur cette base de données
                        $userPrivilegesResult = $mysqli->query("SHOW GRANTS FOR CURRENT_USER()");
                        if ($userPrivilegesResult) {
                            error_log("Privilèges de l'utilisateur actuel:");
                            while ($row = $userPrivilegesResult->fetch_row()) {
                                error_log($row[0]);
                            }
                            $userPrivilegesResult->free();
                        } else {
                            error_log("Impossible de vérifier les privilèges de l'utilisateur: " . $mysqli->error);
                        }
                        
                        // Essayer une connexion directe avec le nom de la base de données
                        try {
                            error_log("Tentative de connexion directe avec la base de données '$fullDbName'");
                            $directMysqli = new \mysqli($host, $user, $dbPassword, $fullDbName);
                            if (!$directMysqli->connect_error) {
                                error_log("Connexion directe réussie");
                                $mysqli->close();
                                $mysqli = $directMysqli;
                            } else {
                                error_log("Échec de la connexion directe: " . $directMysqli->connect_error);
                            }
                        } catch (\Exception $e) {
                            error_log("Exception lors de la connexion directe: " . $e->getMessage());
                        }
                        
                        // Vérifier si la base de données existe via INFORMATION_SCHEMA
                        try {
                            $checkDbQuery = "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$fullDbName'";
                            $checkResult = $mysqli->query($checkDbQuery);
                            if ($checkResult) {
                                $dbExists = $checkResult->num_rows > 0;
                                error_log("Vérification via INFORMATION_SCHEMA: La base de données '$fullDbName' " . ($dbExists ? "existe" : "n'existe pas"));
                                $checkResult->free();
                                
                                if ($dbExists) {
                                    // Essayer de se connecter sans spécifier la base de données puis la sélectionner
                                    try {
                                        $tempMysqli = new \mysqli($host, $user, $dbPassword);
                                        if (!$tempMysqli->connect_error) {
                                            if ($tempMysqli->select_db($fullDbName)) {
                                                error_log("Connexion et sélection réussies en deux étapes");
                                                $mysqli->close();
                                                $mysqli = $tempMysqli;
                                            } else {
                                                error_log("Échec de la sélection en deux étapes: " . $tempMysqli->error);
                                                $tempMysqli->close();
                                            }
                                        }
                                    } catch (\Exception $e) {
                                        error_log("Exception lors de la connexion en deux étapes: " . $e->getMessage());
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                            error_log("Exception lors de la vérification via INFORMATION_SCHEMA: " . $e->getMessage());
                        }
                        
                        // Essayer de se connecter directement avec le nom de la base de données
                        error_log("Tentative de connexion directe avec la base de données '$fullDbName'");
                        // Récupérer les informations de connexion actuelles
                        $currentUserResult = $mysqli->query("SELECT CURRENT_USER() as user");
                        $currentUser = '';
                        if ($currentUserResult && $row = $currentUserResult->fetch_assoc()) {
                            $currentUser = $row['user'] ?? '';
                            // Extraire le nom d'utilisateur de la forme 'user@host'
                            if (strpos($currentUser, '@') !== false) {
                                $currentUser = substr($currentUser, 0, strpos($currentUser, '@'));
                            }
                        }
                        error_log("Utilisateur actuel: $currentUser");
                        
                        // Essayer de vérifier si la base de données existe réellement
                        $checkDbExistsQuery = "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$fullDbName'";
                        $checkDbResult = $mysqli->query($checkDbExistsQuery);
                        if ($checkDbResult) {
                            $dbExists = $checkDbResult->num_rows > 0;
                            $checkDbResult->free();
                            error_log("Vérification via INFORMATION_SCHEMA: La base de données '$fullDbName' " . ($dbExists ? "existe" : "n'existe pas"));
                        } else {
                            error_log("Impossible de vérifier l'existence via INFORMATION_SCHEMA: " . $mysqli->error);
                        }
                        
                        // Utiliser les informations de connexion actuelles ou les dernières utilisées
                        $directMysqli = new \mysqli($host, $currentUser ?: $user, $dbPassword ?? '', $fullDbName);
                        if (!$directMysqli->connect_error) {
                            error_log("Connexion directe réussie avec la base de données '$fullDbName'");
                            $mysqli->close();
                            $mysqli = $directMysqli;
                        } else {
                            error_log("Échec de la connexion directe: " . $directMysqli->connect_error);
                            
                            // Essayer une connexion sans spécifier la base de données puis la sélectionner
                            error_log("Tentative de connexion sans spécifier la base de données");
                            $tempMysqli = new \mysqli($host, $currentUser ?: $user, $dbPassword ?? '');
                            if (!$tempMysqli->connect_error) {
                                if ($tempMysqli->select_db($fullDbName)) {
                                    error_log("Connexion et sélection réussies en deux étapes");
                                    $mysqli->close();
                                    $mysqli = $tempMysqli;
                                } else {
                                    error_log("Échec de la sélection en deux étapes: " . $tempMysqli->error);
                                    $tempMysqli->close();
                                }
                            } else {
                                error_log("Échec de la connexion sans base de données: " . $tempMysqli->connect_error);
                            }
                        }
                    } else {
                        error_log("La base de données '$fullDbName' n'existe pas dans la liste des bases de données disponibles");
                        
                        // Vérifier si le nom de la base de données contient des caractères spéciaux
                        if (preg_match('/[\s\-\.\.\,\;\:\!\?\(\)\[\]\{\}\@\#\$\%\^\&\*\+\=\/\\\\]/', $fullDbName)) {
                            error_log("Le nom de la base de données '$fullDbName' contient des caractères spéciaux qui pourraient causer des problèmes");
                            
                            // Essayer avec un nom nettoyé
                            $cleanedDbName = preg_replace('/[\s\-\.\.\,\;\:\!\?\(\)\[\]\{\}\@\#\$\%\^\&\*\+\=\/\\\\]/', '_', $fullDbName);
                            $cleanedDbName = preg_replace('/_+/', '_', $cleanedDbName);
                            error_log("Tentative avec un nom nettoyé: '$cleanedDbName'");
                            
                            if ($mysqli->select_db($cleanedDbName)) {
                                error_log("Sélection réussie avec le nom nettoyé '$cleanedDbName'");
                                $fullDbName = $cleanedDbName; // Utiliser le nom nettoyé pour la suite
                            } else {
                                error_log("Échec de la sélection avec le nom nettoyé: " . $mysqli->error);
                            }
                        }
                        
                        // Essayer de créer la base de données si elle n'existe pas
                        error_log("Tentative de création de la base de données '$fullDbName'");
                        $createDbQuery = "CREATE DATABASE IF NOT EXISTS `" . $mysqli->real_escape_string($fullDbName) . "`";
                        if ($mysqli->query($createDbQuery)) {
                            error_log("Base de données créée avec succès");
                            if ($mysqli->select_db($fullDbName)) {
                                error_log("Sélection de la base de données créée réussie");
                            } else {
                                error_log("Échec de la sélection de la base de données créée: " . $mysqli->error);
                            }
                        } else {
                            error_log("Échec de la création de la base de données via SQL: " . $mysqli->error);
                            
                            // Essayer de créer la base de données via l'API cPanel
                            try {
                                // Extraire le nom de la base de données sans le préfixe
                                $cpanelUsername = $this->apiUsername;
                                $dbNameWithoutPrefix = $fullDbName;
                                if (strpos($fullDbName, $cpanelUsername . '_') === 0) {
                                    $dbNameWithoutPrefix = substr($fullDbName, strlen($cpanelUsername) + 1);
                                }
                                
                                error_log("Tentative de création via l'API cPanel: $dbNameWithoutPrefix");
                                $apiResponse = $this->makeApiRequest('Mysql/create_database', [
                                    'name' => $dbNameWithoutPrefix
                                ]);
                                
                                if ($apiResponse['status']) {
                                    error_log("Base de données créée avec succès via l'API cPanel");
                                    // Réessayer la sélection après création via API
                                    if ($mysqli->select_db($fullDbName)) {
                                        error_log("Sélection de la base de données créée via API réussie");
                                    } else {
                                        error_log("Échec de la sélection après création via API: " . $mysqli->error);
                                    }
                                } else {
                                    $errorMsg = $apiResponse['errors'][0] ?? 'Erreur inconnue';
                                    error_log("Échec de la création via l'API cPanel: $errorMsg");
                                }
                            } catch (\Exception $e) {
                                error_log("Exception lors de la création via l'API cPanel: " . $e->getMessage());
                            }
                        }
                    }
                    
                    // Si toutes les tentatives ont échoué, essayer avec un nom échappé
                    if (!$mysqli->select_db($fullDbName)) {
                        error_log("Échec de la sélection de la base de données '$fullDbName'. Tentative avec un nom échappé.");
                        $escapedDbName = '`' . $mysqli->real_escape_string($fullDbName) . '`';
                        $selectQuery = "USE $escapedDbName";
                        
                        if (!$mysqli->query($selectQuery)) {
                            // Vérifier si la base de données existe dans la liste des bases de données
                            $showDbsQuery = "SHOW DATABASES LIKE '" . $mysqli->real_escape_string($fullDbName) . "'";
                            $showResult = $mysqli->query($showDbsQuery);
                            
                            if ($showResult && $showResult->num_rows > 0) {
                                error_log("La base de données existe mais ne peut pas être sélectionnée. Problème de permissions possible.");
                                // Tenter de vérifier les permissions
                                $checkPermQuery = "SHOW GRANTS FOR CURRENT_USER()";
                                $permResult = $mysqli->query($checkPermQuery);
                                if ($permResult) {
                                    while ($row = $permResult->fetch_row()) {
                                        error_log("Permission: " . $row[0]);
                                    }
                                    $permResult->free();
                                }
                                
                                // Tenter de créer un nouvel utilisateur et lui attribuer des privilèges
                                try {
                                    // Extraire le nom de la base de données sans le préfixe
                                    $cpanelUsername = $this->apiUsername;
                                    $dbNameWithoutPrefix = $fullDbName;
                                    if (strpos($fullDbName, $cpanelUsername . '_') === 0) {
                                        $dbNameWithoutPrefix = substr($fullDbName, strlen($cpanelUsername) + 1);
                                    }
                                    
                                    // Générer un nom d'utilisateur et un mot de passe uniques
                                    $uniqueId = substr(md5(uniqid(mt_rand(), true)), 0, 8);
                                    $newUsername = 'user_' . $uniqueId;
                                    $newPassword = bin2hex(random_bytes(8));
                                    
                                    error_log("Tentative de création d'un nouvel utilisateur: $newUsername");
                                    $createUserResponse = $this->createDatabaseUser($newUsername, $newPassword);
                                    
                                    if ($createUserResponse['status']) {
                                        error_log("Utilisateur créé avec succès. Attribution des privilèges.");
                                        $privilegesResponse = $this->setDatabaseUserPrivileges($dbNameWithoutPrefix, $newUsername);
                                        
                                        if ($privilegesResponse['status']) {
                                            error_log("Privilèges attribués avec succès. Tentative de connexion avec le nouvel utilisateur.");
                                            
                                            // Tenter de se connecter avec le nouvel utilisateur
                                            try {
                                                $newUserMysqli = new \mysqli($host, $this->apiUsername . '_' . $newUsername, $newPassword, $fullDbName);
                                                if (!$newUserMysqli->connect_error) {
                                                    error_log("Connexion réussie avec le nouvel utilisateur.");
                                                    $mysqli->close();
                                                    $mysqli = $newUserMysqli;
                                                    
                                                    // Stocker les informations d'identification pour une utilisation ultérieure
                                                    $this->createdDbCredentials = [
                                                        'host' => $host,
                                                        'username' => $this->apiUsername . '_' . $newUsername,
                                                        'password' => $newPassword,
                                                        'database' => $fullDbName
                                                    ];
                                                } else {
                                                    error_log("Échec de la connexion avec le nouvel utilisateur: " . $newUserMysqli->connect_error);
                                                }
                                            } catch (\Exception $e) {
                                                error_log("Exception lors de la connexion avec le nouvel utilisateur: " . $e->getMessage());
                                            }
                                        } else {
                                            $errorMsg = $privilegesResponse['errors'][0] ?? 'Erreur inconnue';
                                            error_log("Échec de l'attribution des privilèges: $errorMsg");
                                        }
                                    } else {
                                        $errorMsg = $createUserResponse['errors'][0] ?? 'Erreur inconnue';
                                        error_log("Échec de la création de l'utilisateur: $errorMsg");
                                    }
                                } catch (\Exception $e) {
                                    error_log("Exception lors de la création de l'utilisateur: " . $e->getMessage());
                                }
                            } else {
                                error_log("La base de données '$fullDbName' n'existe pas dans la liste des bases de données.");
                                
                                // Tenter de créer la base de données via l'API cPanel
                                try {
                                    // Extraire le nom de la base de données sans le préfixe
                                    $cpanelUsername = $this->apiUsername;
                                    $dbNameWithoutPrefix = $fullDbName;
                                    if (strpos($fullDbName, $cpanelUsername . '_') === 0) {
                                        $dbNameWithoutPrefix = substr($fullDbName, strlen($cpanelUsername) + 1);
                                    }
                                    
                                    error_log("Tentative de création via l'API cPanel: $dbNameWithoutPrefix");
                                    $apiResponse = $this->makeApiRequest('Mysql/create_database', [
                                        'name' => $dbNameWithoutPrefix
                                    ]);
                                    
                                    if ($apiResponse['status']) {
                                        error_log("Base de données créée avec succès via l'API cPanel");
                                        // Réessayer la sélection après création via API
                                        if ($mysqli->select_db($fullDbName)) {
                                            error_log("Sélection de la base de données créée via API réussie");
                                        } else {
                                            error_log("Échec de la sélection après création via API: " . $mysqli->error);
                                        }
                                    } else {
                                        $errorMsg = $apiResponse['errors'][0] ?? 'Erreur inconnue';
                                        error_log("Échec de la création via l'API cPanel: $errorMsg");
                                    }
                                } catch (\Exception $e) {
                                    error_log("Exception lors de la création via l'API cPanel: " . $e->getMessage());
                                }
                            }
                            
                            throw new \Exception('Erreur lors de la sélection de la base de données: ' . $mysqli->error);
                        } else {
                            error_log("Sélection réussie avec la requête USE et le nom échappé.");
                        }
                    }
                }
                
                error_log("Base de données '$fullDbName' sélectionnée avec succès. Exécution de la requête.");
                
                // Exécuter la requête
                $queryResult = $mysqli->query($query);
                
                if ($queryResult === false) {
                    throw new \Exception('Erreur lors de l\'exécution de la requête SQL: ' . $mysqli->error);
                }
                
                // Préparer la réponse
                $result = [
                    'status' => true,
                    'data' => []
                ];
                
                // Pour les requêtes SELECT, récupérer les résultats
                if ($queryResult instanceof \mysqli_result) {
                    while ($row = $queryResult->fetch_assoc()) {
                        $result['data'][] = $row;
                    }
                    $queryResult->free();
                } else if ($isModificationQuery) {
                    // Pour les requêtes de modification, ajouter des informations supplémentaires
                    $result['message'] = 'La requête a été exécutée avec succès. ' . 
                        'Les modifications ont été appliquées à la base de données. ' . 
                        'Lignes affectées: ' . $mysqli->affected_rows;
                }
                
                // Fermer la connexion
                $mysqli->close();
                
                return $result;
            } catch (\Exception $e) {
                error_log('Erreur lors de l\'exécution de la requête SQL via l\'API cPanel: ' . $e->getMessage());
                
                // Si l'exécution via l'API cPanel échoue, essayer d'utiliser phpMyAdmin
                // Cette partie pourrait nécessiter une implémentation spécifique selon votre hébergeur
                throw $e; // Pour l'instant, on propage l'erreur
            }
        } catch (\Exception $e) {
            error_log('Erreur lors de l\'exécution de la requête SQL: ' . $e->getMessage());
            throw new \Exception('Erreur lors de l\'exécution de la requête SQL: ' . $e->getMessage());
        }
    }

    /**
     * Liste toutes les tables d'une base de données
     *
     * @param string $dbName Nom de la base de données (sans préfixe)
     * @return array Liste des tables
     * @throws \Exception En cas d'erreur
     */
    public function listTables(string $dbName): array
    {
        try {
            $response = $this->executeQuery($dbName, 'SHOW TABLES');
            
            if (!isset($response['data']) || !is_array($response['data'])) {
                return [];
            }
            
            $tables = [];
            foreach ($response['data'] as $row) {
                if (!empty($row) && is_array($row)) {
                    // Prendre la première valeur de chaque ligne (nom de la table)
                    $tables[] = reset($row);
                }
            }
            
            return $tables;
        } catch (\Exception $e) {
            // En cas d'erreur, retourner un tableau vide au lieu de lancer une exception
            // pour ne pas bloquer l'affichage de la page
            return [];
        }
    }

    /**
     * Récupère les informations d'identification de la dernière base de données créée
     * 
     * @return array|null Informations d'identification (database, username, password) ou null si aucune base de données n'a été créée
     */
    public function getCreatedDbCredentials(): ?array
    {
        return $this->createdDbCredentials;
    }

    /**
     * Effectue une requête à l'API cPanel
     *
     * @param string $endpoint Point de terminaison de l'API (ex: 'Mysql/create_database')
     * @param array $params Paramètres de la requête
     * @return array Réponse de l'API
     * @throws \Exception En cas d'erreur
     */
    private function makeApiRequest(string $endpoint, array $params = []): array
    {
        if (empty($this->apiUrl)) {
            throw new \Exception('URL de l\'API cPanel manquante');
        }

        $url = rtrim($this->apiUrl, '/') . self::API_BASE_PATH . $endpoint;
        $queryParams = http_build_query($params);
        
        if (!empty($queryParams)) {
            $url .= '?' . $queryParams;
        }

        $options = [];
        
        // Utiliser le token API si disponible
        if (!empty($this->apiToken)) {
            $options['headers'] = [
                'Authorization' => 'cpanel ' . $this->apiToken
            ];
        }
        // Sinon, utiliser l'authentification Basic avec nom d'utilisateur et mot de passe
        elseif (!empty($this->apiUsername) && !empty($this->apiPassword)) {
            $options['auth_basic'] = [$this->apiUsername, $this->apiPassword];
        }
        else {
            throw new \Exception('Informations d\'authentification cPanel manquantes (token ou identifiants)');
        }

        try {
            // Ajouter des informations de débogage
            $debugInfo = [
                'endpoint' => $endpoint,
                'params' => $params,
                'url' => $url
            ];
            
            // Masquer le mot de passe dans les logs
            if (isset($debugInfo['params']['password'])) {
                $debugInfo['params']['password'] = '***MASKED***';
            }
            
            // Log de la requête (en mode développement uniquement)
            if ($_ENV['APP_ENV'] === 'dev') {
                error_log('cPanel API Request: ' . json_encode($debugInfo, JSON_UNESCAPED_UNICODE));
            }
            
            $response = $this->httpClient->request('GET', $url, $options);

            $content = $response->getContent();
            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Erreur lors du décodage de la réponse JSON: ' . json_last_error_msg());
            }
            
            // Log de la réponse (en mode développement uniquement)
            if ($_ENV['APP_ENV'] === 'dev') {
                $responseLog = [
                    'endpoint' => $endpoint,
                    'status' => $data['status'] ?? 'unknown',
                    'has_data' => isset($data['data']),
                    'errors' => $data['errors'] ?? []
                ];
                error_log('cPanel API Response: ' . json_encode($responseLog, JSON_UNESCAPED_UNICODE));
            }

            return $data;
        } catch (TransportExceptionInterface | ClientExceptionInterface | ServerExceptionInterface | RedirectionExceptionInterface $e) {
            // Log de l'erreur
            error_log('cPanel API Error: ' . $e->getMessage());
            throw new \Exception('Erreur HTTP lors de la requête à l\'API cPanel: ' . $e->getMessage());
        }
    }

    /**
     * Obtient le nom complet de la base de données avec le préfixe cPanel
     *
     * @param string $dbName Nom de la base de données (sans préfixe)
     * @return string Nom complet de la base de données avec préfixe
     */
    public function getFullDatabaseName(string $dbName): string
    {
        // Ajouter le préfixe cPanel si nécessaire
        if (strpos($dbName, $this->apiUsername . '_') !== 0) {
            return $this->apiUsername . '_' . $dbName;
        }
        return $dbName;
    }
}