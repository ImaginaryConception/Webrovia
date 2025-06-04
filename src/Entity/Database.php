<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Repository\DatabaseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Entity\User;

#[ORM\Entity(repositoryClass: DatabaseRepository::class)]
#[ORM\Table(name: 'app_database')] 
#[ORM\HasLifecycleCallbacks]
class Database
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $tables = null;

    #[ORM\Column]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\ManyToOne(inversedBy: 'databases')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Prompt $prompt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->tables = [];
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getTables(): ?array
    {
        return $this->tables;
    }

    public function setTables(?array $tables): static
    {
        $this->tables = $tables;
        return $this;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getPrompt(): ?Prompt
    {
        return $this->prompt;
    }

    public function setPrompt(?Prompt $prompt): static
    {
        $this->prompt = $prompt;
        return $this;
    }
    
    /**
     * Récupère l'utilisateur propriétaire de la base de données via le prompt associé
     */
    public function getUser(): ?User
    {
        return $this->prompt ? $this->prompt->getUser() : null;
    }

    /**
     * Ajoute une table à la base de données
     */
    public function addTable(string $tableName, array $fields = []): static
    {
        if (!$this->tables) {
            $this->tables = [];
        }

        $this->tables[$tableName] = [
            'fields' => $fields,
            'records' => []
        ];

        return $this;
    }

    /**
     * Supprime une table de la base de données
     */
    public function removeTable(string $tableName): static
    {
        if ($this->tables && isset($this->tables[$tableName])) {
            unset($this->tables[$tableName]);
        }

        return $this;
    }

    /**
     * Ajoute un champ à une table
     */
    public function addField(string $tableName, string $fieldName, string $fieldType): static
    {
        if ($this->tables && isset($this->tables[$tableName])) {
            $this->tables[$tableName]['fields'][$fieldName] = $fieldType;
        }

        return $this;
    }

    /**
     * Supprime un champ d'une table
     */
    public function removeField(string $tableName, string $fieldName): static
    {
        if ($this->tables && isset($this->tables[$tableName]) && isset($this->tables[$tableName]['fields'][$fieldName])) {
            unset($this->tables[$tableName]['fields'][$fieldName]);
        }

        return $this;
    }

    /**
     * Ajoute un enregistrement à une table
     */
    public function addRecord(string $tableName, array $record): static
    {
        if ($this->tables && isset($this->tables[$tableName])) {
            $this->tables[$tableName]['records'][] = $record;
        }

        return $this;
    }

    /**
     * Met à jour un enregistrement dans une table
     */
    public function updateRecord(string $tableName, int $recordIndex, array $record): static
    {
        if ($this->tables && isset($this->tables[$tableName]) && 
            isset($this->tables[$tableName]['records'][$recordIndex])) {
            $this->tables[$tableName]['records'][$recordIndex] = $record;
        }

        return $this;
    }

    /**
     * Supprime un enregistrement d'une table
     */
    public function removeRecord(string $tableName, int $recordIndex): static
    {
        if ($this->tables && isset($this->tables[$tableName]) && 
            isset($this->tables[$tableName]['records'][$recordIndex])) {
            array_splice($this->tables[$tableName]['records'], $recordIndex, 1);
        }

        return $this;
    }
}