<?php

namespace App\Repository;

use App\Entity\WebsiteClone;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WebsiteClone>
 * @method WebsiteClone|null find($id, $lockMode = null, $lockVersion = null)
 * @method WebsiteClone|null findOneBy(array $criteria, array $orderBy = null)
 * @method WebsiteClone[]    findAll()
 * @method WebsiteClone[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CloneRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebsiteClone::class);
    }

    public function save(WebsiteClone $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(WebsiteClone $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}