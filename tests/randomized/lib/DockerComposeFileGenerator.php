<?php

namespace RandomizedTests\Tooling;

class DockerComposeFileGenerator
{
    public function generate($destination, array $substitutionsByIdentifier)
    {
        $services = "";
        $serviceTemplate = file_get_contents(__DIR__ . '/templates/docker-compose.service.template.yml');
        foreach ($substitutionsByIdentifier as $identifier => $substitutions) {
            $needles = \array_map(
                function ($key) {
                    return "{{{$key}}}";
                },
                array_keys($substitutions)
            );
            $replaces = array_values($substitutions);
            $services .= str_replace(
                $needles,
                $replaces,
                $serviceTemplate
            ) . "\n";
        }
        $dockerComposeFile = \str_replace(
            '{{services}}',
            $services,
            file_get_contents(__DIR__ . '/templates/docker-compose.template.yml')
        );
        file_put_contents($destination, $dockerComposeFile);
    }
}
