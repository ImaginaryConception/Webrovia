<?php

namespace App\Repository;

use App\Entity\Database;
use App\Entity\Prompt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Database>
 *
 * @method Database|null find($id, $lockMode = null, $lockVersion = null)
 * @method Database|null findOneBy(array $criteria, array $orderBy = null)
 * @method Database[]    findAll()
 * @method Database[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class DatabaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Database::class);
    }

    /**
     * Trouve une base de données par son prompt
     */
    public function findByPrompt(Prompt $prompt): ?Database
    {
        return $this->findOneBy(['prompt' => $prompt]);
    }

    /**
     * Trouve toutes les bases de données d'un utilisateur
     */
    public function findByUser(int $userId): array
    {
        return $this->createQueryBuilder('d')
            ->join('d.prompt', 'p')
            ->join('p.user', 'u')
            ->where('u.id = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Sauvegarde une entité Database
     */
    public function save(Database $database, bool $flush = true): void
    {
        $this->getEntityManager()->persist($database);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Supprime une entité Database
     */
    public function remove(Database $database, bool $flush = true): void
    {
        $this->getEntityManager()->remove($database);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}