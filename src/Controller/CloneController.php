<?php

namespace App\Controller;

use Exception;
use App\Form\CloneType;
use App\Form\DomainType;
use App\Entity\WebsiteClone;
use App\Service\CloneService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class CloneController extends AbstractController
{
    #[Route('/clone', name: 'app_clone')]
    public function index(Request $request): Response
    {
        $clone = new WebsiteClone();
        $form = $this->createForm(CloneType::class, $clone);

        return $this->render('clone/index.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/my-clones', name: 'app_my_clones')]
    #[IsGranted('ROLE_USER')]
    public function myClones(Request $request, EntityManagerInterface $em): Response
    {
        $clones = $em->getRepository(WebsiteClone::class)->findBy(
            ['user' => $this->getUser()],
            ['createdAt' => 'DESC']
        );

        $domainForm = $this->createForm(DomainType::class);
        $domainForm->handleRequest($request);

        if ($domainForm->isSubmitted() && $domainForm->isValid()) {
            $data = $domainForm->getData();
            $domainName = $data['domainName'] . $data['extension'];
            
            $this->addFlash('success', 'Le nom de domaine ' . $domainName . ' a été enregistré avec succès.');
            return $this->redirectToRoute('app_my_clones');
        }

        return $this->render('clone/my_clones.html.twig', [
            'clones' => $clones,
            'domain_form' => $domainForm->createView()
        ]);
    }

    #[Route('/preview-clone/{id}', name: 'app_preview_clone', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function previewClone(Request $request, EntityManagerInterface $em, int $id): Response
    {
        try {
            $path = $request->query->get('path');
            if (!$path) {
                throw new Exception('Le chemin du fichier est requis');
            }

            $clone = $em->getRepository(WebsiteClone::class)->find($id);
            if (!$clone || $clone->getUser() !== $this->getUser()) {
                throw new Exception('Accès non autorisé');
            }

            $files = $clone->getFiles();
            if (!isset($files[$path])) {
                throw new Exception('Fichier non trouvé');
            }

            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $contentType = match($extension) {
                'css' => 'text/css',
                'js' => 'application/javascript',
                'json' => 'application/json',
                'svg' => 'image/svg+xml',
                default => 'text/html',
            };

            return new Response(
                $files[$path],
                Response::HTTP_OK,
                ['Content-Type' => $contentType]
            );

        } catch (Exception $e) {
            return new Response(
                sprintf('<div class="error">%s</div>', htmlspecialchars($e->getMessage())),
                Response::HTTP_BAD_REQUEST,
                ['Content-Type' => 'text/html']
            );
        }
    }

    #[Route('/deploy-clone/{cloneId}', name: 'app_deploy_clone', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function deployClone(Request $request, EntityManagerInterface $em, int $cloneId): Response
    {
        $clone = $em->getRepository(WebsiteClone::class)->find($cloneId);
        if (!$clone || $clone->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $domainForm = $this->createForm(DomainType::class);
        $domainForm->handleRequest($request);

        if ($domainForm->isSubmitted() && $domainForm->isValid()) {
            $data = $domainForm->getData();
            $domainName = $data['domainName'] . $data['extension'];
            $clone->setDomainName($domainName);
            $clone->setDeployed(true);
            $em->flush();

            $this->addFlash('success', 'Le site a été déployé avec succès sur ' . $domainName);
        } else {
            $this->addFlash('error', 'Une erreur est survenue lors du déploiement');
        }

        return $this->redirectToRoute('app_my_clones');
    }

    #[Route('/clone/generate', name: 'app_clone_generate', methods: ['POST'])]
    public function generate(Request $request, EntityManagerInterface $em, CloneService $cloneService): JsonResponse
    {
        $clone = new WebsiteClone();
        $form = $this->createForm(CloneType::class, $clone);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $clone->setUser($this->getUser());
                $clone->setStatus('processing');
                $em->persist($clone);
                $em->flush();

                return new JsonResponse([
                    'success' => true,
                    'data' => ['id' => $clone->getId()],
                ]);
            } catch (\Exception $e) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Une erreur est survenue lors de l\'initialisation du clonage.',
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        return new JsonResponse([
            'success' => false,
            'error' => 'Formulaire invalide',
        ], Response::HTTP_BAD_REQUEST);
    }

    #[Route('/clone/{id}', name: 'app_clone_status', methods: ['GET'])]
    public function status(WebsiteClone $clone, EntityManagerInterface $em, CloneService $cloneService): JsonResponse
    {
        if ($clone->getUser() !== $this->getUser()) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Accès non autorisé',
            ], Response::HTTP_FORBIDDEN);
        }

        if ($clone->getStatus() === 'processing') {
            try {
                $files = $cloneService->makeRequest($clone->getSourceUrl());
                $clone->setGeneratedFiles($files);
                $clone->setStatus('completed');
                $em->flush();

                return new JsonResponse([
                    'status' => 'completed',
                    'files' => $files,
                ]);
            } catch (\Exception $e) {
                $clone->setStatus('error');
                $clone->setError($e->getMessage());
                $em->flush();

                return new JsonResponse([
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        return new JsonResponse([
            'status' => $clone->getStatus(),
            'files' => $clone->getGeneratedFiles(),
            'error' => $clone->getError(),
        ]);
    }

    #[Route('/clone/{id}/preview/{path}', name: 'app_clone_preview')]
    public function preview(WebsiteClone $clone, string $path): Response
    {
        if ($clone->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $files = $clone->getGeneratedFiles();
        if (!isset($files[$path])) {
            throw $this->createNotFoundException();
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $contentType = match($extension) {
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'svg' => 'image/svg+xml',
            default => 'text/html',
        };

        return new Response(
            $files[$path],
            Response::HTTP_OK,
            ['Content-Type' => $contentType]
        );
    }

    #[Route('/api/clone-files/{id}', name: 'app_clone_files', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getCloneFiles(WebsiteClone $clone): JsonResponse
    {
        if ($clone->getUser() !== $this->getUser()) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Accès non autorisé'
            ], Response::HTTP_FORBIDDEN);
        }

        $files = $clone->getGeneratedFiles();
        if (!$files) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Aucun fichier disponible'
            ], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'success' => true,
            'files' => $files
        ]);
    }
}