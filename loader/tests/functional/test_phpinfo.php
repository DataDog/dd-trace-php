<?php

require_once __DIR__."/includes/autoload.php";
skip_if_php5();

$output = runCLI('-i', true);

if ('7.0' === php_minor_version()) {
    assertMatchesFormat($output, <<<EOT
%A
dd_library_loader_mod

  => Datadog Library Loader
Version => %s
Author => Datadog

  => ddtrace
Version => %s
Injection success => true
Injection error =>
Extra config => datadog.trace.sources_path=%s/trace/src

Logs => Found extension file: %s
%A
Application instrumentation bootstrapping complete ('ddtrace')
%A

  => datadog-profiling
Version =>
Injection success => false
Injection error => Incompatible runtime
Extra config =>
Logs => Aborting application instrumentation due to an incompatible runtime
'datadog-profiling' extension is not supported on this PHP version
%A

  => ddappsec
Version => %s
Injection success => true
Injection error =>
Extra config => datadog.appsec.helper_path=%s

Logs => Found extension file: %s
%A
Application instrumentation bootstrapping complete ('ddappsec')
%A
EOT
    );
} else {
    assertMatchesFormat($output, <<<EOT
%A
dd_library_loader_mod

  => Datadog Library Loader
Version => %s
Author => Datadog

  => ddtrace
Version => %s
Injection success => true
Injection error =>
Extra config => datadog.trace.sources_path=%s/trace/src

Logs => Found extension file: %s
%A
Application instrumentation bootstrapping complete ('ddtrace')
%A

  => datadog-profiling
Version => %s
Injection success => true
Injection error =>
Extra config => datadog.profiling.enabled=0

Logs => Found extension file: %s
%A
Application instrumentation bootstrapping complete ('datadog-profiling')
%A

  => ddappsec
Version => %s
Injection success => true
Injection error =>
Extra config => datadog.appsec.helper_path=%s

Logs => Found extension file: %s
%A
Application instrumentation bootstrapping complete ('ddappsec')
%A
EOT
    );
}
