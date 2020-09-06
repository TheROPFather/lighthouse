<?php

namespace Nuwave\Lighthouse\Console;

use GraphQL\Language\Parser;
use GraphQL\Language\Printer;
use GraphQL\Type\Definition\Directive as DirectiveDefinition;
use GraphQL\Type\Definition\Type;
use GraphQL\Utils\SchemaPrinter;
use HaydenPierce\ClassFinder\ClassFinder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Nuwave\Lighthouse\Schema\DirectiveLocator;
use Nuwave\Lighthouse\Schema\TypeRegistry;
use Nuwave\Lighthouse\Support\Contracts\Directive;

class IdeHelperCommand extends Command
{
    public const OPENING_PHP_TAG = "<?php\n";

    public const GENERATED_NOTICE = <<<'SDL'
# File generated by "php artisan lighthouse:ide-helper".
# Do not edit this file directly.
# This file should be ignored by git as it can be autogenerated.

SDL;

    protected $name = 'lighthouse:ide-helper';

    protected $description = 'Create IDE helper files to improve type checking and autocompletion.';

    public function handle(DirectiveLocator $directiveLocator, TypeRegistry $typeRegistry): int
    {
        $this->schemaDirectiveDefinitions($directiveLocator);
        $this->programmaticTypes($typeRegistry);
        $this->phpIdeHelper();

        $this->info("\nIt is recommended to add them to your .gitignore file.");

        return 0;
    }

    /**
     * Create and write schema directive definitions to a file.
     */
    protected function schemaDirectiveDefinitions(DirectiveLocator $directiveLocator): void
    {
        $directiveClasses = $this->scanForDirectives(
            $directiveLocator->namespaces()
        );

        $schema = $this->buildSchemaString($directiveClasses);

        $filePath = static::schemaDirectivesPath();
        \Safe\file_put_contents($filePath, self::GENERATED_NOTICE.$schema);

        $this->info("Wrote schema directive definitions to $filePath.");
    }

    /**
     * Scan the given namespaces for directive classes.
     *
     * @param  array<string>  $directiveNamespaces
     * @return array<string, class-string<\Nuwave\Lighthouse\Support\Contracts\Directive>>
     */
    protected function scanForDirectives(array $directiveNamespaces): array
    {
        $directives = [];

        foreach ($directiveNamespaces as $directiveNamespace) {
            /** @var array<class-string> $classesInNamespace */
            $classesInNamespace = ClassFinder::getClassesInNamespace($directiveNamespace);

            foreach ($classesInNamespace as $class) {
                $reflection = new \ReflectionClass($class);
                if (! $reflection->isInstantiable()) {
                    continue;
                }

                if (! is_a($class, Directive::class, true)) {
                    continue;
                }
                /** @var class-string<\Nuwave\Lighthouse\Support\Contracts\Directive> $class */
                $name = DirectiveLocator::directiveName($class);

                // The directive was already found, so we do not add it twice
                if (isset($directives[$name])) {
                    continue;
                }

                $directives[$name] = $class;
            }
        }

        return $directives;
    }

    /**
     * @param  array<string, class-string<\Nuwave\Lighthouse\Support\Contracts\Directive>>  $directiveClasses
     */
    protected function buildSchemaString(array $directiveClasses): string
    {
        // We include this built in directive by hand, since there is no directive class for it
        $schema = /** @lang GraphQL */ <<<'SDL'
"""
Marks an element of a GraphQL schema as no longer supported.
"""
directive @deprecated(
  """
  Explains why this element was deprecated, usually also including a
  suggestion for how to access supported similar data. Formatted
  in [Markdown](https://daringfireball.net/projects/markdown/).
  """
  reason: String = "No longer supported"
) on FIELD_DEFINITION


SDL;

        foreach ($directiveClasses as $directiveClass) {
            $definition = $this->define($directiveClass);

            $schema .= "# Directive class: $directiveClass\n"
                .$definition."\n"
                . "\n";
        }

        return $schema;
    }

    protected function define(string $directiveClass): string
    {
        /** @var \Nuwave\Lighthouse\Support\Contracts\Directive $directiveClass */
        $definition = $directiveClass::definition();

        // This operation throws if the schema definition is invalid
        Parser::directiveDefinition($definition);

        return trim($definition);
    }

    public static function schemaDirectivesPath(): string
    {
        return base_path().'/schema-directives.graphql';
    }

    protected function programmaticTypes(TypeRegistry $typeRegistry): void
    {
        // Users may register types programmatically, e.g. in service providers
        // In order to allow referencing those in the schema, it is useful to print
        // those types to a helper schema, excluding types the user defined in the schema
        $types = new Collection($typeRegistry->resolvedTypes());

        $filePath = static::programmaticTypesPath();

        if ($types->isEmpty() && file_exists($filePath)) {
            \Safe\unlink($filePath);

            return;
        }

        $schema = $types
            ->map(function (Type $type): string {
                return SchemaPrinter::printType($type);
            })
            ->implode("\n");

        \Safe\file_put_contents($filePath, self::GENERATED_NOTICE.$schema);

        $this->info("Wrote definitions for programmatically registered types to $filePath.");
    }

    public static function programmaticTypesPath(): string
    {
        return base_path().'/programmatic-types.graphql';
    }

    protected function phpIdeHelper(): void
    {
        $filePath = static::phpIdeHelperPath();
        $contents = \Safe\file_get_contents(__DIR__.'/../../_ide_helper.php');

        \Safe\file_put_contents($filePath, $this->withGeneratedNotice($contents));

        $this->info("Wrote PHP definitions to $filePath.");
    }

    public static function phpIdeHelperPath(): string
    {
        return base_path().'/_lighthouse_ide_helper.php';
    }

    protected function withGeneratedNotice(string $phpContents): string
    {
        return substr_replace(
            $phpContents,
            self::OPENING_PHP_TAG.self::GENERATED_NOTICE,
            0,
            strlen(self::OPENING_PHP_TAG)
        );
    }
}
