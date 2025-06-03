<?php

namespace App\Service;

class StubProcessor
{
    private const STUB_DIR = __DIR__ . '/Stubs/';
    private array $frontendData = [];
    private ?string $controllerCode = null;
    private array $entityFields = [];
    private array $detectedEntities = [];
    private array $entityCode = [];
    private array $repositoryCode = [];
    private array $formTypeCode = [];
    
    public function setFrontendData(array $frontendData): void
    {
        $this->frontendData = $frontendData;
    }

    public function setControllerCode(?string $code): void
    {
        $this->controllerCode = $code;
    }

    public function setEntityCode(array $code): void
    {
        $this->entityCode = $code;
    }

    public function setRepositoryCode(array $code): void
    {
        $this->repositoryCode = $code;
    }

    public function setFormTypeCode(array $code): void
    {
        $this->formTypeCode = $code;
    }

    public function processStub(string $stubName, array $replacements): string
    {
        $stubPath = self::STUB_DIR . $stubName . '.stub';
        if (!file_exists($stubPath)) {
            throw new \RuntimeException(sprintf('Stub file not found: %s', $stubPath));
        }

        // Vérifier si nous avons du code généré pour ce type de fichier
        if ($stubName === 'controller' && $this->controllerCode !== null) {
            return $this->controllerCode;
        } elseif ($stubName === 'entity' && isset($this->entityCode[$replacements['entity_name']])) {
            return $this->entityCode[$replacements['entity_name']];
        } elseif ($stubName === 'repository' && isset($this->repositoryCode[$replacements['entity_name'] . 'Repository'])) {
            return $this->repositoryCode[$replacements['entity_name'] . 'Repository'];
        } elseif ($stubName === 'form' && isset($this->formTypeCode[$replacements['entity_name'] . 'Type'])) {
            return $this->formTypeCode[$replacements['entity_name'] . 'Type'];
        }

        $content = "<?php\n\n" . file_get_contents($stubPath);
        
        // Ajouter des méthodes dynamiques basées sur le front-end
        if ($stubName === 'controller' && !empty($this->frontendData)) {
            $content = $this->addDynamicMethods($content);
        }

        foreach ($replacements as $placeholder => $value) {
            $content = str_replace('{{' . $placeholder . '}}', $value, $content);
        }

        // Vérifier qu'il ne reste pas de placeholders non remplacés
        if (preg_match('/{{{\w+}}}/', $content, $matches)) {
            throw new \RuntimeException(sprintf('Placeholder non remplacé trouvé: %s', $matches[0]));
        }

        return $content;
    }

    private function addDynamicMethods(string $content): string
    {
        $generatedMethods = [];
        $entityFields = [];
        
        // Analyser les templates Twig pour identifier les actions et les champs d'entité
        foreach ($this->frontendData as $filename => $fileContent) {
            if (str_ends_with($filename, '.html.twig')) {
                // Extraire les formulaires et les actions
                preg_match_all('/{%\s*form_start\s*\(\s*form\s*,\s*{[^}]*}\s*\)\s*%}/', $fileContent, $formStarts);
                preg_match_all('/{%\s*form_row\s*\(\s*form\.([^\s}]+)\s*\)\s*%}/', $fileContent, $formFields);
                
                foreach ($formStarts[0] as $index => $formStart) {
                    // Détecter le nom de l'entité à partir du nom du template
                    $entityName = $this->detectEntityNameFromFilename($filename);
                    if ($entityName) {
                        $fields = [];
                        
                        // Extraire les champs du formulaire
                        foreach ($formFields[1] as $field) {
                            $fields[$field] = $this->guessFieldType($field, $fileContent);
                        }
                        
                        if (!empty($fields)) {
                            $this->detectedEntities[$entityName] = $fields;
                            
                            // Générer les méthodes du contrôleur pour ce formulaire
                            $generatedMethods[] = $this->generateFormControllerMethod($entityName, $fields);
                        }
                    }
                }
            }
        }
        
        // Stocker les champs d'entité pour une utilisation ultérieure
        $this->entityFields = $this->detectedEntities;
        
        // Insérer les méthodes générées avant la dernière accolade
        if (!empty($generatedMethods)) {
            $lastBrace = strrpos($content, '}');
            $content = substr_replace($content, "\n    " . implode("\n\n    ", $generatedMethods) . "\n", $lastBrace, 0);
        }
        
        return $content;
    }

    private function detectEntityNameFromFilename(string $filename): ?string {
        // Extraire le nom de l'entité du nom du fichier (ex: contact/new.html.twig -> Contact)
        if (preg_match('/([^\/]+)\/(?:new|edit|create)\.html\.twig$/', $filename, $matches)) {
            return ucfirst($matches[1]);
        }
        return null;
    }

