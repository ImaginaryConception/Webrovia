<?php

namespace App\Controller;

use App\Entity\ModelMaker;
use App\Form\ModelMakerType;
use App\Service\ModelMakerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route('/model-maker')]
class ModelMakerController extends AbstractController
{
    #[Route('/', name: 'app_model_maker')]
    #[IsGranted('ROLE_USER')]
    public function index(EntityManagerInterface $em): Response
    {
        // Créer un nouveau ModelMaker
        $modelMaker = new ModelMaker();
        $form = $this->createForm(ModelMakerType::class, $modelMaker);

        // Récupérer les modèles de l'utilisateur connecté
        $userModels = $em->getRepository(ModelMaker::class)->findByUserOrderedByDate($this->getUser());

        return $this->render('model_maker/index.html.twig', [
            'form' => $form->createView(),
            'models' => $userModels
        ]);
    }

    #[Route('/generate', name: 'app_model_maker_generate', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function generate(Request $request, EntityManagerInterface $em, ModelMakerService $modelMakerService): Response
    {
        // Vérifier si la requête est une requête AJAX
        if ($request->headers->get('X-Requested-With') !== 'XMLHttpRequest') {
            return $this->json([
                'success' => false,
                'error' => 'Cette action nécessite une requête AJAX'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Créer un nouveau ModelMaker
        $modelMaker = new ModelMaker();
        $form = $this->createForm(ModelMakerType::class, $modelMaker);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Associer le modèle à l'utilisateur connecté
            $modelMaker->setUser($this->getUser());
            $modelMaker->setStatus('pending');
            $modelMaker->setGenerationMessage('Initialisation de la génération...');

            // Persister l'entité pour obtenir un ID
            $em->persist($modelMaker);
            $em->flush();

            // Générer le modèle de manière asynchrone
            $imageUrl = $modelMakerService->generateModelFromPrompt($modelMaker);

            if ($imageUrl) {
                return $this->json([
                    'success' => true,
                    'message' => 'Maquette générée avec succès',
                    'modelId' => $modelMaker->getId(),
                    'imageUrl' => $imageUrl
                ]);
            } else {
                return $this->json([
                    'success' => false,
                    'error' => 'Erreur lors de la génération de la maquette',
                    'modelId' => $modelMaker->getId()
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        // Si le formulaire n'est pas valide, retourner les erreurs
        $errors = [];
        foreach ($form->getErrors(true) as $error) {
            $errors[] = $error->getMessage();
        }

        return $this->json([
            'success' => false,
            'errors' => $errors
        ], Response::HTTP_BAD_REQUEST);
    }

    #[Route('/status/{id}', name: 'app_model_maker_status', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function status(ModelMaker $modelMaker): JsonResponse
    {
        // Vérifier que l'utilisateur est bien le propriétaire du modèle
        if ($modelMaker->getUser() !== $this->getUser()) {
            return $this->json([
                'success' => false,
                'error' => 'Vous n\'êtes pas autorisé à accéder à ce modèle'
            ], Response::HTTP_FORBIDDEN);
        }

        return $this->json([
            'success' => true,
            'status' => $modelMaker->getStatus(),
            'message' => $modelMaker->getGenerationMessage(),
            'imageUrl' => $modelMaker->getImageUrl(),
            'error' => $modelMaker->getError()
        ]);
    }

    #[Route('/delete/{id}', name: 'app_model_maker_delete', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function delete(Request $request, ModelMaker $modelMaker, EntityManagerInterface $em): JsonResponse
    {
        // Vérifier que l'utilisateur est bien le propriétaire du modèle
        if ($modelMaker->getUser() !== $this->getUser()) {
            return $this->json([
                'success' => false,
                'error' => 'Vous n\'êtes pas autorisé à supprimer ce modèle'
            ], Response::HTTP_FORBIDDEN);
        }

        // Vérifier le token CSRF
        if (!$this->isCsrfTokenValid('delete' . $modelMaker->getId(), $request->request->get('_token'))) {
            return $this->json([
                'success' => false,
                'error' => 'Token CSRF invalide'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Supprimer l'image si elle existe
        $imageUrl = $modelMaker->getImageUrl();
        if ($imageUrl && file_exists($this->getParameter('kernel.project_dir') . '/public' . $imageUrl)) {
            unlink($this->getParameter('kernel.project_dir') . '/public' . $imageUrl);
        }

        // Supprimer le modèle
        $em->remove($modelMaker);
        $em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Modèle supprimé avec succès'
        ]);
    }
}