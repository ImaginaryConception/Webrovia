<?php

namespace App\Controller;

use App\Entity\Database;
use App\Form\DatabaseType;
use App\Form\TableType;
use App\Repository\DatabaseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/database')]
class DatabaseWebController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private DatabaseRepository $databaseRepository;

    public function __construct(
        EntityManagerInterface $entityManager,
        DatabaseRepository $databaseRepository
    ) {
        $this->entityManager = $entityManager;
        $this->databaseRepository = $databaseRepository;
    }

    /**
     * Liste des bases de données
     */
    #[Route('/', name: 'app_database_index', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function index(): Response
    {
        $user = $this->getUser();
        $databases = $this->databaseRepository->findByUser($user?->getId());

        return $this->render('database/index.html.twig', [
            'databases' => $databases,
        ]);
    }

    /**
     * Création d'une nouvelle base de données
     */
    #[Route('/new', name: 'app_database_new', methods: ['GET', 'POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function new(Request $request): Response
    {
        $database = new Database();
        $form = $this->createForm(DatabaseType::class, $database);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // La classe Database n'a pas de méthode setUser directe
            // Elle est liée à l'utilisateur via le Prompt
            $prompt = new \App\Entity\Prompt();
            $prompt->setUser($this->getUser());
            $prompt->setContent('Base de données créée manuellement');
            $prompt->setWebsiteType('Database');
            $this->entityManager->persist($prompt);
            
            // Lier la base de données au prompt
            $database->setPrompt($prompt);
            // createdAt est défini dans le constructeur de Database
            $database->setUpdatedAt(new \DateTimeImmutable());
            
            $this->entityManager->persist($database);
            $this->entityManager->flush();

            $this->addFlash('success', 'Base de données créée avec succès.');
            return $this->redirectToRoute('app_database_show', ['id' => $database->getId()]);
        }

        // Récupérer les prompts de l'utilisateur
        $prompts = $this->entityManager->getRepository(\App\Entity\Prompt::class)->findLatestByUser($this->getUser());

        return $this->render('database/new.html.twig', [
            'database' => $database,
            'form' => $form,
            'prompts' => $prompts,
        ]);
    }

    /**
     * Affichage d'une base de données
     */
    #[Route('/{id}', name: 'app_database_show', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function show(Database $database): Response
    {
        $user = $this->getUser();
        if ($database->getUser() !== $user) {
            $this->addFlash('error', 'Vous n\'avez pas accès à cette base de données.');
            return $this->redirectToRoute('app_database_index');
        }

        return $this->render('database/show.html.twig', [
            'database' => $database,
        ]);
    }

    /**
     * Édition d'une base de données
     */
    #[Route('/{id}/edit', name: 'app_database_edit', methods: ['GET', 'POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function edit(Request $request, Database $database): Response
    {
        $user = $this->getUser();
        if ($database->getUser() !== $user) {
            $this->addFlash('error', 'Vous n\'avez pas accès à cette base de données.');
            return $this->redirectToRoute('app_database_index');
        }

        $form = $this->createForm(DatabaseType::class, $database);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $database->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('success', 'Base de données mise à jour avec succès.');
            return $this->redirectToRoute('app_database_show', ['id' => $database->getId()]);
        }

        return $this->render('database/edit.html.twig', [
            'database' => $database,
            'form' => $form,
        ]);
    }

    /**
     * Suppression d'une base de données
     */
    #[Route('/{id}/delete', name: 'app_database_delete', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function delete(Request $request, Database $database): Response
    {
        $user = $this->getUser();
        if ($database->getUser() !== $user) {
            $this->addFlash('error', 'Vous n\'avez pas accès à cette base de données.');
            return $this->redirectToRoute('app_database_index');
        }

        if ($this->isCsrfTokenValid('delete-database-'.$database->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($database);
            $this->entityManager->flush();
            $this->addFlash('success', 'Base de données supprimée avec succès.');
        }

        return $this->redirectToRoute('app_database_index');
    }

    /**
     * Ajout d'une table à une base de données
     */
    #[Route('/{id}/add-table', name: 'app_database_add_table', methods: ['GET', 'POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function addTable(Request $request, Database $database): Response
    {
        $user = $this->getUser();
        if ($database->getUser() !== $user) {
            $this->addFlash('error', 'Vous n\'avez pas accès à cette base de données.');
            return $this->redirectToRoute('app_database_index');
        }

        $form = $this->createForm(TableType::class);
        $form->handleRequest($request);

        if ($request->isMethod('POST')) {
            $tableName = $request->request->get('table_name');
            $fieldsData = $request->request->get('fields', []);
            
            // Validation du nom de la table
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $tableName)) {
                $this->addFlash('error', 'Le nom de la table doit commencer par une lettre et ne contenir que des lettres, des chiffres et des underscores.');
                return $this->redirectToRoute('app_database_add_table', ['id' => $database->getId()]);
            }
            
            // Vérification si la table existe déjà
            $tables = $database->getTables() ?? [];
            if (isset($tables[$tableName])) {
                $this->addFlash('error', "Une table avec le nom '$tableName' existe déjà.");
                return $this->redirectToRoute('app_database_add_table', ['id' => $database->getId()]);
            }
            
            // Création de la structure de la table
            $fields = [];
            // Vérifier que $fieldsData est bien un tableau avant de l'itérer
            if (is_array($fieldsData)) {
                foreach ($fieldsData as $field) {
                    $fieldName = $field['name'] ?? '';
                    $fieldType = $field['type'] ?? '';
                    
                    if (!empty($fieldName) && !empty($fieldType)) {
                        // Validation du nom du champ
                        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $fieldName)) {
                            $this->addFlash('error', "Le nom du champ '$fieldName' doit commencer par une lettre et ne contenir que des lettres, des chiffres et des underscores.");
                            return $this->redirectToRoute('app_database_add_table', ['id' => $database->getId()]);
                        }
                        
                        $fields[$fieldName] = $fieldType;
                    }
                }
            }
            
            // Ajout de l'ID comme premier champ
            $tableFields = ['id' => 'integer'];
            foreach ($fields as $fieldName => $fieldType) {
                $tableFields[$fieldName] = $fieldType;
            }
            
            // Ajout de la table à la base de données
            $database->addTable($tableName, $tableFields);
            $database->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
            
            $this->addFlash('success', "Table '$tableName' créée avec succès.");
            return $this->redirectToRoute('app_database_show', ['id' => $database->getId()]);
        }

        return $this->render('database/add_table.html.twig', [
            'database' => $database,
            'form' => $form->createView(),
        ]);
    }

    /**
     * Détail d'une table
     */
    #[Route('/{id}/table/{tableName}', name: 'app_database_table_detail', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function tableDetail(Database $database, string $tableName): Response
    {
        $user = $this->getUser();
        if ($database->getUser() !== $user) {
            $this->addFlash('error', 'Vous n\'avez pas accès à cette base de données.');
            return $this->redirectToRoute('app_database_index');
        }

        $tables = $database->getTables() ?? [];
        if (!isset($tables[$tableName])) {
            $this->addFlash('error', "La table '$tableName' n'existe pas.");
            return $this->redirectToRoute('app_database_show', ['id' => $database->getId()]);
        }

        $tableData = $tables[$tableName];

        return $this->render('database/table_detail.html.twig', [
            'database' => $database,
            'tableName' => $tableName,
            'tableData' => $tableData,
        ]);
    }

    /**
     * Suppression d'une table
     */
    #[Route('/{id}/table/{tableName}/delete', name: 'app_database_delete_table', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function deleteTable(Request $request, Database $database, string $tableName): Response
    {
        $user = $this->getUser();
        if ($database->getUser() !== $user) {
            $this->addFlash('error', 'Vous n\'avez pas accès à cette base de données.');
            return $this->redirectToRoute('app_database_index');
        }

        if ($this->isCsrfTokenValid('delete-table-'.$tableName, $request->request->get('_token'))) {
            $tables = $database->getTables() ?? [];
            if (!isset($tables[$tableName])) {
                $this->addFlash('error', "La table '$tableName' n'existe pas.");
            } else {
                $database->removeTable($tableName);
                $this->entityManager->flush();
                $this->addFlash('success', "Table '$tableName' supprimée avec succès.");
            }
        }

        return $this->redirectToRoute('app_database_show', ['id' => $database->getId()]);
    }

    /**
     * Ajout d'un champ à une table
     */
    #[Route('/{id}/table/{tableName}/add-field', name: 'app_database_add_field', methods: ['GET', 'POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function addField(Request $request, Database $database, string $tableName): Response
    {
        $user = $this->getUser();
        if ($database->getUser() !== $user) {
            $this->addFlash('error', 'Vous n\'avez pas accès à cette base de données.');
            return $this->redirectToRoute('app_database_index');
        }

        $tables = $database->getTables() ?? [];
        if (!isset($tables[$tableName])) {
            $this->addFlash('error', "La table '$tableName' n'existe pas.");
            return $this->redirectToRoute('app_database_show', ['id' => $database->getId()]);
        }

        $tableData = $tables[$tableName];
        $existingFields = $tableData['fields'] ?? [];

        if ($request->isMethod('POST')) {
            $fieldName = $request->request->get('field_name');
            $fieldType = $request->request->get('field_type');

            // Validation du nom du champ
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $fieldName)) {
                $this->addFlash('error', 'Le nom du champ doit commencer par une lettre et ne contenir que des lettres, des chiffres et des underscores.');
                return $this->redirectToRoute('app_database_add_field', [
                    'id' => $database->getId(),
                    'tableName' => $tableName
                ]);
            }

            // Vérification si le champ existe déjà
            if (isset($tableData['fields'][$fieldName])) {
                $this->addFlash('error', "Un champ avec le nom '$fieldName' existe déjà dans cette table.");
                return $this->redirectToRoute('app_database_add_field', [
                    'id' => $database->getId(),
                    'tableName' => $tableName
                ]);
            }

            // Ajout du champ
            $database->addField($tableName, $fieldName, $fieldType);
            $this->entityManager->flush();

            $this->addFlash('success', "Champ '$fieldName' ajouté avec succès à la table '$tableName'.");
            return $this->redirectToRoute('app_database_table_detail', [
                'id' => $database->getId(),
                'tableName' => $tableName
            ]);
        }

        return $this->render('database/add_field.html.twig', [
            'database' => $database,
            'database_name' => $database->getName(),
            'database_id' => $database->getId(),
            'tableName' => $tableName,
            'table_name' => $tableName,
            'existing_fields' => $existingFields,
        ]);
    }

    /**
     * Ajout d'un enregistrement à une table
     */
    #[Route('/{id}/table/{tableName}/add-record', name: 'app_database_add_record', methods: ['GET', 'POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function addRecord(Request $request, Database $database, string $tableName): Response
    {
        $user = $this->getUser();
        if ($database->getUser() !== $user) {
            $this->addFlash('error', 'Vous n\'avez pas accès à cette base de données.');
            return $this->redirectToRoute('app_database_index');
        }

        $tables = $database->getTables() ?? [];
        if (!isset($tables[$tableName])) {
            $this->addFlash('error', "La table '$tableName' n'existe pas.");
            return $this->redirectToRoute('app_database_show', ['id' => $database->getId()]);
        }

        $tableData = $tables[$tableName];
        $fields = $tableData['fields'] ?? [];

        if ($request->isMethod('POST')) {
            // Fix: Use null as default and check if it's an array
            $recordData = $request->request->get('record', null);
            
            // Ensure $recordData is an array
            if (!is_array($recordData)) {
                $recordData = [];
            }
            
            // Validation des données
            foreach ($fields as $fieldName => $fieldType) {
                if (!isset($recordData[$fieldName]) && $fieldName !== 'id') {
                    $this->addFlash('error', "Le champ '$fieldName' est requis.");
                    return $this->redirectToRoute('app_database_add_record', [
                        'id' => $database->getId(),
                        'tableName' => $tableName
                    ]);
                }
                
                // Validation spécifique selon le type
                if ($fieldType === 'email' && !empty($recordData[$fieldName]) && !filter_var($recordData[$fieldName], FILTER_VALIDATE_EMAIL)) {
                    $this->addFlash('error', "Le champ '$fieldName' doit être une adresse email valide.");
                    return $this->redirectToRoute('app_database_add_record', [
                        'id' => $database->getId(),
                        'tableName' => $tableName
                    ]);
                }
                
                if ($fieldType === 'url' && !empty($recordData[$fieldName]) && !filter_var($recordData[$fieldName], FILTER_VALIDATE_URL)) {
                    $this->addFlash('error', "Le champ '$fieldName' doit être une URL valide.");
                    return $this->redirectToRoute('app_database_add_record', [
                        'id' => $database->getId(),
                        'tableName' => $tableName
                    ]);
                }
            }
            
            // Ajout de l'enregistrement
            $database->addRecord($tableName, $recordData);
            $this->entityManager->flush();
            
            $this->addFlash('success', 'Enregistrement ajouté avec succès.');
            return $this->redirectToRoute('app_database_table_detail', [
                'id' => $database->getId(),
                'tableName' => $tableName
            ]);
        }

        return $this->render('database/add_record.html.twig', [
            'database' => $database,
            'database_name' => $database->getName(),
            'database_id' => $database->getId(),
            'tableName' => $tableName,
            'table_name' => $tableName,
            'fields' => $fields,
        ]);
    }

    /**
     * Édition d'un enregistrement dans une table
     */
    #[Route('/{id}/table/{tableName}/edit-record/{recordId}', name: 'app_database_edit_record', methods: ['GET', 'POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function editRecord(Request $request, Database $database, string $tableName, int $recordId): Response
    {
        $user = $this->getUser();
        if ($database->getUser() !== $user) {
            $this->addFlash('error', 'Vous n\'avez pas accès à cette base de données.');
            return $this->redirectToRoute('app_database_index');
        }

        $tables = $database->getTables() ?? [];
        if (!isset($tables[$tableName])) {
            $this->addFlash('error', "La table '$tableName' n'existe pas.");
            return $this->redirectToRoute('app_database_show', ['id' => $database->getId()]);
        }

        $tableData = $tables[$tableName];
        $record = null;
        $recordIndex = null;
        
        // Recherche de l'enregistrement par son ID
        foreach ($tableData['records'] as $index => $r) {
            if (isset($r['id']) && $r['id'] == $recordId) {
                $record = $r;
                $recordIndex = $index;
                break;
            }
        }
        
        if ($record === null) {
            $this->addFlash('error', "L'enregistrement #$recordId n'existe pas dans la table '$tableName'.");
            return $this->redirectToRoute('app_database_table_detail', [
                'id' => $database->getId(),
                'tableName' => $tableName
            ]);
        }

        if ($request->isMethod('POST')) {
            // Fix: Use null as default and check if it's an array
            $updatedRecord = $request->request->get('record', null);
            
            // Ensure $updatedRecord is an array
            if (!is_array($updatedRecord)) {
                $updatedRecord = [];
            }
            
            // Validation des données
            foreach ($tableData['fields'] as $fieldName => $fieldType) {
                if (!isset($updatedRecord[$fieldName]) && $fieldName !== 'id') {
                    $this->addFlash('error', "Le champ '$fieldName' est requis.");
                    return $this->redirectToRoute('app_database_edit_record', [
                        'id' => $database->getId(),
                        'tableName' => $tableName,
                        'recordId' => $recordId
                    ]);
                }
                
                // Validation spécifique selon le type
                if ($fieldType === 'email' && !filter_var($updatedRecord[$fieldName], FILTER_VALIDATE_EMAIL)) {
                    $this->addFlash('error', "Le champ '$fieldName' doit être une adresse email valide.");
                    return $this->redirectToRoute('app_database_edit_record', [
                        'id' => $database->getId(),
                        'tableName' => $tableName,
                        'recordId' => $recordId
                    ]);
                }
                
                if ($fieldType === 'url' && !filter_var($updatedRecord[$fieldName], FILTER_VALIDATE_URL)) {
                    $this->addFlash('error', "Le champ '$fieldName' doit être une URL valide.");
                    return $this->redirectToRoute('app_database_edit_record', [
                        'id' => $database->getId(),
                        'tableName' => $tableName,
                        'recordId' => $recordId
                    ]);
                }
            }
            
            // Préservation de l'ID
            $updatedRecord['id'] = $recordId;
            
            // Mise à jour de l'enregistrement
            $database->updateRecord($tableName, $recordIndex, $updatedRecord);
            $this->entityManager->flush();
            
            $this->addFlash('success', 'Enregistrement mis à jour avec succès.');
            return $this->redirectToRoute('app_database_table_detail', [
                'id' => $database->getId(),
                'tableName' => $tableName
            ]);
        }

        // Préparation des données pour le formulaire
        $formData = [];
        foreach ($tableData['fields'] as $fieldName => $fieldType) {
            $formData[$fieldName] = [
                'type' => $fieldType,
                'value' => $record[$fieldName] ?? ''
            ];
        }

        return $this->render('database/edit_record.html.twig', [
            'database' => $database,
            'database_name' => $database->getName(),
            'database_id' => $database->getId(),
            'tableName' => $tableName,
            'table_name' => $tableName,
            'record_id' => $recordId,
            'form_data' => $formData,
        ]);
    }

    /**
     * Suppression d'un enregistrement
     */
    #[Route('/{id}/table/{tableName}/delete-record/{recordId}', name: 'app_database_delete_record', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function deleteRecord(Request $request, Database $database, string $tableName, int $recordId): Response
    {
        $user = $this->getUser();
        if ($database->getUser() !== $user) {
            $this->addFlash('error', 'Vous n\'avez pas accès à cette base de données.');
            return $this->redirectToRoute('app_database_index');
        }

        if ($this->isCsrfTokenValid('delete-record-'.$recordId, $request->request->get('_token'))) {
            $tables = $database->getTables() ?? [];
            if (!isset($tables[$tableName])) {
                $this->addFlash('error', "La table '$tableName' n'existe pas.");
                return $this->redirectToRoute('app_database_show', ['id' => $database->getId()]);
            }

            $tableData = $tables[$tableName];
            $recordIndex = null;
            
            // Recherche de l'enregistrement par son ID
            foreach ($tableData['records'] as $index => $record) {
                if (isset($record['id']) && $record['id'] == $recordId) {
                    $recordIndex = $index;
                    break;
                }
            }
            
            if ($recordIndex === null) {
                $this->addFlash('error', "L'enregistrement #$recordId n'existe pas dans la table '$tableName'.");
            } else {
                $database->removeRecord($tableName, $recordIndex);
                $this->entityManager->flush();
                $this->addFlash('success', 'Enregistrement supprimé avec succès.');
            }
        }

        return $this->redirectToRoute('app_database_table_detail', [
            'id' => $database->getId(),
            'tableName' => $tableName
        ]);
    }

    /**
     * Exporte une base de données au format SQL
     */
    #[Route('/{id}/export', name: 'app_database_export', methods: ['GET'])]  
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function exportDatabase(Database $database): Response
    {
        $user = $this->getUser();
        if ($database->getUser() !== $user) {
            $this->addFlash('error', 'Vous n\'avez pas accès à cette base de données.');
            return $this->redirectToRoute('app_database_index');
        }

        // Utiliser le service DatabaseService pour générer le SQL
        $databaseService = $this->container->get('App\Service\DatabaseService');
        $sql = $databaseService->generateSqlSchema($database);

        // Ajouter les données (INSERT statements) si nécessaire
        $tables = $database->getTables() ?? [];
        foreach ($tables as $tableName => $tableData) {
            if (isset($tableData['records']) && !empty($tableData['records'])) {
                $sql .= "\n-- Données pour la table {$tableName}\n";
                
                foreach ($tableData['records'] as $record) {
                    $fields = array_keys($record);
                    $values = array_map(function($value) {
                        if (is_string($value)) {
                            return "'" . addslashes($value) . "'";
                        } elseif (is_bool($value)) {
                            return $value ? '1' : '0';
                        } elseif (is_null($value)) {
                            return 'NULL';
                        } else {
                            return $value;
                        }
                    }, array_values($record));
                    
                    $sql .= "INSERT INTO {$tableName} (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $values) . ");\n";
                }
            }
        }

        // Créer une réponse avec le contenu SQL
        $response = new Response($sql);
        $response->headers->set('Content-Type', 'text/plain');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $database->getName() . '.sql"');

        return $response;
    }

    /**
     * Suppression d'un champ d'une table
     */
    #[Route('/{id}/table/{tableName}/delete-field/{fieldName}', name: 'app_database_delete_field', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function deleteField(Request $request, Database $database, string $tableName, string $fieldName): Response
    {
        $user = $this->getUser();
        if ($database->getUser() !== $user) {
            $this->addFlash('error', 'Vous n\'avez pas accès à cette base de données.');
            return $this->redirectToRoute('app_database_index');
        }

        if ($this->isCsrfTokenValid('delete-field-'.$fieldName, $request->request->get('_token'))) {
            $tables = $database->getTables() ?? [];
            if (!isset($tables[$tableName])) {
                $this->addFlash('error', "La table '$tableName' n'existe pas.");
                return $this->redirectToRoute('app_database_show', ['id' => $database->getId()]);
            }

            if ($fieldName === 'id') {
                $this->addFlash('error', "Le champ 'id' ne peut pas être supprimé.");
                return $this->redirectToRoute('app_database_table_detail', [
                    'id' => $database->getId(),
                    'tableName' => $tableName
                ]);
            }

            $tableData = $tables[$tableName];
            if (!isset($tableData['fields'][$fieldName])) {
                $this->addFlash('error', "Le champ '$fieldName' n'existe pas dans la table '$tableName'.");
            } else {
                $database->removeField($tableName, $fieldName);
                $this->entityManager->flush();
                $this->addFlash('success', "Champ '$fieldName' supprimé avec succès.");
            }
        }

        return $this->redirectToRoute('app_database_table_detail', [
            'id' => $database->getId(),
            'tableName' => $tableName
        ]);
    }
}