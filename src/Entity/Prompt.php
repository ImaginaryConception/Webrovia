<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Entity\Database;
use App\Repository\PromptRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

#[ORM\Entity(repositoryClass: PromptRepository::class)]
class Prompt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $content = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $websiteType = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $features = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $generatedFiles = null;

    #[ORM\Column]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\ManyToOne(inversedBy: 'prompts')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 255)]
    private ?string $status = 'pending';

    #[ORM\Column(type: Types::BOOLEAN, options: ["default" => false])]
    private bool $deployed = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $modificationRequest = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $domainName = null;

    #[ORM\Column(type: Types::INTEGER, options: ["default" => 1])]
    private int $version = 1;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $websiteIdentification = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $generationMessage = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'modifications')]
    private ?self $originalPrompt = null;

    #[ORM\OneToMany(mappedBy: 'originalPrompt', targetEntity: self::class)]
    private Collection $modifications;

    #[ORM\OneToMany(mappedBy: 'prompt', targetEntity: Database::class, orphanRemoval: true)]
    private Collection $databases;

    /**
     * @return Collection<int, self>
     */
    public function getModifications(): Collection
    {
        return $this->modifications;
    }

    public function addModification(self $modification): static
    {
        if (!$this->modifications->contains($modification)) {
            $this->modifications->add($modification);
            $modification->setOriginalPrompt($this);
        }

        return $this;
    }

    public function removeModification(self $modification): static
    {
        if ($this->modifications->removeElement($modification)) {
            // set the owning side to null (unless already changed)
            if ($modification->getOriginalPrompt() === $this) {
                $modification->setOriginalPrompt(null);
            }
        }

        return $this;
    }

    public function __construct()
    {
        $this->databases = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
        $this->modifications = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;
        return $this;
    }

    public function getWebsiteType(): ?string
    {
        return $this->websiteType;
    }

    public function setWebsiteType(string $websiteType): static
    {
        $this->websiteType = $websiteType;
        return $this;
    }

    public function getFeatures(): ?array
    {
        return $this->features;
    }

    public function setFeatures(?array $features): static
    {
        $this->features = $features;
        return $this;
    }

    public function getGeneratedFiles(): ?array
    {
        return $this->generatedFiles;
    }

    public function setGeneratedFiles(?array $generatedFiles): static
    {

        // S'assurer que les fichiers HTML sont correctement traités comme des chaînes
        // et non comme du JSON potentiellement mal formé
        $this->generatedFiles = $generatedFiles;
        return $this;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): static
    {
        $this->error = $error;
        return $this;
    }

    public function getModificationRequest(): ?string
    {
        return $this->modificationRequest;
    }

    public function setModificationRequest(?string $modificationRequest): static
    {
        $this->modificationRequest = $modificationRequest;
        return $this;
    }

    public function getDomainName(): ?string
    {
        return $this->domainName;
    }

    public function setDomainName(?string $domainName): static
    {
        $this->domainName = $domainName;
        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): static
    {
        $this->version = $version;
        return $this;
    }

    /**
     * Récupère l'historique des versions du prompt
     * @return Collection<int, self>
     */
    public function getVersions(): Collection
    {
        if ($this->originalPrompt) {
            // Si c'est une modification, retourner les versions de l'original
            return $this->originalPrompt->getVersions();
        }
        // Si c'est l'original, retourner ses modifications
        return $this->modifications;
    }

    public function incrementVersion(): static
    {
        $this->version++;
        return $this;
    }

    public function getWebsiteIdentification(): ?string
    {
        return $this->websiteIdentification;
    }

    public function setWebsiteIdentification(?string $websiteIdentification): static
    {
        $this->websiteIdentification = $websiteIdentification;
        return $this;
    }

    public function getOriginalPrompt(): ?self
    {
        return $this->originalPrompt;
    }

    public function setOriginalPrompt(?self $originalPrompt): static
    {
        $this->originalPrompt = $originalPrompt;
        return $this;
    }



    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function isDeployed(): bool
    {
        return $this->deployed;
    }

    public function setDeployed(bool $deployed): static
    {
        $this->deployed = $deployed;
        return $this;
    }

    public function getGenerationMessage(): ?string
    {
        return $this->generationMessage;
    }

    public function setGenerationMessage(?string $generationMessage): static
    {
        $this->generationMessage = $generationMessage;
        return $this;
    }

    /**
     * @return Collection<int, Database>
     */
    public function getDatabases(): Collection
    {
        return $this->databases;
    }

    public function addDatabase(Database $database): static
    {
        if (!$this->databases->contains($database)) {
            $this->databases->add($database);
            $database->setPrompt($this);
        }

        return $this;
    }

    public function removeDatabase(Database $database): static
    {
        if ($this->databases->removeElement($database)) {
            // set the owning side to null (unless already changed)
            if ($database->getPrompt() === $this) {
                $database->setPrompt(null);
            }
        }

        return $this;
    }

    /**
     * Retourne une représentation en chaîne de caractères de l'objet Prompt
     */
    public function __toString(): string
    {
        return $this->websiteType ?? 'Prompt #' . $this->id;
    }
}