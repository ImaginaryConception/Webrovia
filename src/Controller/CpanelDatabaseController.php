<?php

namespace App\Controller;

use App\Entity\Prompt;
use App\Repository\PromptRepository;
use App\Service\CpanelService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/cpanel/database')]
class CpanelDatabaseController extends AbstractController
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
     * Crée une nouvelle base de données MySQL via l'API cPanel
     */
    #[Route('/create/{promptId}', name: 'app_cpanel_database_create', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function createDatabase(Request $request, int $promptId): JsonResponse
    {
        $prompt = $this->promptRepository->find($promptId);
        
        if (!$prompt) {
            return $this->json(['error' => 'Prompt not found'], Response::HTTP_NOT_FOUND);
        }
        
        // Vérifier que l'utilisateur est propriétaire du prompt
        if ($prompt->getUser() !== $this->getUser()) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }
        
        $data = json_decode($request->getContent(), true);
        // Préfixer le nom de la base de données avec haan7883_
        $dbName = $data['name'] ?? 'db_' . $promptId;
        if (!str_starts_with($dbName, 'haan7883_')) {
            $dbName = 'haan7883_' . $dbName;
        }
        
        try {
            // Créer la base de données via l'API cPanel
            $cpanelResponse = $this->cpanelService->createDatabase($dbName);
            
            // Créer un utilisateur pour la base de données
            $username = $data['username'] ?? 'user_' . $promptId;
            // Préfixer le nom d'utilisateur avec haan7883_
            if (!str_starts_with($username, 'haan7883_')) {
                $username = 'haan7883_' . $username;
            }
            $password = $data['password'] ?? $this->generateRandomPassword();
            
            $this->cpanelService->createDatabaseUser($username, $password);
            
            // Attribuer les privilèges à l'utilisateur
            $this->cpanelService->setDatabaseUserPrivileges($dbName, $username);
            
            return $this->json([
                'name' => $dbName,
                'cpanelResponse' => $cpanelResponse,
                'username' => $username,
                'password' => $password, // Attention: ne pas exposer le mot de passe en production
                'createdAt' => (new \DateTime())->format('Y-m-d H:i:s')
            ], Response::HTTP_CREATED);
            
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Liste toutes les bases de données MySQL via l'API cPanel
     */
    #[Route('/list', name: 'app_cpanel_database_list', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function listDatabases(): JsonResponse
    {
        try {
            $databases = $this->cpanelService->listDatabases();
            return $this->json($databases);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Supprime une base de données MySQL via l'API cPanel
     */
    #[Route('/delete/{dbName}', name: 'app_cpanel_database_delete', methods: ['DELETE'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function deleteDatabase(string $dbName): JsonResponse
    {
        try {
            $response = $this->cpanelService->deleteDatabase($dbName);
            return $this->json(['success' => true, 'response' => $response]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Exécute une requête SQL sur une base de données MySQL via l'API cPanel
     */
    #[Route('/execute-query/{dbName}', name: 'app_cpanel_database_execute_query', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function executeQuery(Request $request, string $dbName): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $query = $data['query'] ?? '';
        $dbPassword = $data['db_password'] ?? null;
        
        if (empty($query)) {
            return $this->json(['error' => 'Query is required'], Response::HTTP_BAD_REQUEST);
        }
        
        try {
            $response = $this->cpanelService->executeQuery($dbName, $query, $dbPassword);
            return $this->json($response);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Récupère la structure d'une table (colonnes et types) via l'API cPanel
     */
    #[Route('/table-structure/{dbName}', name: 'app_cpanel_database_get_table_structure', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function getTableStructure(Request $request, string $dbName): JsonResponse
    {
        $tableName = $request->query->get('table_name');
        if (empty($tableName)) {
            return $this->json(['error' => 'Table name is required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $structure = $this->cpanelService->getTableStructure($dbName, $tableName);
            return $this->json($structure);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Ajoute des données à une table via l'API cPanel
     */
    #[Route('/add-data/{dbName}', name: 'app_cpanel_database_add_data', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function addData(Request $request, string $dbName): JsonResponse
    {
        $data = $request->request->all();
        $tableName = $data['table_name'] ?? null;
        $dbPassword = $data['db_password'] ?? null;

        if (empty($tableName)) {
            return $this->json(['error' => 'Table name is required'], Response::HTTP_BAD_REQUEST);
        }

        // Remove table_name and db_password from data, remaining are column_name => value
        unset($data['table_name']);
        unset($data['db_password']);

        if (empty($data)) {
            return $this->json(['error' => 'No data provided to insert'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $response = $this->cpanelService->insertData($dbName, $tableName, $data, $dbPassword);
            $this->addFlash('success', 'Données ajoutées avec succès à la table ' . $tableName);
            return $this->json(['success' => true, 'message' => 'Données ajoutées avec succès', 'response' => $response]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
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
}