<?php

echo "preload ini ok: ", ini_get('datadog.profiling.log_level') === 'error' ? 'yes' : 'no', PHP_EOL;
