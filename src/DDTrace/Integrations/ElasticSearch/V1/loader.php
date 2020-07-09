<?php

namespace DDTrace\Integrations\ElasticSearch\V1;

function load()
{
    $es = new ElasticSearchSandboxedIntegration();
    $es->init();
}
