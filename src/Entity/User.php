<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;
    
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $contactEmail = null;
    
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $preferredStyle = null;
    
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $businessType = null;
    
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $colorScheme = null;
    
    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $additionalPreferences = null;

    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isSubscribed = false;

    #[ORM\Column(type: 'integer')]
    private int $count = 0;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Prompt::class, orphanRemoval: true)]
    private Collection $prompts;
    
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: ModelMaker::class, orphanRemoval: true)]
    private Collection $modelMakers;

    public function __construct()
    {
        $this->prompts = new ArrayCollection();
        $this->modelMakers = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;
        return $this;
    }

    public function isSubscribed(): bool
    {
        return $this->isSubscribed;
    }

    public function setIsSubscribed(bool $isSubscribed): self
    {
        $this->isSubscribed = $isSubscribed;

        return $this;
    }

    public function eraseCredentials(): void
    {
    }

    public function getCount(): int
    {
        return $this->count;
    }

    public function setCount(int $count): self
    {
        $this->count = $count;
        return $this;
    }
    
    public function getContactEmail(): ?string
    {
        return $this->contactEmail;
    }
    
    public function setContactEmail(?string $contactEmail): self
    {
        $this->contactEmail = $contactEmail;
        return $this;
    }
    
    public function getPreferredStyle(): ?string
    {
        return $this->preferredStyle;
    }
    
    public function setPreferredStyle(?string $preferredStyle): self
    {
        $this->preferredStyle = $preferredStyle;
        return $this;
    }
    
    public function getBusinessType(): ?string
    {
        return $this->businessType;
    }
    
    public function setBusinessType(?string $businessType): self
    {
        $this->businessType = $businessType;
        return $this;
    }
    
    public function getColorScheme(): ?string
    {
        return $this->colorScheme;
    }
    
    public function setColorScheme(?string $colorScheme): self
    {
        $this->colorScheme = $colorScheme;
        return $this;
    }
    
    public function getAdditionalPreferences(): ?string
    {
        return $this->additionalPreferences;
    }
    
    public function setAdditionalPreferences(?string $additionalPreferences): self
    {
        $this->additionalPreferences = $additionalPreferences;
        return $this;
    }

    /**
     * @return Collection<int, Prompt>
     */
    public function getPrompts(): Collection
    {
        return $this->prompts;
    }

    public function addPrompt(Prompt $prompt): static
    {
        if (!$this->prompts->contains($prompt)) {
            $this->prompts->add($prompt);
            $prompt->setUser($this);
        }
        return $this;
    }

    public function removePrompt(Prompt $prompt): static
    {
        if ($this->prompts->removeElement($prompt)) {
            if ($prompt->getUser() === $this) {
                $prompt->setUser(null);
            }
        }
        return $this;
    }
    
    /**
     * @return Collection<int, ModelMaker>
     */
    public function getModelMakers(): Collection
    {
        return $this->modelMakers;
    }

    public function addModelMaker(ModelMaker $modelMaker): static
    {
        if (!$this->modelMakers->contains($modelMaker)) {
            $this->modelMakers->add($modelMaker);
            $modelMaker->setUser($this);
        }

        return $this;
    }

    public function removeModelMaker(ModelMaker $modelMaker): static
    {
        if ($this->modelMakers->removeElement($modelMaker)) {
            // set the owning side to null (unless already changed)
            if ($modelMaker->getUser() === $this) {
                $modelMaker->setUser(null);
            }
        }

        return $this;
    }
}