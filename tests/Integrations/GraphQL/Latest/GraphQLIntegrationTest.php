<?php

namespace DDTrace\Tests\Integrations\GraphQL\Latest;

use DDTrace\Tag;
use DDTrace\Tests\Common\SnapshotTestTrait;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;
use GraphQL\GraphQL;
use GraphQL\Type\Schema;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

class GraphQLIntegrationTest extends IntegrationTestCase
{
    use SnapshotTestTrait;

    public function testBasicQuery()
    {
        $traces = $this->isolateTracer(function () {
            $schema = new Schema([
                'query' => new ObjectType([
                    'name' => 'Query',
                    'fields' => [
                        'hello' => [
                            'type' => Type::string(),
                            'resolve' => function () {
                                return 'world';
                            }
                        ]
                    ]
                ])
            ]);

            $query = 'query { hello }';
            $result = GraphQL::executeQuery($schema, $query);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('graphql.parse', 'phpunit', 'graphql', 'graphql.parse')
                ->withExactTags([
                    'graphql.source' => 'query { hello }',
                    Tag::COMPONENT => 'graphql'
                ]),
            SpanAssertion::build('graphql.validate', 'phpunit', 'graphql', 'graphql.validate')
                ->withExactTags([
                    'graphql.source' => 'query { hello }',
                    Tag::COMPONENT => 'graphql'
                ]),
            SpanAssertion::build('graphql.execute', 'phpunit', 'graphql', 'graphql.execute')
                ->withExactTags([
                    'graphql.source' => 'query { hello }',
                    'graphql.operation.type' => 'query',
                    Tag::COMPONENT => 'graphql'
                ])
        ]);
    }

    public function testQueryWithVariables()
    {
        $traces = $this->isolateTracer(function () {
            $schema = new Schema([
                'query' => new ObjectType([
                    'name' => 'Query',
                    'fields' => [
                        'greet' => [
                            'type' => Type::string(),
                            'args' => [
                                'name' => ['type' => Type::string()]
                            ],
                            'resolve' => function ($root, $args) {
                                return "Hello, {$args['name']}!";
                            }
                        ]
                    ]
                ])
            ]);

            $query = 'query Greet($name: String!) { greet(name: $name) }';
            $variables = ['name' => 'World'];
            $result = GraphQL::executeQuery($schema, $query, null, null, $variables);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('graphql.parse', 'phpunit', 'graphql', 'graphql.parse')
                ->withExactTags([
                    'graphql.source' => 'query Greet($name: String!) { greet(name: $name) }',
                    Tag::COMPONENT => 'graphql'
                ]),
            SpanAssertion::build('graphql.validate', 'phpunit', 'graphql', 'graphql.validate')
                ->withExactTags([
                    'graphql.source' => 'query Greet($name: String!) { greet(name: $name) }',
                    Tag::COMPONENT => 'graphql'
                ]),
            SpanAssertion::build('graphql.execute', 'phpunit', 'graphql', 'graphql.execute')
                ->withExactTags([
                    'graphql.source' => 'query Greet($name: String!) { greet(name: $name) }',
                    'graphql.operation.type' => 'query',
                    'graphql.operation.name' => 'Greet',
                    'graphql.variables.name' => 'World',
                    Tag::COMPONENT => 'graphql'
                ])
        ]);
    }

    public function testMutation()
    {
        $traces = $this->isolateTracer(function () {
            $schema = new Schema([
                'mutation' => new ObjectType([
                    'name' => 'Mutation',
                    'fields' => [
                        'createUser' => [
                            'type' => Type::string(),
                            'args' => [
                                'name' => ['type' => Type::string()]
                            ],
                            'resolve' => function ($root, $args) {
                                return "Created user: {$args['name']}";
                            }
                        ]
                    ]
                ])
            ]);

            $query = 'mutation CreateUser($name: String!) { createUser(name: $name) }';
            $variables = ['name' => 'John'];
            $result = GraphQL::executeQuery($schema, $query, null, null, $variables);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('graphql.parse', 'phpunit', 'graphql', 'graphql.parse')
                ->withExactTags([
                    'graphql.source' => 'mutation CreateUser($name: String!) { createUser(name: $name) }',
                    Tag::COMPONENT => 'graphql'
                ]),
            SpanAssertion::build('graphql.validate', 'phpunit', 'graphql', 'graphql.validate')
                ->withExactTags([
                    'graphql.source' => 'mutation CreateUser($name: String!) { createUser(name: $name) }',
                    Tag::COMPONENT => 'graphql'
                ]),
            SpanAssertion::build('graphql.execute', 'phpunit', 'graphql', 'graphql.execute')
                ->withExactTags([
                    'graphql.source' => 'mutation CreateUser($name: String!) { createUser(name: $name) }',
                    'graphql.operation.type' => 'mutation',
                    'graphql.operation.name' => 'CreateUser',
                    'graphql.variables.name' => 'John',
                    Tag::COMPONENT => 'graphql'
                ])
        ]);
    }
}