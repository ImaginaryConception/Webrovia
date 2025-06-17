<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Form\WebsiteCloneType;
use App\Service\WebsiteCloneService;

class WebsiteCloneController extends AbstractController
{
    private WebsiteCloneService $websiteCloneService;

    public function __construct(WebsiteCloneService $websiteCloneService)
    {
        $this->websiteCloneService = $websiteCloneService;
    }

    #[Route('/website/clone', name: 'app_website_clone')]
    public function index(Request $request): Response
    {
        $form = $this->createForm(WebsiteCloneType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $data = $form->getData();
                return $this->websiteCloneService->cloneWebsite($data);
            } catch (\Exception $e) {
                $this->addFlash('error', 'Une erreur est survenue lors du clonage du site : ' . $e->getMessage());
            }
        }

        return $this->render('website_clone/index.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}