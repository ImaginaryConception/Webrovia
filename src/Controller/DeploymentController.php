<?php

namespace App\Controller;

use App\Entity\Prompt;
use App\Service\FtpService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class DeploymentController extends AbstractController
{
    #[Route('/deploy/{promptId}', name: 'app_deploy_site')]
    public function deploy(string $promptId, FtpService $ftpService, EntityManagerInterface $entityManager, ManagerRegistry $doctrine, Request $request): Response
    {
        // Récupération du nom de domaine et de l'extension depuis la requête
        $domainName = $request->query->get('domain_name');
        $domainExtension = $request->query->get('domain_extension');

        $domain = $domainName . $domainExtension;

        // Récupération de l'entité Prompt
        $prompt = $entityManager->getRepository(Prompt::class)->find($promptId);

        if (!$prompt) {
            $this->addFlash('error', 'Le prompt demandé n\'existe pas');
            return $this->redirectToRoute('app_my_sites');
        }

        // Vérification des fichiers générés
        $generatedFiles = $prompt->getGeneratedFiles();
        if (!$generatedFiles) {
            $this->addFlash('error', 'Aucun fichier généré n\'a été trouvé pour ce site');
            return $this->redirectToRoute('app_my_sites');
        }

        // Utilisation directe des fichiers générés stockés dans l'entité
        $files = $generatedFiles;

        $result = $ftpService->deploySite($prompt);

        if (!$result['success']) {
            $this->addFlash('error', $result['error'] ?? 'Une erreur est survenue lors du déploiement');
            return $this->redirectToRoute('app_my_sites');
        }

        // Marquer le site comme déployé
        $prompt->setDeployed(true);
        $prompt->setDomainName($domain);
        $em = $doctrine->getManager();
        $em->persist($prompt);
        $em->flush();

        return $this->render('deployment/success.html.twig', [
            'domain' => $domain
        ]);
    }
}