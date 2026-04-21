<?php
$loaded = extension_loaded('ddtrace');
echo "<h2>Datadog extension: " . ($loaded ? 'loaded ✓' : 'NOT loaded ✗') . "</h2>\n";
phpinfo();
