<?php

namespace App\Service;

use App\Entity\Database;
use App\Entity\Prompt;
use App\Repository\DatabaseRepository;
use Doctrine\ORM\EntityManagerInterface;

class DatabaseService
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
     * Crée une nouvelle base de données pour un prompt
     */
    public function createDatabase(Prompt $prompt, string $name = null): Database
    {
        // Vérifier si une base de données existe déjà pour ce prompt
        $existingDatabase = $this->databaseRepository->findByPrompt($prompt);
        
        if ($existingDatabase) {
            return $existingDatabase;
        }
        
        // Créer une nouvelle base de données
        $database = new Database();
        $database->setName($name ?? 'database_' . $prompt->getId());
        $database->setPrompt($prompt);
        
        $this->entityManager->persist($database);
        $this->entityManager->flush();
        
        return $database;
    }

    /**
     * Récupère une base de données par son ID
     */
    public function getDatabaseById(int $id): ?Database
    {
        return $this->databaseRepository->find($id);
    }

    /**
     * Récupère une base de données par son prompt
     */
    public function getDatabaseByPrompt(Prompt $prompt): ?Database
    {
        return $this->databaseRepository->findByPrompt($prompt);
    }

    /**
     * Ajoute une table à une base de données
     */
    public function addTable(Database $database, string $tableName, array $fields = []): Database
    {
        $database->addTable($tableName, $fields);
        $this->entityManager->persist($database);
        $this->entityManager->flush();
        
        return $database;
    }

    /**
     * Supprime une table d'une base de données
     */
    public function removeTable(Database $database, string $tableName): Database
    {
        $database->removeTable($tableName);
        $this->entityManager->persist($database);
        $this->entityManager->flush();
        
        return $database;
    }

    /**
     * Ajoute un champ à une table
     */
    public function addField(Database $database, string $tableName, string $fieldName, string $fieldType): Database
    {
        $database->addField($tableName, $fieldName, $fieldType);
        $this->entityManager->persist($database);
        $this->entityManager->flush();
        
        return $database;
    }

    /**
     * Supprime un champ d'une table
     */
    public function removeField(Database $database, string $tableName, string $fieldName): Database
    {
        $database->removeField($tableName, $fieldName);
        $this->entityManager->persist($database);
        $this->entityManager->flush();
        
        return $database;
    }

    /**
     * Ajoute un enregistrement à une table
     */
    public function addRecord(Database $database, string $tableName, array $record): Database
    {
        $database->addRecord($tableName, $record);
        $this->entityManager->persist($database);
        $this->entityManager->flush();
        
        return $database;
    }

    /**
     * Met à jour un enregistrement dans une table
     */
    public function updateRecord(Database $database, string $tableName, int $recordIndex, array $record): Database
    {
        $database->updateRecord($tableName, $recordIndex, $record);
        $this->entityManager->persist($database);
        $this->entityManager->flush();
        
        return $database;
    }

    /**
     * Supprime un enregistrement d'une table
     */
    public function removeRecord(Database $database, string $tableName, int $recordIndex): Database
    {
        $database->removeRecord($tableName, $recordIndex);
        $this->entityManager->persist($database);
        $this->entityManager->flush();
        
        return $database;
    }

    /**
     * Génère le code SQL pour créer les tables de la base de données
     */
    public function generateSqlSchema(Database $database): string
    {
        $sql = '';
        $tables = $database->getTables() ?? [];
        
        foreach ($tables as $tableName => $tableData) {
            $sql .= "CREATE TABLE {$tableName} (\n";
            
            $fields = $tableData['fields'] ?? [];
            $fieldsSql = [];
            
            foreach ($fields as $fieldName => $fieldType) {
                $sqlType = $this->mapPhpTypeToSqlType($fieldType);
                $fieldsSql[] = "  {$fieldName} {$sqlType}";
            }
            
            // Ajouter une clé primaire si 'id' existe
            if (isset($fields['id'])) {
                $fieldsSql[] = "  PRIMARY KEY (id)";
            }
            
            $sql .= implode(",\n", $fieldsSql);
            $sql .= "\n);\n\n";
        }
        
        return $sql;
    }

    /**
     * Mappe un type PHP à un type SQL
     */
    private function mapPhpTypeToSqlType(string $phpType): string
    {
        return match ($phpType) {
            'integer' => 'INTEGER',
            'float' => 'FLOAT',
            'boolean' => 'BOOLEAN',
            'datetime' => 'DATETIME',
            'date' => 'DATE',
            'time' => 'TIME',
            'text' => 'TEXT',
            'email' => 'VARCHAR(255)',
            'url' => 'VARCHAR(255)',
            default => 'VARCHAR(255)',
        };
    }
    
    /**
     * Récupère la liste des tables d'une base de données
     */
    public function getTables(Database $database): array
    {
        $tables = $database->getTables() ?? [];
        return array_keys($tables);
    }
    
    /**
     * Récupère les données d'une table
     */
    public function getTableData(Database $database, string $tableName): array
    {
        $tables = $database->getTables() ?? [];
        
        if (!isset($tables[$tableName])) {
            return ['fields' => [], 'records' => []];
        }
        
        return $tables[$tableName];
    }
    
    /**
     * Exporte les données d'une table au format CSV
     */
    public function exportTableToCsv(array $tableData): string
    {
        $fields = $tableData['fields'] ?? [];
        $records = $tableData['records'] ?? [];
        
        if (empty($fields)) {
            return '';
        }
        
        $csv = fopen('php://temp', 'r+');
        
        // En-têtes des colonnes
        fputcsv($csv, array_keys($fields));
        
        // Données
        foreach ($records as $record) {
            $row = [];
            foreach (array_keys($fields) as $fieldName) {
                $value = $record[$fieldName] ?? '';
                
                // Conversion des booléens en chaînes
                if (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                }
                
                // Conversion des dates en chaînes
                if ($value instanceof \DateTime) {
                    $value = $value->format('Y-m-d H:i:s');
                }
                
                $row[] = $value;
            }
            
            fputcsv($csv, $row);
        }
        
        rewind($csv);
        $content = stream_get_contents($csv);
        fclose($csv);
        
        return $content;
    }
}