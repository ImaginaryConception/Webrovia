<?php

namespace App\Controller;

use App\Entity\Database;
use App\Entity\Prompt;
use App\Repository\DatabaseRepository;
use App\Repository\PromptRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/database')]
class DatabaseController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private DatabaseRepository $databaseRepository;
    private PromptRepository $promptRepository;

    public function __construct(
        EntityManagerInterface $entityManager,
        DatabaseRepository $databaseRepository,
        PromptRepository $promptRepository
    ) {
        $this->entityManager = $entityManager;
        $this->databaseRepository = $databaseRepository;
        $this->promptRepository = $promptRepository;
    }

    /**
     * Crée une nouvelle base de données pour un prompt
     */
    #[Route('/create/{promptId}', name: 'app_database_create', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function create(Request $request, int $promptId): JsonResponse
    {
        $prompt = $this->promptRepository->find($promptId);
        
        if (!$prompt) {
            return $this->json(['error' => 'Prompt not found'], Response::HTTP_NOT_FOUND);
        }
        
        // Vérifier que l'utilisateur est propriétaire du prompt
        if ($prompt->getUser() !== $this->getUser()) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }
        
        // Vérifier si une base de données existe déjà pour ce prompt
        $existingDatabase = $this->databaseRepository->findByPrompt($prompt);
        if ($existingDatabase) {
            return $this->json(['error' => 'Database already exists for this prompt'], Response::HTTP_BAD_REQUEST);
        }
        
        $data = json_decode($request->getContent(), true);
        
        $database = new Database();
        $database->setName($data['name'] ?? 'database_' . $promptId);
        $database->setPrompt($prompt);
        
        $this->databaseRepository->save($database);
        
        return $this->json([
            'id' => $database->getId(),
            'name' => $database->getName(),
            'tables' => $database->getTables(),
            'createdAt' => $database->getCreatedAt()->format('Y-m-d H:i:s')
        ], Response::HTTP_CREATED);
    }

    /**
     * Récupère les informations d'une base de données
     */
    #[Route('/{id}', name: 'app_database_get', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function get(int $id): JsonResponse
    {
        $database = $this->databaseRepository->find($id);
        
        if (!$database) {
            return $this->json(['error' => 'Database not found'], Response::HTTP_NOT_FOUND);
        }
        
        // Vérifier que l'utilisateur est propriétaire du prompt associé
        if ($database->getPrompt()->getUser() !== $this->getUser()) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }
        
        return $this->json([
            'id' => $database->getId(),
            'name' => $database->getName(),
            'tables' => $database->getTables(),
            'createdAt' => $database->getCreatedAt()->format('Y-m-d H:i:s'),
            'updatedAt' => $database->getUpdatedAt() ? $database->getUpdatedAt()->format('Y-m-d H:i:s') : null,
            'promptId' => $database->getPrompt()->getId()
        ]);
    }

    /**
     * Récupère toutes les bases de données d'un prompt
     */
    #[Route('/prompt/{promptId}', name: 'app_database_by_prompt', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function getByPrompt(int $promptId): JsonResponse
    {
        $prompt = $this->promptRepository->find($promptId);
        
        if (!$prompt) {
            return $this->json(['error' => 'Prompt not found'], Response::HTTP_NOT_FOUND);
        }
        
        // Vérifier que l'utilisateur est propriétaire du prompt
        if ($prompt->getUser() !== $this->getUser()) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }
        
        $database = $this->databaseRepository->findByPrompt($prompt);
        
        if (!$database) {
            return $this->json(['error' => 'No database found for this prompt'], Response::HTTP_NOT_FOUND);
        }
        
        return $this->json([
            'id' => $database->getId(),
            'name' => $database->getName(),
            'tables' => $database->getTables(),
            'createdAt' => $database->getCreatedAt()->format('Y-m-d H:i:s'),
            'updatedAt' => $database->getUpdatedAt() ? $database->getUpdatedAt()->format('Y-m-d H:i:s') : null
        ]);
    }

    /**
     * Ajoute une table à une base de données
     */
    #[Route('/{id}/table', name: 'app_database_add_table', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function addTable(Request $request, int $id): JsonResponse
    {
        $database = $this->databaseRepository->find($id);
        
        if (!$database) {
            return $this->json(['error' => 'Database not found'], Response::HTTP_NOT_FOUND);
        }
        
        // Vérifier que l'utilisateur est propriétaire du prompt associé
        if ($database->getPrompt()->getUser() !== $this->getUser()) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }
        
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['tableName']) || empty($data['tableName'])) {
            return $this->json(['error' => 'Table name is required'], Response::HTTP_BAD_REQUEST);
        }
        
        $tableName = $data['tableName'];
        $fields = $data['fields'] ?? [];
        
        $tables = $database->getTables() ?? [];
        
        // Vérifier si la table existe déjà
        if (isset($tables[$tableName])) {
            return $this->json(['error' => 'Table already exists'], Response::HTTP_BAD_REQUEST);
        }
        
        $database->addTable($tableName, $fields);
        $this->databaseRepository->save($database);
        
        return $this->json([
            'tableName' => $tableName,
            'fields' => $fields
        ], Response::HTTP_CREATED);
    }

    /**
     * Supprime une table d'une base de données
     */
    #[Route('/{id}/table/{tableName}', name: 'app_database_remove_table', methods: ['DELETE'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function removeTable(int $id, string $tableName): JsonResponse
    {
        $database = $this->databaseRepository->find($id);
        
        if (!$database) {
            return $this->json(['error' => 'Database not found'], Response::HTTP_NOT_FOUND);
        }
        
        // Vérifier que l'utilisateur est propriétaire du prompt associé
        if ($database->getPrompt()->getUser() !== $this->getUser()) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }
        
        $tables = $database->getTables() ?? [];
        
        // Vérifier si la table existe
        if (!isset($tables[$tableName])) {
            return $this->json(['error' => 'Table not found'], Response::HTTP_NOT_FOUND);
        }
        
        $database->removeTable($tableName);
        $this->databaseRepository->save($database);
        
        return $this->json(['success' => true]);
    }

    /**
     * Ajoute un champ à une table
     */
    #[Route('/{id}/table/{tableName}/field', name: 'app_database_add_field', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function addField(Request $request, int $id, string $tableName): JsonResponse
    {
        $database = $this->databaseRepository->find($id);
        
        if (!$database) {
            return $this->json(['error' => 'Database not found'], Response::HTTP_NOT_FOUND);
        }
        
        // Vérifier que l'utilisateur est propriétaire du prompt associé
        if ($database->getPrompt()->getUser() !== $this->getUser()) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }
        
        $tables = $database->getTables() ?? [];
        
        // Vérifier si la table existe
        if (!isset($tables[$tableName])) {
            return $this->json(['error' => 'Table not found'], Response::HTTP_NOT_FOUND);
        }
        
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['fieldName']) || empty($data['fieldName'])) {
            return $this->json(['error' => 'Field name is required'], Response::HTTP_BAD_REQUEST);
        }
        
        if (!isset($data['fieldType']) || empty($data['fieldType'])) {
            return $this->json(['error' => 'Field type is required'], Response::HTTP_BAD_REQUEST);
        }
        
        $fieldName = $data['fieldName'];
        $fieldType = $data['fieldType'];
        
        // Vérifier si le champ existe déjà
        if (isset($tables[$tableName]['fields'][$fieldName])) {
            return $this->json(['error' => 'Field already exists'], Response::HTTP_BAD_REQUEST);
        }
        
        $database->addField($tableName, $fieldName, $fieldType);
        $this->databaseRepository->save($database);
        
        return $this->json([
            'fieldName' => $fieldName,
            'fieldType' => $fieldType
        ], Response::HTTP_CREATED);
    }

    /**
     * Supprime un champ d'une table
     */
    #[Route('/{id}/table/{tableName}/field/{fieldName}', name: 'app_database_remove_field', methods: ['DELETE'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function removeField(int $id, string $tableName, string $fieldName): JsonResponse
    {
        $database = $this->databaseRepository->find($id);
        
        if (!$database) {
            return $this->json(['error' => 'Database not found'], Response::HTTP_NOT_FOUND);
        }
        
        // Vérifier que l'utilisateur est propriétaire du prompt associé
        if ($database->getPrompt()->getUser() !== $this->getUser()) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }
        
        $tables = $database->getTables() ?? [];
        
        // Vérifier si la table existe
        if (!isset($tables[$tableName])) {
            return $this->json(['error' => 'Table not found'], Response::HTTP_NOT_FOUND);
        }
        
        // Vérifier si le champ existe
        if (!isset($tables[$tableName]['fields'][$fieldName])) {
            return $this->json(['error' => 'Field not found'], Response::HTTP_NOT_FOUND);
        }
        
        $database->removeField($tableName, $fieldName);
        $this->databaseRepository->save($database);
        
        return $this->json(['success' => true]);
    }

    /**
     * Ajoute un enregistrement à une table
     */
    #[Route('/{id}/table/{tableName}/record', name: 'app_database_add_record', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function addRecord(Request $request, int $id, string $tableName): JsonResponse
    {
        $database = $this->databaseRepository->find($id);
        
        if (!$database) {
            return $this->json(['error' => 'Database not found'], Response::HTTP_NOT_FOUND);
        }
        
        // Vérifier que l'utilisateur est propriétaire du prompt associé
        if ($database->getPrompt()->getUser() !== $this->getUser()) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }
        
        $tables = $database->getTables() ?? [];
        
        // Vérifier si la table existe
        if (!isset($tables[$tableName])) {
            return $this->json(['error' => 'Table not found'], Response::HTTP_NOT_FOUND);
        }
        
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['record']) || !is_array($data['record'])) {
            return $this->json(['error' => 'Record data is required'], Response::HTTP_BAD_REQUEST);
        }
        
        $record = $data['record'];
        
        // Vérifier que tous les champs requis sont présents
        foreach ($tables[$tableName]['fields'] as $fieldName => $fieldType) {
            if (!isset($record[$fieldName])) {
                return $this->json(['error' => "Field '$fieldName' is required"], Response::HTTP_BAD_REQUEST);
            }
        }
        
        $database->addRecord($tableName, $record);
        $this->databaseRepository->save($database);
        
        return $this->json([
            'record' => $record,
            'index' => count($tables[$tableName]['records'])
        ], Response::HTTP_CREATED);
    }

    /**
     * Met à jour un enregistrement dans une table
     */
    #[Route('/{id}/table/{tableName}/record/{recordIndex}', name: 'app_database_update_record', methods: ['PUT'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function updateRecord(Request $request, int $id, string $tableName, int $recordIndex): JsonResponse
    {
        $database = $this->databaseRepository->find($id);
        
        if (!$database) {
            return $this->json(['error' => 'Database not found'], Response::HTTP_NOT_FOUND);
        }
        
        // Vérifier que l'utilisateur est propriétaire du prompt associé
        if ($database->getPrompt()->getUser() !== $this->getUser()) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }
        
        $tables = $database->getTables() ?? [];
        
        // Vérifier si la table existe
        if (!isset($tables[$tableName])) {
            return $this->json(['error' => 'Table not found'], Response::HTTP_NOT_FOUND);
        }
        
        // Vérifier si l'enregistrement existe
        if (!isset($tables[$tableName]['records'][$recordIndex])) {
            return $this->json(['error' => 'Record not found'], Response::HTTP_NOT_FOUND);
        }
        
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['record']) || !is_array($data['record'])) {
            return $this->json(['error' => 'Record data is required'], Response::HTTP_BAD_REQUEST);
        }
        
        $record = $data['record'];
        
        // Vérifier que tous les champs requis sont présents
        foreach ($tables[$tableName]['fields'] as $fieldName => $fieldType) {
            if (!isset($record[$fieldName])) {
                return $this->json(['error' => "Field '$fieldName' is required"], Response::HTTP_BAD_REQUEST);
            }
        }
        
        $database->updateRecord($tableName, $recordIndex, $record);
        $this->databaseRepository->save($database);
        
        return $this->json([
            'record' => $record
        ]);
    }

    /**
     * Supprime un enregistrement d'une table
     */
    #[Route('/{id}/table/{tableName}/record/{recordIndex}', name: 'app_database_remove_record', methods: ['DELETE'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function removeRecord(int $id, string $tableName, int $recordIndex): JsonResponse
    {
        $database = $this->databaseRepository->find($id);
        
        if (!$database) {
            return $this->json(['error' => 'Database not found'], Response::HTTP_NOT_FOUND);
        }
        
        // Vérifier que l'utilisateur est propriétaire du prompt associé
        if ($database->getPrompt()->getUser() !== $this->getUser()) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }
        
        $tables = $database->getTables() ?? [];
        
        // Vérifier si la table existe
        if (!isset($tables[$tableName])) {
            return $this->json(['error' => 'Table not found'], Response::HTTP_NOT_FOUND);
        }
        
        // Vérifier si l'enregistrement existe
        if (!isset($tables[$tableName]['records'][$recordIndex])) {
            return $this->json(['error' => 'Record not found'], Response::HTTP_NOT_FOUND);
        }
        
        $database->removeRecord($tableName, $recordIndex);
        $this->databaseRepository->save($database);
        
        return $this->json(['success' => true]);
    }
}