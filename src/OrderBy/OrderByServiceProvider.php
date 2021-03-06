<?php

namespace Nuwave\Lighthouse\OrderBy;

use GraphQL\Language\AST\InputObjectTypeDefinitionNode;
use GraphQL\Language\Parser;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Nuwave\Lighthouse\Events\ManipulateAST;
use Nuwave\Lighthouse\Events\RegisterDirectiveNamespaces;

class OrderByServiceProvider extends ServiceProvider
{
    public const DEFAULT_ORDER_BY_CLAUSE = 'OrderByClause';

    /**
     * Bootstrap any application services.
     */
    public function boot(Dispatcher $dispatcher): void
    {
        $dispatcher->listen(
            RegisterDirectiveNamespaces::class,
            function (RegisterDirectiveNamespaces $registerDirectiveNamespaces): string {
                return __NAMESPACE__;
            }
        );

        $dispatcher->listen(
            ManipulateAST::class,
            function (ManipulateAST $manipulateAST): void {
                $manipulateAST->documentAST
                    ->setTypeDefinition(
                        Parser::enumTypeDefinition(/** @lang GraphQL */ '
                            "The available directions for ordering a list of records."
                            enum SortOrder {
                                "Sort records in ascending order."
                                ASC

                                "Sort records in descending order."
                                DESC
                            }
                        '
                        )
                    )
                    ->setTypeDefinition(
                        static::createOrderByClauseInput(
                            static::DEFAULT_ORDER_BY_CLAUSE,
                            'Allows ordering a list of records.',
                            'String'
                        )
                    );
            }
        );
    }

    public static function createOrderByClauseInput(string $name, string $description, string $columnType): InputObjectTypeDefinitionNode
    {
        return Parser::inputObjectTypeDefinition(/** @lang GraphQL */ <<<GRAPHQL
            "$description"
            input $name {
                "The column that is used for ordering."
                column: $columnType!

                "The direction that is used for ordering."
                order: SortOrder!
            }
GRAPHQL
        );
    }
}
