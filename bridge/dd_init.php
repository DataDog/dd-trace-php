<?php

namespace DDTrace\Bridge;

use DDTrace\Bootstrap;
use DDTrace\Integrations\IntegrationsLoader;

Bootstrap::once();
IntegrationsLoader::load();
