<?php

namespace App\Controller;

use Exception;
use Stripe\Stripe;
use App\Entity\User;
use Ramsey\Uuid\Uuid;
use App\Entity\Prompt;
use App\Form\DomainType;
use App\Form\PromptType;
use Stripe\StripeClient;
use App\Service\FtpService;
use App\Entity\WebsiteClone;
use Stripe\Checkout\Session;
use App\Service\PorkbunService;
use App\Message\GenerateWebsiteMessage;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\PromptRestorationService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Psr\Log\LoggerInterface;

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
            $newPrompt->setGenerationMessage('Initialisation de la modification du site web...');

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
                'error' => $e->getMessage(),
                'data' => [
                    'id' => $newPrompt->getId(),
                    'status' => $newPrompt->getStatus(),
                ]
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/generate', name: 'app_generate', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function generate(Request $request, Security $security, EntityManagerInterface $em, MessageBusInterface $messageBus): Response
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

            $user = $security->getUser();
            if (!$user instanceof \App\Entity\User) {
                throw new Exception("Utilisateur non valide.");
            }

            if ($user->getCount() >= 3 && $user->isSubscribed() === false) {
                throw new Exception('Vous avez atteint le nombre maximum de sites web gratuits');
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
            $prompt->setGenerationMessage('Initialisation de la génération du site web...');

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
    public function promptStatus(
        Prompt $prompt, 
        EntityManagerInterface $em, 
        PromptRestorationService $promptRestorationService
    ): Response {
        if ($prompt->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        try {
            $response = [
                'status' => $prompt->getStatus(),
                'files' => $promptRestorationService->getPromptFiles($prompt),
                'generationMessage' => $prompt->getGenerationMessage()
            ];

            if ($prompt->getStatus() === 'error') {
                $error = $prompt->getError();
                $response = array_merge(
                    $response,
                    $promptRestorationService->handleError($prompt)
                );

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
    public function paymentSuccess(Request $request, EntityManagerInterface $em, FtpService $ftpService, PorkbunService $porkbunService): Response
    {
        $type = $request->query->get('type');
        $id = $request->query->get('id');
        $domainName = $request->query->get('domainName');
        $extension = $request->query->get('extension');

        if ($type === 'site') {
            $prompt = $em->getRepository(Prompt::class)->find($id);
            if (!$prompt || $prompt->getUser() !== $this->getUser()) {
                $this->addFlash('error', 'Accès refusé ou prompt introuvable.');
                return $this->redirectToRoute('app_my_sites', ['id' => $id]);
            }

            $fullDomain = $domainName . $extension;
            
            // Vérifier la disponibilité du domaine
            try {
                $availabilityResult = $porkbunService->checkDomainAvailability($fullDomain);
                if (!($availabilityResult['avail'] ?? false)) {
                    $this->addFlash('error', 'Le domaine ' . $fullDomain . ' n\'est pas disponible. Veuillez en choisir un autre.');
                    return $this->redirectToRoute('app_my_sites', ['id' => $id]);
                }
                
                // Enregistrer le domaine via Porkbun
                $registerResult = $porkbunService->registerDomain($fullDomain);
                if (!($registerResult['success'] ?? false)) {
                    $this->addFlash('error', 'Erreur lors de l\'enregistrement du domaine: ' . ($registerResult['message'] ?? 'Erreur inconnue'));
                    return $this->redirectToRoute('app_my_sites', ['id' => $id]);
                }
                
                // Ajouter un enregistrement DNS pour rediriger vers l'IP du serveur
                $dnsResult = $porkbunService->addDnsRecord($fullDomain, 'A', '@', '109.234.162.89');
                if (!($dnsResult['success'] ?? false)) {
                    $this->addFlash('warning', 'Le domaine a été enregistré mais la configuration DNS a échoué: ' . ($dnsResult['message'] ?? 'Erreur inconnue'));
                }
                
                // Récupérer un certificat SSL pour le domaine
                $sslResult = $porkbunService->retrieveSslCertificate($fullDomain);
                if (!($sslResult['success'] ?? false)) {
                    $this->addFlash('warning', 'Le domaine a été enregistré mais la génération du certificat SSL a échoué: ' . ($sslResult['message'] ?? 'Erreur inconnue'));
                }
            } catch (\Exception $e) {
                $this->addFlash('warning', 'Une erreur est survenue lors de la configuration du domaine: ' . $e->getMessage());
            }
            
            $prompt->setDomainName($fullDomain);
            $prompt->setDeployed(true);
            $em->flush();

            $result = $ftpService->deploySite($prompt);

            if (!$result['success']) {
                $this->addFlash('error', $result['error'] ?? 'Erreur lors du déploiement');
                return $this->redirectToRoute('app_my_sites', ['id' => $id]);
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

    #[Route('/test/', name: 'pasyDomain')]
    public function testPorkbun(PorkbunService $porkbunService): JsonResponse
    {
        try {
            $domain = 'webrovia.com';
            $result = $porkbunService->checkDomainAvailability($domain);

            return new JsonResponse([
                'success' => true,
                'domain' => $domain,
                'available' => $result['available'] ?? false,
                'price' => $result['price'] ?? 'N/A'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    #[Route('/create-stripe-session-domain/{type}/{id}', name: 'payDomain')]
    public function domainCheckout(string $type, int $id, Request $request, UrlGeneratorInterface $urlGenerator, LoggerInterface $logger): RedirectResponse
    {
        $stripe = new StripeClient($_ENV['STRIPE_SECRET_KEY']);

        $returnRoute = $type === 'site' ? 'app_my_sites' : 'app_my_clones';
        $deployRoute = $type === 'site' ? 'app_deploy_site' : 'app_deploy_clone';
        $routeParam = $type === 'site' ? 'promptId' : 'cloneId';

        // Récupérer les données du formulaire
        $formData = $request->request->all()['domain'];
        $domainName = $formData['domainName'];
        $extension = $formData['extension'];
        $price = $formData['price'] ?? null;
        
        $logger->info('Données du formulaire de domaine', [
            'domainName' => $domainName,
            'extension' => $extension,
            'price' => $price
        ]);
        
        // Créer une session de paiement avec le prix dynamique si disponible
        $lineItems = [];
        
        if ($price && is_numeric($price)) {
            // Convertir le prix en centimes pour Stripe (Stripe utilise les centimes)
            $priceInCents = (int)($price * 100);
            $logger->info('Prix en centimes pour Stripe', ['priceInCents' => $priceInCents]);
            
            $lineItems[] = [
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => [
                        'name' => "Domaine {$domainName}{$extension}",
                    ],
                    'unit_amount' => $priceInCents,
                ],
                'quantity' => 1,
            ];
        } else {
            // Fallback sur le prix fixe si le prix dynamique n'est pas disponible
            $logger->warning('Prix du domaine non disponible, utilisation du prix fixe', ['price' => $price]);
            $lineItems[] = [
                'price' => 'price_1RQFAkBtUGEFOuHvakQdWcqY',
                'quantity' => 1,
            ];
        }
        
        $checkoutSession = $stripe->checkout->sessions->create([
            'line_items' => $lineItems,
            'mode' => 'payment', // Changé de 'subscription' à 'payment' pour un paiement unique
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
                'extension' => $extension,
                'price' => $price
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
            ->subject('Confirmation de votre paiement Webrovia.')
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

    #[Route('/delete-site/{id}', name: 'app_delete_site', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function deleteSite(Request $request, EntityManagerInterface $em, int $id): Response
    {
        try {
            if ($request->headers->get('X-Requested-With') !== 'XMLHttpRequest') {
                return $this->json([
                    'success' => false,
                    'error' => 'Cette action nécessite une requête AJAX'
                ], Response::HTTP_BAD_REQUEST);
            }

            $prompt = $em->getRepository(Prompt::class)->find($id);
            if (!$prompt || $prompt->getUser() !== $this->getUser()) {
                throw new Exception('Accès non autorisé');
            }

            $prompt->setStatus('deleted');
            $em->flush();

            return $this->json([
                'success' => true,
                'message' => 'Site supprimé avec succès'
            ]);

        } catch (Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
                'data' => [
                    'id' => $id
                ]
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/sites', name: 'app_list_sites')]
    #[IsGranted('ROLE_USER')]
    public function listSites(EntityManagerInterface $em): Response
    {
        $prompts = $em->getRepository(Prompt::class)->findBy(
            ['user' => $this->getUser()],
            ['createdAt' => 'DESC']
        );

        return $this->render('main/list_sites.html.twig', [
            'prompts' => $prompts
        ]);
    }

    #[Route('/my-sites/{id}', name: 'app_my_sites')]
    #[IsGranted('ROLE_USER')]
    public function mySites(Request $request, EntityManagerInterface $em, ?int $id = null): Response
    {
        if ($id !== null) {
            $prompt = $em->getRepository(Prompt::class)->find($id);
            if (!$prompt || $prompt->getUser() !== $this->getUser()) {
                throw $this->createNotFoundException('Site non trouvé');
            }
            
            // Si le prompt actuel n'est pas complété, chercher la dernière version complétée
            if ($prompt->getStatus() == 'archived' || $prompt->getStatus() == 'error_archived') {
                $latestPrompt = $em->getRepository(Prompt::class)->findOneBy(
                    [
                        'user' => $this->getUser(),
                        'websiteIdentification' => $prompt->getWebsiteIdentification(),
                        'status' => 'completed'
                    ],
                    ['version' => 'DESC']
                );
                
                // Si on ne trouve pas de prompt avec status "completed", chercher celui avec status "error"
                if (!$latestPrompt) {
                    $latestPrompt = $em->getRepository(Prompt::class)->findOneBy(
                        [
                            'user' => $this->getUser(),
                            'websiteIdentification' => $prompt->getWebsiteIdentification(),
                            'status' => 'error'
                        ],
                        ['version' => 'DESC']
                    );
                }
                
                if ($latestPrompt && $latestPrompt->getId() !== $prompt->getId()) {
                    return $this->redirectToRoute('app_my_sites', ['id' => $latestPrompt->getId()]);
                }
            }
            
            $prompts = [$prompt];
        } else {
            $prompts = $em->getRepository(Prompt::class)->findBy(
                ['user' => $this->getUser()],
                ['createdAt' => 'DESC']
            );
        }

        // Injecte allVersions dans chaque prompt
        foreach ($prompts as $prompt) {
            // Récupérer toutes les versions liées au même websiteIdentification
            $versions = $em->getRepository(Prompt::class)->findBy(
                ['websiteIdentification' => $prompt->getWebsiteIdentification()],
                ['version' => 'DESC']
            );
            $prompt->allVersions = $versions;
        }

        $templates = [];
        foreach ($prompts as $prompt) {
            if ($prompt->getGeneratedFiles()) {
                foreach ($prompt->getGeneratedFiles() as $path => $content) {
                    $templates[] = [
                        'name' => basename($path),
                        'path' => $path,
                        'promptId' => $prompt->getId()
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
            return $this->redirectToRoute('app_my_sites', ['id' => $id]);
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
                $path = 'index.html.twig';
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

            // Traiter le contenu pour créer une prévisualisation intégrée
            $content = $files[$path];
            if ($extension === 'twig' || $extension === 'html') {
                // Supprimer les balises Twig
                $content = preg_replace('/\{%.*?%\}/s', '', $content);
                $content = preg_replace('/\{\{.*?\}\}/s', '', $content);
            }

            // Préparer les ressources
            $cssContent = [];
            $jsContent = [];
            $svgContent = [];
            
            // Collecter toutes les ressources
            foreach ($files as $filePath => $fileContent) {
                $fileExt = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                switch ($fileExt) {
                    case 'css':
                        $cssContent[] = $fileContent;
                        break;
                    case 'js':
                        $jsContent[] = $fileContent;
                        break;
                    case 'svg':
                        $fileName = pathinfo($filePath, PATHINFO_FILENAME);
                        $svgContent[$fileName] = $fileContent;
                        break;
                }
            }

            // Assurer une structure HTML complète
            if ($extension === 'twig' || $extension === 'html') {
                if (!preg_match('/<html[^>]*>/i', $content)) {
                    $content = "<!DOCTYPE html><html><head><meta charset=\"UTF-8\"><title>Preview</title></head><body>" . $content . "</body></html>";
                }

                
                // Injecter les styles CSS
                if (!empty($cssContent)) {
                    $styles = "<style>" . implode("", $cssContent) . "</style>";
                    if (strpos($content, '</head>') !== false) {
                        $content = preg_replace('/<\/head>/', $styles . '</head>', $content);
                    } elseif (strpos($content, '<html') !== false) {
                        $content = preg_replace('/<html[^>]*>/', '$0<head>' . $styles . '</head>', $content);
                    } else {
                        $content = "<head>" . $styles . "</head>" . $content;
                    }
                }
                
                // Injecter les SVGs
                if (!empty($svgContent)) {
                    $svgSprites = "<div style=\"display: none;\">";
                    foreach ($svgContent as $name => $svg) {
                        $svgSprites .= "<!-- SVG: $name -->$svg";
                    }
                    $svgSprites .= "</div>";
                    $content = preg_replace('/<body[^>]*>/', '$0' . $svgSprites, $content);
                }
                
                // Injecter les scripts JavaScript
                if (!empty($jsContent)) {
                    $scripts = "<script>" . implode("", $jsContent) . "</script>";
                    $content = preg_replace('/<\/body>/', $scripts . '</body>', $content);
                }
                
                // Ajouter des styles pour l'iframe
                $frameStyles = "<style>body { margin: 0; padding: 0; }</style>";
                $content = preg_replace('/<\/head>/', $frameStyles . '</head>', $content);
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
    
    #[Route('/api/file-content/{id}', name: 'app_update_file_content', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function updateFileContent(Request $request, EntityManagerInterface $em, int $id): Response
    {
        try {
            if (!$request->headers->has('X-Requested-With') || $request->headers->get('X-Requested-With') !== 'XMLHttpRequest') {
                throw new Exception('Cette action nécessite une requête AJAX');
            }
            
            // Récupérer les données JSON du corps de la requête
            $data = json_decode($request->getContent(), true);
            if (!$data || !isset($data['path']) || !isset($data['content'])) {
                throw new Exception('Le chemin et le contenu du fichier sont requis');
            }
            
            $path = $data['path'];
            $content = $data['content'];
            
            // Vérifier l'accès au prompt
            $prompt = $em->getRepository(Prompt::class)->find($id);
            if (!$prompt || $prompt->getUser() !== $this->getUser()) {
                throw new Exception('Accès non autorisé');
            }
            
            // Vérifier que le fichier existe
            $files = $prompt->getGeneratedFiles();
            if (!isset($files[$path])) {
                throw new Exception('Fichier non trouvé');
            }
            
            // Mettre à jour le contenu du fichier
            $files[$path] = $content;
            $prompt->setGeneratedFiles($files);
            
            // Persister les changements
            $em->flush();
            
            return new Response(
                json_encode([
                    'success' => true,
                    'message' => 'Fichier mis à jour avec succès'
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

    private function getPromptChain(Prompt $prompt): array
    {
        $chain = [];
        $current = $prompt;

        while ($current !== null) {
            $chain[] = $current;
            $current = $current->getOriginalPrompt();
        }

        return array_reverse($chain); // pour avoir la plus ancienne en premier
    }

    #[Route('/restore/{id}', name: 'app_restore_version', methods: ['POST'])]
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
                    if ($prompt->getStatus() === 'error' || $prompt->getStatus() === 'error_archived') {
                        $prompt->setStatus('error_archived');
                    } else {
                        $prompt->setStatus('archived');
                    }
                }
            }
            
            // Réactiver la version cible
            $targetPrompt->setStatus('completed');
            
            // Persister les changements
            $em->flush();

            $this->addFlash('success', 'La version a été restaurée avec succès et toutes les autres versions ont été archivées.');
            return $this->redirectToRoute('app_my_sites', ['id' => $id]);
        } catch (Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_my_sites');
    }
}