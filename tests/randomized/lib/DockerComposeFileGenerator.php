<?php

namespace RandomizedTests\Tooling;

require_once __DIR__ . '/Utils.php';

class DockerComposeFileGenerator
{
    public function generate($destination, array $substitutions)
    {
        Utils::writeTemplate(
            $destination,
            __DIR__ . '/templates/docker-compose.template.yml',
            $substitutions
        );
    }
}
