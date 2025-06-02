<?php

namespace App\Service;

use Symfony\Component\String\UnicodeString;

class BackendGeneratorService
{
    private const ENTITY_TEMPLATE = <<<'PHP'
<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: %repository_class%::class)]
class %class_name%
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

%properties%

%getters_setters%
}
PHP;

    private const REPOSITORY_TEMPLATE = <<<'PHP'
<?php

namespace App\Repository;

use App\Entity\%class_name%;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class %class_name%Repository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, %class_name%::class);
    }
}
PHP;

    private const FORM_TYPE_TEMPLATE = <<<'PHP'
<?php

namespace App\Form;

use App\Entity\%class_name%;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class %class_name%Type extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
%form_fields%
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => %class_name%::class,
        ]);
    }
}
PHP;

    private const CONTROLLER_TEMPLATE = <<<'PHP'
<?php

namespace App\Controller;

use App\Entity\%class_name%;
use App\Form\%class_name%Type;
use App\Repository\%class_name%Repository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/%route_prefix%')]
class %class_name%Controller extends AbstractController
{
    #[Route('/', name: '%route_prefix%_index', methods: ['GET'])]
    public function index(%class_name%Repository $repository): Response
    {
        return \$this->render('%template_prefix%/index.html.twig', [
            '%entity_var_plural%' => \$repository->findAll(),
        ]);
    }

    #[Route('/new', name: '%route_prefix%_new', methods: ['GET', 'POST'])]
    public function new(Request \$request, EntityManagerInterface \$entityManager): Response
    {
        \$%entity_var% = new %class_name%();
        \$form = \$this->createForm(%class_name%Type::class, \$%entity_var%);
        \$form->handleRequest(\$request);

        if (\$form->isSubmitted() && \$form->isValid()) {
            \$entityManager->persist(\$%entity_var%);
            \$entityManager->flush();

            return \$this->redirectToRoute('%route_prefix%_index');
        }

        return \$this->render('%template_prefix%/new.html.twig', [
            '%entity_var%' => \$%entity_var%,
            'form' => \$form,
        ]);
    }

    #[Route('/{id}', name: '%route_prefix%_show', methods: ['GET'])]
    public function show(%class_name% \$%entity_var%): Response
    {
        return \$this->render('%template_prefix%/show.html.twig', [
            '%entity_var%' => \$%entity_var%,
        ]);
    }

    #[Route('/{id}/edit', name: '%route_prefix%_edit', methods: ['GET', 'POST'])]
    public function edit(Request \$request, %class_name% \$%entity_var%, EntityManagerInterface \$entityManager): Response
    {
        \$form = \$this->createForm(%class_name%Type::class, \$%entity_var%);
        \$form->handleRequest(\$request);

        if (\$form->isSubmitted() && \$form->isValid()) {
            \$entityManager->flush();

            return \$this->redirectToRoute('%route_prefix%_index');
        }

        return \$this->render('%template_prefix%/edit.html.twig', [
            '%entity_var%' => \$%entity_var%,
            'form' => \$form,
        ]);
    }

    #[Route('/{id}', name: '%route_prefix%_delete', methods: ['POST'])]
    public function delete(Request \$request, %class_name% \$%entity_var%, EntityManagerInterface \$entityManager): Response
    {
        if (\$this->isCsrfTokenValid('delete'.\$%entity_var%->getId(), \$request->request->get('_token'))) {
            \$entityManager->remove(\$%entity_var%);
            \$entityManager->flush();
        }

        return \$this->redirectToRoute('%route_prefix%_index');
    }
}
PHP;

    public function generateBackend(array $twigFiles): array
    {
        $generatedFiles = [];

        foreach ($twigFiles as $filename => $content) {
            if (!str_ends_with($filename, '.html.twig')) {
                continue;
            }

            $entityInfo = $this->analyzeTemplate($content);
            if (empty($entityInfo)) {
                continue;
            }

            $generatedFiles = array_merge(
                $generatedFiles,
                $this->generateEntity($entityInfo),
                $this->generateRepository($entityInfo),
                $this->generateFormType($entityInfo),
                $this->generateController($entityInfo)
            );
        }

        return $generatedFiles;
    }

    private function analyzeTemplate(string $content): array
    {
        $entityInfo = [];
        
        // Analyse des formulaires pour déduire les entités et leurs champs
        preg_match_all('/{{ ?form_row\(form\.(\w+)\) ?}}/', $content, $matches);
        if (!empty($matches[1])) {
            $entityInfo['fields'] = array_unique($matches[1]);
        }

        // Analyse des boucles for pour déduire les noms d'entités
        preg_match('/{% ?for (\w+) in (\w+) ?%}/', $content, $matches);
        if (!empty($matches[2])) {
            $entityInfo['name'] = (new UnicodeString($matches[2]))
                ->trimEnd('s')
                ->camel()->title();
        }

        return $entityInfo;
    }

    private function generateEntity(array $entityInfo): array
    {
        $properties = [];
        $gettersSetters = [];

        foreach ($entityInfo['fields'] as $field) {
            $properties[] = sprintf("    #[ORM\Column(length: 255)]\n    private ?string $%s = null;", $field);
            
            // Getter
            $gettersSetters[] = sprintf("
    public function get%s(): ?string
    {
        return \$this->%s;
    }", ucfirst($field), $field);
            
            // Setter
            $gettersSetters[] = sprintf("
    public function set%s(?string $%s): static
    {
        \$this->%s = $%s;
        return \$this;
    }", ucfirst($field), $field, $field, $field);
        }

        $entityContent = str_replace(
            ['%class_name%', '%repository_class%', '%properties%', '%getters_setters%'],
            [
                $entityInfo['name'],
                $entityInfo['name'] . 'Repository',
                implode("\n\n", $properties),
                implode("\n", $gettersSetters)
            ],
            self::ENTITY_TEMPLATE
        );

        return [
            'src/Entity/' . $entityInfo['name'] . '.php' => $entityContent
        ];
    }

    private function generateRepository(array $entityInfo): array
    {
        $repositoryContent = str_replace(
            '%class_name%',
            $entityInfo['name'],
            self::REPOSITORY_TEMPLATE
        );

        return [
            'src/Repository/' . $entityInfo['name'] . 'Repository.php' => $repositoryContent
        ];
    }

    private function generateFormType(array $entityInfo): array
    {
        $formFields = [];
        foreach ($entityInfo['fields'] as $field) {
            $formFields[] = sprintf("        \$builder->add('%s');", $field);
        }

        $formTypeContent = str_replace(
            ['%class_name%', '%form_fields%'],
            [
                $entityInfo['name'],
                implode("\n", $formFields)
            ],
            self::FORM_TYPE_TEMPLATE
        );

        return [
            'src/Form/' . $entityInfo['name'] . 'Type.php' => $formTypeContent
        ];
    }

    private function generateController(array $entityInfo): array
    {
        $routePrefix = strtolower($entityInfo['name']);
        $entityVar = lcfirst($entityInfo['name']);

        $controllerContent = str_replace(
            [
                '%class_name%',
                '%route_prefix%',
                '%template_prefix%',
                '%entity_var%',
                '%entity_var_plural%'
            ],
            [
                $entityInfo['name'],
                $routePrefix,
                $routePrefix,
                $entityVar,
                $routePrefix . 's'
            ],
            self::CONTROLLER_TEMPLATE
        );

        return [
            'src/Controller/' . $entityInfo['name'] . 'Controller.php' => $controllerContent
        ];
    }
}