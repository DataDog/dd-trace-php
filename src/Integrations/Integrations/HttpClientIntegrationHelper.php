<?php

namespace DDTrace\Integrations;

use DDTrace\Tag;

class HttpClientIntegrationHelper
{
    const PEER_SERVICE_SOURCES = [
        Tag::NETWORK_DESTINATION_NAME,
        Tag::TARGET_HOST,
    ];
}