    private function generateFormControllerMethod(string $entityName, array $fields): string {
        $lcEntityName = strtolower($entityName);
        return "#[Route('/{$lcEntityName}/new', name: 'app_{$lcEntityName}_new', methods: ['GET', 'POST'])]\n" .
               "    public function new(Request \$request, EntityManagerInterface \$entityManager): Response\n" .
               "    {\n" .
               "        \$entity = new {$entityName}();\n" .
               "        \$form = \$this->createForm({$entityName}Type::class, \$entity);\n" .
               "        \$form->handleRequest(\$request);\n\n" .
               "        if (\$form->isSubmitted() && \$form->isValid()) {\n" .
               "            \$entityManager->persist(\$entity);\n" .
               "            \$entityManager->flush();\n\n" .
               "            \$this->addFlash('success', '{$entityName} créé avec succès');\n" .
               "            return \$this->redirectToRoute('app_{$lcEntityName}_index');\n" .
               "        }\n\n" .
               "        return \$this->render('{$lcEntityName}/new.html.twig', [\n" .
               "            'entity' => \$entity,\n" .
               "            'form' => \$form->createView(),\n" .
               "        ]);\n" .
               "    }";
    }

    private function generateActionName(string $route): string
    {
        $name = str_replace(['/', '-', '_'], ' ', trim($route, '/'));
        return lcfirst(str_replace(' ', '', ucwords($name)));
    }

    private function generateControllerMethod(string $actionName, string $method, string $route): string
    {
        return "#[Route('$route', name: 'app_{{route_prefix}}_$actionName', methods: ['$method'])]\n" .
               "    public function $actionName(Request \$request): Response\n" .
               "    {\n" .
               "        // TODO: Implement $actionName method\n" .
               "        return \$this->render('{{template_prefix}}/$actionName.html.twig', [\n" .
               "            'controller_name' => '{{entity_name}}Controller',\n" .
               "        ]);\n" .
               "    }";
    }

    private function guessFieldType(string $fieldName, string $fileContent): string
    {
        // Détecter le type en fonction du nom du champ et des attributs HTML
        if (preg_match('/type="([^"]+)"[^>]*name="' . preg_quote($fieldName, '/') . '"/', $fileContent, $matches)) {
            switch ($matches[1]) {
                case 'number':
                case 'range':
                    return 'integer';
                case 'email':
                    return 'string';
                case 'date':
                    return '\\DateTimeInterface';
                case 'checkbox':
                    return 'boolean';
                case 'file':
                    return 'string'; // Pour stocker le chemin du fichier
                default:
                    return 'string';
            }
        }

        // Détecter le type en fonction du nom du champ
        if (str_contains($fieldName, 'email')) return 'string';
        if (str_contains($fieldName, 'date')) return '\\DateTimeInterface';
        if (str_contains($fieldName, 'price') || str_contains($fieldName, 'amount')) return 'float';
        if (str_contains($fieldName, 'count') || str_contains($fieldName, 'number') || str_contains($fieldName, 'id')) return 'integer';
        if (str_contains($fieldName, 'is_') || str_contains($fieldName, 'has_')) return 'boolean';

        // Type par défaut
        return 'string';
    }

    public function getEntityFields(): array
    {
        return $this->detectedEntities;
    }

    public function generateEntityReplacements(string $entityName): array
    {
        $replacements = [
            'namespace' => 'App',
            'entity_name' => $entityName,
            'route_prefix' => strtolower($entityName),
            'template_prefix' => strtolower($entityName),
            'entity_var' => lcfirst($entityName),
            'entity_var_plural' => lcfirst($entityName) . 's',
        ];

        // Générer les propriétés de l'entité
        $properties = [];
        $gettersSetters = [];
        $formFields = [];

        if (isset($this->detectedEntities[$entityName])) {
            foreach ($this->detectedEntities[$entityName] as $fieldName => $fieldType) {
                // Propriété de l'entité
                $properties[] = "    #[ORM\Column(type: '{$this->getDoctrineType($fieldType)}')]"
                    . "\n    private ?{$fieldType} \${$fieldName} = null;";

                // Getter
                $gettersSetters[] = "    public function get" . ucfirst($fieldName) . "(): ?{$fieldType}"
                    . "\n    {"
                    . "\n        return \$this->{$fieldName};"
                    . "\n    }";

                // Setter
                $gettersSetters[] = "    public function set" . ucfirst($fieldName) . "(?{$fieldType} \${$fieldName}): static"
                    . "\n    {"
                    . "\n        \$this->{$fieldName} = \${$fieldName};"
                    . "\n        return \$this;"
                    . "\n    }";

                // Champ de formulaire
                $formFields[] = "            ->add('{$fieldName}', {$this->getFormType($fieldType)})";
            }
        }

        $replacements['properties'] = !empty($properties) ? implode("\n\n", $properties) : '';
        $replacements['getters_setters'] = !empty($gettersSetters) ? implode("\n\n", $gettersSetters) : '';
        $replacements['form_fields'] = !empty($formFields) ? implode("\n", $formFields) : '';

        return $replacements;
    }

    private function getDoctrineType(string $phpType): string
    {
        return match($phpType) {
            'int', 'integer' => 'integer',
            'float' => 'float',
            'bool', 'boolean' => 'boolean',
            '\DateTimeInterface' => 'datetime',
            default => 'string',
        };
    }

    private function getFormType(string $phpType): string
    {
        return match($phpType) {
            'int', 'integer' => 'IntegerType::class',
            'float' => 'NumberType::class',
            'bool', 'boolean' => 'CheckboxType::class',
            '\DateTimeInterface' => 'DateType::class',
            default => 'TextType::class',
        };
    }
}