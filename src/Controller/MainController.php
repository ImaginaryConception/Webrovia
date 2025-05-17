<?php

namespace App\Controller;

use Exception;
use Stripe\Stripe;
use App\Entity\Prompt;
use App\Form\PromptType;
use Stripe\StripeClient;
use Stripe\Checkout\Session;
use App\Message\GenerateWebsiteMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Ramsey\Uuid\Uuid;

class MainController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(EntityManagerInterface $em): Response
    {
        $prompt = new Prompt();
        $form = $this->createForm(PromptType::class, $prompt);

        // Récupérer le dernier prompt de l'utilisateur connecté
        $lastPrompt = null;
        if ($this->getUser()) {
            $lastPrompt = $em->getRepository(Prompt::class)->findOneBy(
                ['user' => $this->getUser()],
                ['createdAt' => 'DESC']
            );
        }

        return $this->render('main/index.html.twig', [
            'form' => $form->createView(),
            'prompt' => $lastPrompt
        ]);
    }

    #[Route('/modify/{id}', name: 'app_modify', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function modify(Request $request, EntityManagerInterface $em, MessageBusInterface $messageBus, int $id): Response
    {
        try {
            if (!$request->headers->has('X-Requested-With') || $request->headers->get('X-Requested-With') !== 'XMLHttpRequest') {
                return new Response(
                    json_encode([
                        'success' => false,
                        'error' => 'Cette action nécessite une requête AJAX'
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    Response::HTTP_BAD_REQUEST,
                    ['Content-Type' => 'application/json']
                );
            }

            $content = $request->request->get('content');
            if (!$content) {
                throw new Exception('Le contenu de la modification est requis');
            }

            // Récupérer le prompt spécifique à modifier
            $prompt = $em->getRepository(Prompt::class)->find($id);

            if (!$prompt || $prompt->getUser() !== $this->getUser()) {
                throw new Exception('Accès non autorisé');
            }

            // Créer un nouveau prompt avec les fichiers générés initialement
            $newPrompt = new Prompt();
            $newPrompt->setUser($this->getUser());
            $newPrompt->setContent($prompt->getContent());
            $newPrompt->setModificationRequest($content);
            $newPrompt->setStatus('pending');
            $newPrompt->setGeneratedFiles($prompt->getGeneratedFiles());
            $newPrompt->setOriginalPrompt($prompt);
            $newPrompt->setWebsiteIdentification($prompt->getWebsiteIdentification() ?: Uuid::uuid4()->toString());
            
            // Calculer la nouvelle version en fonction de la version la plus élevée
            $highestVersion = $em->getRepository(Prompt::class)
                ->createQueryBuilder('p')
                ->select('MAX(p.version)')
                ->where('p.originalPrompt = :originalPrompt OR p.id = :promptId')
                ->setParameter('originalPrompt', $prompt)
                ->setParameter('promptId', $prompt->getId())
                ->getQuery()
                ->getSingleScalarResult() ?: 0;
            
            $newPrompt->setVersion($highestVersion + 1);

            // Désactiver l'ancien prompt
            $prompt->setStatus('archived');
            $em->flush();

            $content = $request->request->get('content');
            if (!$content) {
                throw new Exception('Le contenu de la modification est requis');
            }

            // Récupérer le prompt spécifique à modifier
            $prompt = $em->getRepository(Prompt::class)->find($id);

            if (!$prompt || $prompt->getUser() !== $this->getUser()) {
                throw new Exception('Accès non autorisé');
            }

            // Créer un nouveau prompt avec les fichiers générés initialement
            $newPrompt = new Prompt();
            $newPrompt->setUser($this->getUser());
            $newPrompt->setContent($prompt->getContent());
            $newPrompt->setModificationRequest($content);
            $newPrompt->setStatus('pending');
            $newPrompt->setGeneratedFiles($prompt->getGeneratedFiles());
            $newPrompt->setOriginalPrompt($prompt);
            
            // Mettre à jour les références des prompts existants
            $existingPrompts = $em->getRepository(Prompt::class)->findBy(['originalPrompt' => $prompt]);
            foreach ($existingPrompts as $existingPrompt) {
                $existingPrompt->setOriginalPrompt($newPrompt);
            }
            
            $em->persist($newPrompt);
            $em->flush();
            
            // Désactiver l'ancien prompt au lieu de le supprimer
            $prompt->setStatus('archived');
            $em->flush();

            // Dispatcher le message pour la génération
            $messageBus->dispatch(new GenerateWebsiteMessage($newPrompt->getId()));

            return new Response(
                json_encode([
                    'success' => true,
                    'data' => [
                        'id' => $newPrompt->getId(),
                        'status' => $newPrompt->getStatus(),
                        'message' => 'Modification du site web en cours...'
                    ]
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                Response::HTTP_OK,
                ['Content-Type' => 'application/json']
            );

        } catch (Exception $e) {
            return new Response(
                json_encode([
                    'success' => false,
                    'error' => $e->getMessage()
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                Response::HTTP_BAD_REQUEST,
                ['Content-Type' => 'application/json']
            );
        }
    }

    #[Route('/generate', name: 'app_generate', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function generate(Request $request, EntityManagerInterface $em, MessageBusInterface $messageBus): Response
    {
        try {
            if (!$request->headers->has('X-Requested-With') || $request->headers->get('X-Requested-With') !== 'XMLHttpRequest') {
                return new Response(
                    json_encode([
                        'success' => false,
                        'error' => 'Cette action nécessite une requête AJAX'
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    Response::HTTP_BAD_REQUEST,
                    ['Content-Type' => 'application/json']
                );
            }

            $prompt = new Prompt();
            $form = $this->createForm(PromptType::class, $prompt);
            $form->handleRequest($request);

            if (!$form->isSubmitted()) {
                throw new Exception('Le formulaire n\'a pas été soumis');
            }

            if (!$form->isValid()) {
                $errors = [];
                foreach ($form->getErrors(true) as $error) {
                    $errors[] = $error->getMessage();
                }
                return new Response(
                    json_encode([
                        'success' => false,
                        'errors' => $errors
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    Response::HTTP_BAD_REQUEST,
                    ['Content-Type' => 'application/json']
                );
            }

            $prompt->setUser($this->getUser());
            $prompt->setStatus('pending');
            $prompt->setWebsiteIdentification(Uuid::uuid4()->toString());
            $prompt->setVersion(1);

            // Gérer la modification d'un site existant
            $originalPromptId = $request->request->get('originalPromptId');
            if ($originalPromptId) {
                $originalPrompt = $em->getRepository(Prompt::class)->find($originalPromptId);
                if ($originalPrompt && $originalPrompt->getUser() === $this->getUser()) {
                    $prompt->setOriginalPrompt($originalPrompt);
                    $prompt->setModificationRequest($prompt->getContent());
                    $prompt->setContent($originalPrompt->getContent());
                }
            }

            $prompt->setUser($this->getUser());
            $prompt->setStatus('pending');
            $em->persist($prompt);
            $em->flush();

            $messageBus->dispatch(new GenerateWebsiteMessage($prompt->getId()));

            return new Response(
                json_encode([
                    'success' => true,
                    'data' => [
                        'id' => $prompt->getId(),
                        'status' => $prompt->getStatus(),
                        'message' => 'Génération du site web en cours...'
                    ]
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                Response::HTTP_ACCEPTED,
                ['Content-Type' => 'application/json']
            );

        } catch (Exception $e) {
            return new Response(
                json_encode([
                    'success' => false,
                    'error' => $e->getMessage()
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['Content-Type' => 'application/json']
            );
        }
    }

    #[Route('/prompt/{id}', name: 'app_prompt_status', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function promptStatus(Prompt $prompt, EntityManagerInterface $em, MessageBusInterface $messageBus): Response
    {
        if ($prompt->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        try {
            $response = [
                'status' => $prompt->getStatus(),
                'files' => []
            ];

            // Traiter les fichiers générés
            $generatedFiles = $prompt->getGeneratedFiles();
            if ($generatedFiles) {
                foreach ($generatedFiles as $filename => $content) {
                    // Nettoyer et encoder correctement le contenu JSON
                    $cleanContent = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $response['files'][$filename] = json_encode(json_decode($cleanContent)) ?: $cleanContent;
                }
            }

            if ($prompt->getStatus() === 'error') {
                $error = $prompt->getError();
                $response['error'] = $error;

                // Restaurer automatiquement la version précédente en cas d'erreur
                $previousPrompt = $em->getRepository(Prompt::class)->findOneBy(
                    ['websiteIdentification' => $prompt->getWebsiteIdentification()],
                    ['version' => 'DESC']
                );

                if ($previousPrompt && $previousPrompt->getId() !== $prompt->getId()) {
                    $newPrompt = new Prompt();
                    $newPrompt->setUser($this->getUser());
                    $newPrompt->setContent($previousPrompt->getContent());
                    if ($previousPrompt->getWebsiteType()) {
                        $newPrompt->setWebsiteType($previousPrompt->getWebsiteType());
                    }
                    if ($previousPrompt->getFeatures()) {
                        $newPrompt->setFeatures($previousPrompt->getFeatures());
                    }
                    $newPrompt->setGeneratedFiles($previousPrompt->getGeneratedFiles());
                    $newPrompt->setStatus('pending');
                    $newPrompt->setWebsiteIdentification($previousPrompt->getWebsiteIdentification());
                    $newPrompt->setVersion($previousPrompt->getVersion() + 1);
                    $newPrompt->setOriginalPrompt($previousPrompt->getOriginalPrompt() ?: $previousPrompt);

                    $em->persist($newPrompt);
                    $prompt->setStatus('archived');
                    $em->flush();

                    $messageBus->dispatch(new GenerateWebsiteMessage($newPrompt->getId()));
                    $response['restoration_initiated'] = true;
                }

                // Si l'erreur contient "429", on renvoie une vraie 429
                $httpCode = str_contains($error, '429') ? 429 : Response::HTTP_INTERNAL_SERVER_ERROR;

                return new Response(
                    json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    $httpCode,
                    ['Content-Type' => 'application/json']
                );
            }

            return new Response(
                json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                $prompt->getStatus() === 'error' ? Response::HTTP_INTERNAL_SERVER_ERROR : Response::HTTP_OK,
                ['Content-Type' => 'application/json']
            );
        } catch (\JsonException $e) {
            return new Response(
                json_encode([
                    'status' => 'error',
                    'error' => 'Erreur lors de la génération de la réponse JSON'
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['Content-Type' => 'application/json']
            );
        }
    }

    #[Route('/admin/prompts', name: 'app_admin_prompts')]
    #[IsGranted('ROLE_ADMIN')]
    public function adminPrompts(EntityManagerInterface $em): Response
    {
        $prompts = $em->getRepository(Prompt::class)->findAllOrderedByDate();

        return $this->render('main/admin_prompts.html.twig', [
            'prompts' => $prompts
        ]);
    }

    #[Route('/create-stripe-session/', name: 'pay')]
    public function stripeCheckout()
    {
        $stripePrivateKey = new StripeClient($_ENV['STRIPE_SECRET_KEY']);

        $checkout_session = $stripePrivateKey->checkout->sessions->create([
            'line_items' => [[
                'price' => 'price_1RLmFaPWgJeaubFaxBHYVrcz',
                'quantity' => 1,
            ]],
            'mode' => 'subscription',
            'success_url' => $this->generateUrl('success', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'cancel_url' => $this->generateUrl('error', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);

        return new RedirectResponse($checkout_session->url);
    }

    #[Route('/success', name: 'success')]
    public function success(MailerInterface $mailer)
    {
        $email = (new TemplatedEmail())
            ->from('support@imaginaryconception.com')
            ->to('anishamouche@gmail.com')
            ->bcc('imaginaryconception.com+7d8eac2120@invite.trustpilot.com')
            ->subject('Confirmation de votre paiement Web Forge AI.')
            ->textTemplate('emails/payment_confirmation.txt.twig')
            ->htmlTemplate('emails/payment_confirmation.html.twig')
        ;

        // Envoie l'email
        $mailer->send($email);

        return $this->render('main/success.html.twig');
    }

    #[Route('/error', name: 'error')]
    public function error()
    {
        return $this->render('main/error.html.twig');
    }

    #[Route('/my-sites', name: 'app_my_sites')]
    #[IsGranted('ROLE_USER')]
    public function mySites(EntityManagerInterface $em): Response
    {
        $prompts = $em->getRepository(Prompt::class)->findBy(
            ['user' => $this->getUser()],
            ['createdAt' => 'DESC']
        );

        $templates = [];
        foreach ($prompts as $prompt) {
            if ($prompt->getGeneratedFiles()) {
                foreach ($prompt->getGeneratedFiles() as $path => $content) {
                    $templates[] = [
                        'name' => basename($path),
                        'path' => $path
                    ];
                }
            }
        }

        return $this->render('main/my_sites.html.twig', [
            'prompts' => $prompts,
            'templates' => $templates
        ]);
    }

    #[Route('/api/file-content/{id}', name: 'app_get_file_content', methods: ['GET'])]
    public function getFileContent(Request $request, EntityManagerInterface $em, int $id): Response
    {
        try {
            if (!$request->headers->has('X-Requested-With') || $request->headers->get('X-Requested-With') !== 'XMLHttpRequest') {
                throw new Exception('Cette action nécessite une requête AJAX');
            }

            $path = $request->query->get('path');
            if (!$path) {
                throw new Exception('Le chemin du fichier est requis');
            }

            $prompt = $em->getRepository(Prompt::class)->find($id);
            if (!$prompt || $prompt->getUser() !== $this->getUser()) {
                throw new Exception('Accès non autorisé');
            }

            $files = $prompt->getGeneratedFiles();
            if (!isset($files[$path])) {
                throw new Exception('Fichier non trouvé');
            }

            return new Response(
                json_encode([
                    'success' => true,
                    'content' => $files[$path]
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                Response::HTTP_OK,
                ['Content-Type' => 'application/json']
            );

        } catch (Exception $e) {
            return new Response(
                json_encode([
                    'success' => false,
                    'message' => $e->getMessage()
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                Response::HTTP_BAD_REQUEST,
                ['Content-Type' => 'application/json']
            );
        }
    }

    #[Route('/restore-version/{id}', name: 'app_restore_version', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function restoreVersion(Request $request, EntityManagerInterface $em, int $id): Response
    {
        try {
            // Récupérer le prompt à restaurer
            $targetPrompt = $em->getRepository(Prompt::class)->find($id);
            if (!$targetPrompt || $targetPrompt->getUser() !== $this->getUser()) {
                throw new Exception('Accès non autorisé à cette version');
            }

            // Récupérer toutes les versions du site
            $allPrompts = $em->getRepository(Prompt::class)->findBy(
                ['websiteIdentification' => $targetPrompt->getWebsiteIdentification()]
            );

            // Vérifier que la version à restaurer est bien archivée
            if ($targetPrompt->getStatus() !== 'archived') {
                throw new Exception('Seules les versions archivées peuvent être restaurées');
            }

            // Archiver toutes les versions du site, sauf la version cible
            foreach ($allPrompts as $prompt) {
                if ($prompt->getId() !== $targetPrompt->getId()) {
                    $prompt->setStatus('archived');
                }
            }
            
            // Réactiver la version cible
            $targetPrompt->setStatus('completed');
            
            // Persister les changements
            $em->flush();

            $this->addFlash('success', 'La version a été restaurée avec succès et toutes les autres versions ont été archivées.');
            
        } catch (Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_my_sites');
    }
}