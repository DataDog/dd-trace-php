<?php

namespace DDTrace\Integrations\GraphQL;

use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use GraphQL\Language\AST\OperationDefinitionNode;

class GraphQLIntegration extends Integration
{
    const NAME = 'graphql';

    public function init(): int
    {
        $integration = $this;

        \DDTrace\trace_method(
            'GraphQL\Executor\Executor',
            'promiseToExecute',
            function (SpanData $span, $args) use ($integration) {
                $span->name = 'graphql.execute';
                $span->type = Type::GRAPHQL;
                $span->meta[Tag::COMPONENT] = GraphQLIntegration::NAME;

                // Args are:
                // 0: PromiseAdapter $promiseAdapter
                // 1: Schema $schema
                // 2: DocumentNode $documentNode
                // 3: $rootValue = null
                // 4: $contextValue = null
                // 5: ?array $variableValues = null
                // 6: ?string $operationName = null
                // 7: ?callable $fieldResolver = null
                // 8: ?callable $argsMapper = null

                // Set graphql.source from the document node
                if (isset($args[2]) && isset($args[2]->loc) && isset($args[2]->loc->source)) {
                    $span->meta['graphql.source'] = $args[2]->loc->source->body;
                }

                // Find the operation definition
                if (isset($args[2]) && isset($args[2]->definitions)) {
                    $operationName = $args[6] ?? null;
                    $operationDefinition = null;

                    // If operation name is provided, find the matching operation
                    if ($operationName !== null) {
                        foreach ($args[2]->definitions as $definition) {
                            if ($definition instanceof OperationDefinitionNode &&
                                isset($definition->name) &&
                                $definition->name->value === $operationName) {
                                $operationDefinition = $definition;
                                break;
                            }
                        }
                    }

                    // If no operation name or no matching operation found, use the first operation definition
                    if ($operationDefinition === null) {
                        foreach ($args[2]->definitions as $definition) {
                            if ($definition instanceof OperationDefinitionNode) {
                                $operationDefinition = $definition;
                                break;
                            }
                        }
                    }

                    // Set operation type and name if we found an operation definition
                    if ($operationDefinition !== null) {
                        if (isset($operationDefinition->operation)) {
                            $span->meta['graphql.operation.type'] = $operationDefinition->operation;
                        }
                        if (isset($operationDefinition->name->value)) {
                            $span->meta['graphql.operation.name'] = $operationDefinition->name->value;
                        }
                    }
                }

                // Set graphql.variables from the variable values
                if (isset($args[5])) {
                    foreach ($args[5] as $key => $value) {
                        $span->meta["graphql.variables.$key"] = is_scalar($value) ? $value : json_encode($value);
                    }
                }
            }
        );

        \DDTrace\trace_method(
            'GraphQL\Validator\DocumentValidator',
            'validate',
            function (SpanData $span, $args) use ($integration) {
                $span->name = 'graphql.validate';
                $span->type = Type::GRAPHQL;
                $span->meta[Tag::COMPONENT] = GraphQLIntegration::NAME;

                // Args are:
                // 0: Schema $schema
                // 1: DocumentNode $ast
                // 2: array $rules = null
                // 3: array $typeInfo = null

                // Set graphql.source from the document node
                if (isset($args[1]) && isset($args[1]->loc) && isset($args[1]->loc->source)) {
                    $span->meta['graphql.source'] = $args[1]->loc->source->body;
                }
            }
        );

        \DDTrace\trace_method(
            'GraphQL\Language\Parser',
            'parse',
            function (SpanData $span, $args) use ($integration) {
                $span->name = 'graphql.parse';
                $span->type = Type::GRAPHQL;
                $span->meta[Tag::COMPONENT] = GraphQLIntegration::NAME;

                // Args are:
                // 0: Source|string $source
                // 1: array $options = []

                // Set graphql.source
                if (isset($args[0])) {
                    if (is_string($args[0])) {
                        $span->meta['graphql.source'] = $args[0];
                    } elseif (is_object($args[0]) && isset($args[0]->body)) {
                        $span->meta['graphql.source'] = $args[0]->body;
                    }
                }
            }
        );

        return Integration::LOADED;
    }
}