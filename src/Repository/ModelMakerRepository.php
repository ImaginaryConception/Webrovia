<?php

namespace App\Repository;

use App\Entity\ModelMaker;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ModelMaker>
 *
 * @method ModelMaker|null find($id, $lockMode = null, $lockVersion = null)
 * @method ModelMaker|null findOneBy(array $criteria, array $orderBy = null)
 * @method ModelMaker[]    findAll()
 * @method ModelMaker[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ModelMakerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ModelMaker::class);
    }

    /**
     * Trouve tous les modèles d'un utilisateur, triés par date de création décroissante
     */
    public function findByUserOrderedByDate($user)
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.user = :user')
            ->setParameter('user', $user)
            ->orderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les derniers modèles créés par un utilisateur
     */
    public function findLatestByUser($user, $limit = 5)
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.user = :user')
            ->setParameter('user', $user)
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}