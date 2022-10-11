<?php
function ddtrace_version_at_least($version) {
    $ddtrace_version = phpversion('ddtrace');
    if (version_compare($version, $ddtrace_version) > 0) {
        echo "Error: ddtrace required version >= $version; got $ddtrace_version".PHP_EOL;;
    }
}
