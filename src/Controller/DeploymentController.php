<?php

namespace App\Controller;

use App\Entity\Prompt;
use App\Form\DomainType;
use App\Service\FtpService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class DeploymentController extends AbstractController
{
    #[Route('/deploy/{promptId}', name: 'app_deploy_site', methods: ['POST'])]
    public function deploy(
        string $promptId,
        FtpService $ftpService,
        EntityManagerInterface $em,
        Request $request
    ): Response {
        $prompt = $em->getRepository(Prompt::class)->find($promptId);

        if (!$prompt || $prompt->getUser() !== $this->getUser()) {
            $this->addFlash('error', 'Accès refusé ou prompt introuvable.');
            return $this->redirectToRoute('app_my_sites');
        }

        $form = $this->createForm(DomainType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $formData = $form->getData();
            $domainName = trim($formData['domainName']);
            $domainExtension = $formData['extension'];
        }

        $fullDomain = $domainName . $domainExtension;

        $prompt->setDomainName($fullDomain);
        $prompt->setDeployed(true);

        $em->flush();

        $result = $ftpService->deploySite($prompt);

        if (!$result['success']) {
            $this->addFlash('error', $result['error'] ?? 'Erreur lors du déploiement');
            return $this->redirectToRoute('app_my_sites');
        }

        return $this->render('deployment/success.html.twig', [
            'domain' => $fullDomain
        ]);
    }

}