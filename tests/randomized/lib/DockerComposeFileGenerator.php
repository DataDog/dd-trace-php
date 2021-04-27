<?php

namespace RandomizedTests\Tooling;

class DockerComposeFileGenerator
{
    public function generate($destination, array $substitutions)
    {
        $needles = \array_map(
            function ($key) {
                return "{{{$key}}}";
            },
            array_keys($substitutions)
        );
        $replaces = array_values($substitutions);

        file_put_contents(
            $destination,
            \str_replace(
                $needles,
                $replaces,
                file_get_contents(__DIR__ . '/templates/docker-compose.template.yml')
            )
        );
    }
}
