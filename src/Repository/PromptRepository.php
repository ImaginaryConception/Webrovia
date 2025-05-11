<?php

namespace App\Repository;

use App\Entity\Prompt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Prompt>
 *
 * @method Prompt|null find($id, $lockMode = null, $lockVersion = null)
 * @method Prompt|null findOneBy(array $criteria, array $orderBy = null)
 * @method Prompt[]    findAll()
 * @method Prompt[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PromptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Prompt::class);
    }

    public function findLatestByUser($user, $limit = 10)
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.user = :user')
            ->setParameter('user', $user)
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult()
        ;
    }

    public function findAllOrderedByDate($limit = 50)
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult()
        ;
    }
}