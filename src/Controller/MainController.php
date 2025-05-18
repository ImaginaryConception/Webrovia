<?php

namespace App\Controller;

use Exception;
use Stripe\Stripe;
use Ramsey\Uuid\Uuid;
use App\Entity\Prompt;
use App\Form\DomainType;
use App\Form\PromptType;
use Stripe\StripeClient;
use App\Entity\WebsiteClone;
use Stripe\Checkout\Session;
use App\Service\FtpService;
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
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;

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
            if ($request->headers->get('X-Requested-With') !== 'XMLHttpRequest') {
                return $this->json([
                    'success' => false,
                    'error' => 'Cette action nécessite une requête AJAX'
                ], Response::HTTP_BAD_REQUEST);
            }

            $content = $request->request->get('content');
            if (!$content) {
                throw new Exception('Le contenu de la modification est requis');
            }

            $prompt = $em->getRepository(Prompt::class)->find($id);
            if (!$prompt || $prompt->getUser() !== $this->getUser()) {
                throw new Exception('Accès non autorisé');
            }

            // Nouvelle version du prompt
            $newPrompt = new Prompt();
            $newPrompt->setUser($this->getUser());
            $newPrompt->setContent($prompt->getContent());
            $newPrompt->setModificationRequest($content);
            $newPrompt->setStatus('pending');
            $newPrompt->setGeneratedFiles($prompt->getGeneratedFiles());
            $newPrompt->setOriginalPrompt($prompt);
            $newPrompt->setWebsiteIdentification($prompt->getWebsiteIdentification() ?: Uuid::uuid4()->toString());

            // Calcul version
            $highestVersion = $em->getRepository(Prompt::class)
                ->createQueryBuilder('p')
                ->select('MAX(p.version)')
                ->where('p.originalPrompt = :originalPrompt OR p.id = :promptId')
                ->setParameter('originalPrompt', $prompt)
                ->setParameter('promptId', $prompt->getId())
                ->getQuery()
                ->getSingleScalarResult() ?: 0;

            $newPrompt->setVersion($highestVersion + 1);

            // Mettre à jour les anciens prompts si nécessaire
            $existingPrompts = $em->getRepository(Prompt::class)->findBy(['originalPrompt' => $prompt]);
            foreach ($existingPrompts as $existingPrompt) {
                $existingPrompt->setOriginalPrompt($newPrompt);
            }

            // Archive l'ancien
            $prompt->setStatus('archived');

            $em->persist($newPrompt);
            $em->flush();

            $messageBus->dispatch(new GenerateWebsiteMessage($newPrompt->getId()));

            return $this->json([
                'success' => true,
                'data' => [
                    'id' => $newPrompt->getId(),
                    'status' => $newPrompt->getStatus(),
                    'message' => 'Modification du site web en cours...'
                ]
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
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
                    // Nettoyer et décoder correctement le contenu JSON
                    $cleanContent = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $decodedContent = json_decode($cleanContent, true);
                    $response['files'][$filename] = $decodedContent !== null ? $decodedContent : $cleanContent;
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
        $clones = $em->getRepository(WebsiteClone::class)->findAll();

        return $this->render('main/admin_prompts.html.twig', [
            'prompts' => $prompts,
            'clones' => $clones
        ]);
    }

    #[Route('/create-stripe-session', name: 'pay')]
    public function stripeCheckout(UrlGeneratorInterface $urlGenerator): RedirectResponse
    {
        $stripe = new StripeClient($_ENV['STRIPE_SECRET_KEY']);

        $checkoutSession = $stripe->checkout->sessions->create([
            'line_items' => [[
                'price' => 'price_1RQEoUBtUGEFOuHvDJWQVYku',
                'quantity' => 1,
            ]],
            'mode' => 'subscription',
            'payment_method_types' => ['card'],
            'success_url' => $urlGenerator->generate('success', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'cancel_url' => $urlGenerator->generate('error', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);

        return new RedirectResponse($checkoutSession->url);
    }

    #[Route('/payment/success', name: 'app_payment_success')]
    public function paymentSuccess(Request $request, EntityManagerInterface $em, FtpService $ftpService): Response
    {
        $type = $request->query->get('type');
        $id = $request->query->get('id');
        $domainName = $request->query->get('domainName');
        $extension = $request->query->get('extension');

        if ($type === 'site') {
            $prompt = $em->getRepository(Prompt::class)->find($id);
            if (!$prompt || $prompt->getUser() !== $this->getUser()) {
                $this->addFlash('error', 'Accès refusé ou prompt introuvable.');
                return $this->redirectToRoute('app_my_sites');
            }

            $fullDomain = $domainName . $extension;
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

        // Pour les clones, rediriger vers la route de déploiement existante
        return $this->redirectToRoute('app_deploy_clone', [
            'cloneId' => $id,
            'domainName' => $domainName,
            'extension' => $extension
        ]);
    }

    #[Route('/create-stripe-session-domain/{type}/{id}', name: 'payDomain')]
    public function domainCheckout(string $type, int $id, Request $request, UrlGeneratorInterface $urlGenerator): RedirectResponse
    {
        $stripe = new StripeClient($_ENV['STRIPE_SECRET_KEY']);

        $returnRoute = $type === 'site' ? 'app_my_sites' : 'app_my_clones';
        $deployRoute = $type === 'site' ? 'app_deploy_site' : 'app_deploy_clone';
        $routeParam = $type === 'site' ? 'promptId' : 'cloneId';

        // Récupérer les données du formulaire
        $formData = $request->request->all()['domain'];
        $domainName = $formData['domainName'];
        $extension = $formData['extension'];

        $checkoutSession = $stripe->checkout->sessions->create([
            'line_items' => [[
                'price' => 'price_1RQFAkBtUGEFOuHvakQdWcqY',
                'quantity' => 1,
            ]],
            'mode' => 'subscription',
            'payment_method_types' => ['card'],
            'success_url' => $urlGenerator->generate('app_payment_success', [
                'type' => $type,
                'id' => $id,
                'domainName' => $domainName,
                'extension' => $extension
            ], UrlGeneratorInterface::ABSOLUTE_URL),
            'cancel_url' => $urlGenerator->generate($returnRoute, [], UrlGeneratorInterface::ABSOLUTE_URL),
            'metadata' => [
                'type' => $type,
                'id' => $id,
                'domainName' => $domainName,
                'extension' => $extension
            ]
        ]);

        return new RedirectResponse($checkoutSession->url);
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
    public function mySites(Request $request, EntityManagerInterface $em): Response
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

        $domainForm = $this->createForm(DomainType::class);
        $domainForm->handleRequest($request);

        if ($domainForm->isSubmitted() && $domainForm->isValid()) {
            $data = $domainForm->getData();
            $domainName = $data['domainName'] . $data['extension'];
            
            $this->addFlash('success', 'Le nom de domaine ' . $domainName . ' a été enregistré avec succès.');
            return $this->redirectToRoute('app_my_sites');
        }

        return $this->render('main/my_sites.html.twig', [
            'prompts' => $prompts,
            'templates' => $templates,
            'domain_form' => $domainForm->createView()
        ]);
    }

    #[Route('/preview/{id}', name: 'app_preview_template', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function previewTemplate(Request $request, EntityManagerInterface $em, int $id): Response
    {
        try {
            $path = $request->query->get('path');
            if (!$path) {
                $errorMessage = new Response(
                    '<div style="display: flex; align-items: center; justify-content: center; min-height: 100%; width: 100%; margin: 0; padding: 20px; background-color: #fff3cd; color: #856404; font-family: Arial, sans-serif; text-align: center;">
                        <div>
                            <i class="fas fa-exclamation-triangle" style="font-size: 24px; margin-bottom: 15px; display: block;"></i>
                            <p style="margin: 0; font-size: 16px;">Veuillez sélectionner un fichier template dans l\'explorateur de fichiers pour continuer.</p>
                        </div>
                    </div>',
                    Response::HTTP_BAD_REQUEST,
                    ['Content-Type' => 'text/html']
                );
                return $errorMessage;
            }

            $prompt = $em->getRepository(Prompt::class)->find($id);
            if (!$prompt || $prompt->getUser() !== $this->getUser()) {
                throw new Exception('Accès non autorisé');
            }

            $files = $prompt->getGeneratedFiles();
            if (!isset($files[$path])) {
                throw new Exception('Fichier non trouvé');
            }

            // Déterminer le type de contenu en fonction de l'extension du fichier
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $contentType = match($extension) {
                'css' => 'text/css',
                'js' => 'application/javascript',
                'json' => 'application/json',
                'svg' => 'image/svg+xml',
                default => 'text/html',
            };

            // Traiter le contenu Twig pour n'afficher que le HTML/CSS rendu
                $content = $files[$path];
                if ($extension === 'twig' || $extension === 'html') {
                    // Supprimer les balises Twig
                    $content = preg_replace('/\{%.*?%\}/s', '', $content);
                    $content = preg_replace('/\{\{.*?\}\}/s', '', $content);
            }

            return new Response(
                $content,
                Response::HTTP_OK,
                ['Content-Type' => $contentType]
            );
        } catch (Exception $e) {
            // En cas d'erreur, toujours renvoyer une réponse HTML pour l'affichage dans l'iframe
            return new Response(
                sprintf('<div class="error">%s</div>', htmlspecialchars($e->getMessage())),
                Response::HTTP_BAD_REQUEST,
                ['Content-Type' => 'text/html']
            );
        }
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