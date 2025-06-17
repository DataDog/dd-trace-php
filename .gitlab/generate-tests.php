<?php

include "generate-common.php";

preg_match('(^\.services(.*?)\n(\S|\Z))ms', file_get_contents(__DIR__ . "/generate-common.php"), $m);

preg_match_all('(^  (\S*):)m', $m[1], $m, PREG_PATTERN_ORDER);
$services = array_combine($m[1], $m[1]);

const ASSERT_NO_MEMLEAKS = ' 2>&1 | tee /dev/stderr | { ! grep -qe "=== Total [0-9]+ memory leaks detected ==="; }';

function after_script($execute_dir = ".", $has_test_agent = false) {
?>

  artifacts:
    reports:
      junit: "artifacts/tests/php-tests.xml"
    paths:
      - "artifacts/"
    when: "always"
  after_script:
<?php if ($has_test_agent): ?>
    - .gitlab/check_test_agent.sh
<?php endif; ?>
    - .gitlab/collect_artifacts.sh "<?= $execute_dir ?>"
<?php
}

function sidecar_logs() {
?>
    _DD_DEBUG_SIDECAR_LOG_LEVEL: trace
    _DD_DEBUG_SIDECAR_LOG_METHOD: "file://${CI_PROJECT_DIR}/artifacts/sidecar.log"
<?php
}

function before_script_steps() {
    unset_dd_runner_env_vars();
?>

    - switch-php "${SWITCH_PHP_VERSION}"
    - git config --global --add safe.directory "${CI_PROJECT_DIR}"
    - git config --global --add safe.directory "${CI_PROJECT_DIR}/*"
    - mkdir -p tmp/build_extension/modules artifacts
    - mv "modules/${PHP_MAJOR_MINOR}-${SWITCH_PHP_VERSION}-${host_os}-${ARCH}/ddtrace.so" "tmp/build_extension/modules/"
<?php
}
?>

stages:
  - compile
  - test
  - "integrations test"
  - "web test"

#variables:
#  CI_DEBUG_SERVICES: "true"

<?php function agent_httpbin_service() { ?>
    - !reference [.services, test-agent]
    - !reference [.services, request-replayer]
    - !reference [.services, httpbin-integration]
<?php } ?>

"compile extension: debug":
  stage: compile
  tags: [ "arch:${ARCH}" ]
  image: registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-${PHP_MAJOR_MINOR}_buster
  parallel:
    matrix:
      - PHP_MAJOR_MINOR: *all_minor_major_targets
        ARCH: *arch_targets
  variables:
    host_os: linux-gnu
    SHARED: "1"
    WITH_ASAN: "0"
    CARGO_HOME: "/rust/cargo/"
    SWITCH_PHP_VERSION: "debug"
    KUBERNETES_CPU_REQUEST: 12
    KUBERNETES_MEMORY_REQUEST: 4Gi
    KUBERNETES_MEMORY_LIMIT: 8Gi
  script: .gitlab/compile_extension.sh
  after_script: |
    export out_dir="modules/${PHP_MAJOR_MINOR}-${SWITCH_PHP_VERSION}-${host_os}-${ARCH}/"
    mkdir -p "${out_dir}"
    mv "tmp/build_extension/modules/ddtrace.so" "${out_dir}"
  cache:
    - key:
        prefix: $CI_JOB_NAME
        files:
          - Cargo.lock
          - Cargo.toml
      paths:
        - /rust/cargo/
  artifacts:
    paths:
      - "VERSION"
      - "modules/"

"compile extension: debug-zts-asan":
  extends: "compile extension: debug"
  variables:
    WITH_ASAN: "1"
    SWITCH_PHP_VERSION: "debug-zts-asan"
  parallel:
    matrix:
      - PHP_MAJOR_MINOR: *asan_minor_major_targets
        ARCH: *arch_targets

"windows test_c":
  stage: test
  tags: [ "windows-v2:2019"]
  needs: []
  parallel:
    matrix:
      - PHP_MAJOR_MINOR: <?= json_encode($windows_minor_major_targets) ?>

  variables:
    GIT_CONFIG_COUNT: 1
    GIT_CONFIG_KEY_0: core.longpaths
    GIT_CONFIG_VALUE_0: true
    CONTAINER_NAME: $CI_JOB_NAME_SLUG
    IMAGE: "registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-${PHP_MAJOR_MINOR}_windows"
  script: |
    # Make sure we actually fail if a command fails
    $ErrorActionPreference = 'Stop'
    $PSNativeCommandUseErrorActionPreference = $true

    mkdir dumps

    # Start the container
    docker network create -d "nat" -o com.docker.network.windowsshim.dnsservers="1.1.1.1" net
    docker run --network net -d --name httpbin-integration registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:httpbin-windows
    docker run --network net -d --name request-replayer registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-request-replayer-2.0-windows
    docker run -v ${pwd}:C:\Users\ContainerAdministrator\app  --network net -d --name ${CONTAINER_NAME} ${IMAGE} ping -t localhost

    # Build nts
    docker exec ${CONTAINER_NAME} powershell.exe "cd app; switch-php nts; C:\php\SDK\phpize.bat; .\configure.bat --enable-debug-pack; nmake"

    # Set test environment variables
    docker exec ${CONTAINER_NAME} powershell.exe "setx DD_AUTOLOAD_NO_COMPILE true; setx DATADOG_HAVE_DEV_ENV 1; setx DD_TRACE_GIT_METADATA_ENABLED 0"

    # Run extension tests
    docker exec ${CONTAINER_NAME} powershell.exe 'cd app; $env:_DD_DEBUG_SIDECAR_LOG_LEVEL=trace; $env:_DD_DEBUG_SIDECAR_LOG_METHOD="""file://${pwd}\sidecar.log"""; C:\php\php.exe -n -d memory_limit=-1 -d output_buffering=0 run-tests.php -g FAIL,XFAIL,BORK,WARN,LEAK,XLEAK,SKIP --show-diff -p C:\php\php.exe -d "extension=${pwd}\x64\Release\php_ddtrace.dll" "${pwd}\tests\ext"'

    # Try to stop the container, don't care if we fail
    try { docker stop -t 5 ${CONTAINER_NAME} } catch { }
  after_script:
    - |
        docker exec php cmd.exe /s /c xcopy /y /c /s /e C:\ProgramData\Microsoft\Windows\WER\ReportQueue .\app\dumps\
        exit 0
  artifacts:
    paths:
      - sidecar.log
      - x64/Release/php_ddtrace.dll
      - x64/Release/php_ddtrace.pdb
      - dumps


"Prepare code":
  stage: compile
  image: registry.ddbuild.io/images/mirror/php:8.2-cli
  tags: [ "arch:amd64" ]
  needs: []
  variables:
    KUBERNETES_CPU_REQUEST: 1
    KUBERNETES_MEMORY_REQUEST: 2Gi
  before_script:
    - apt update && apt install -y unzip
    - php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" && php composer-setup.php && mv composer.phar /usr/local/bin/composer
    - composer update --no-interaction
  script:
    - make generate
  artifacts:
    paths:
      - src/bridge/_generated_*.php

.base_test:
  stage: test
  tags: [ "arch:${ARCH}" ]
  image: registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-${PHP_MAJOR_MINOR}_buster
  timeout: 30m
  variables:
    host_os: linux-gnu
    COMPOSER_MEMORY_LIMIT: "-1"
    DD_TRACE_ASSUME_COMPILED: "1"
    DDAGENT_HOSTNAME: "127.0.0.1"
    MAX_TEST_PARALLELISM: 8
    TEST_FILES_DIR: "."
    DATADOG_HAVE_DEV_ENV: 1
    HTTPBIN_HOSTNAME: httpbin-integration
    HTTPBIN_PORT: 8080
  before_script:
<?php before_script_steps() ?>
    - for host in ${WAIT_FOR:-}; do wait-for $host --timeout=30; done

.asan_test:
  extends: .base_test
  variables:
    SWITCH_PHP_VERSION: debug-zts-asan
    ASAN_OPTIONS: abort_on_error=1:disable_coredump=0:unmap_shadow_on_exit=1
<?php sidecar_logs(); ?>

<?php
foreach ($asan_minor_major_targets as $major_minor):
    foreach ($arch_targets as $arch):
?>
"ASAN test_c: [<?= $major_minor ?>, <?= $arch ?>]":
  extends: .asan_test
  services:
<?php agent_httpbin_service() ?>
  needs:
    - job: "compile extension: debug-zts-asan"
      parallel:
        matrix:
          - PHP_MAJOR_MINOR: "<?= $major_minor ?>"
            ARCH: "<?= $arch ?>"
      artifacts: true
  retry: 2
  variables:
    WAIT_FOR: test-agent:9126
    KUBERNETES_CPU_REQUEST: 6
    KUBERNETES_CPU_LIMIT: 6
    KUBERNETES_MEMORY_REQUEST: 4Gi
    KUBERNETES_MEMORY_LIMIT: 4Gi
    MAX_TEST_PARALLELISM: 2
    PHP_MAJOR_MINOR: "<?= $major_minor ?>"
    ARCH: "<?= $arch ?>"
  script:
    - make test_c
<?php after_script("tmp/build_extension", has_test_agent: true); ?>

"ASAN Internal api randomized tests: [<?= $major_minor ?>, <?= $arch ?>]":
  extends: .asan_test
  needs:
    - job: "compile extension: debug-zts-asan"
      parallel:
        matrix:
          - PHP_MAJOR_MINOR: "<?= $major_minor ?>"
            ARCH: "<?= $arch ?>"
      artifacts: true
  variables:
    PHP_MAJOR_MINOR: "<?= $major_minor ?>"
    ARCH: "<?= $arch ?>"
  script:
    - make test_internal_api_randomized
<?php after_script(); ?>

<?php
    endforeach;
endforeach;
?>

<?php
foreach ($asan_minor_major_targets as $major_minor):
?>
"ASAN init hook tests: [<?= $major_minor ?>]":
  extends: .asan_test
  services:
    - !reference [.services, httpbin-integration]
  needs:
    - job: "compile extension: debug-zts-asan"
      parallel:
        matrix:
          - PHP_MAJOR_MINOR: "<?= $major_minor ?>"
            ARCH: "amd64"
      artifacts: true
  variables:
    PHP_MAJOR_MINOR: "<?= $major_minor ?>"
    ARCH: "amd64"
  script:
    - make test_with_init_hook
<?php after_script(); ?>

<?php if (version_compare($major_minor, "8.0", ">=")): ?>
"ASAN test_c with multiple observers: [<?= $major_minor ?>]":
  extends: .asan_test
  services:
<?php agent_httpbin_service() ?>
  needs:
    - job: "compile extension: debug-zts-asan"
      parallel:
        matrix:
          - PHP_MAJOR_MINOR: "<?= $major_minor ?>"
            ARCH: "amd64"
      artifacts: true
  variables:
    WAIT_FOR: test-agent:9126
    KUBERNETES_CPU_REQUEST: 12
    MAX_TEST_PARALLELISM: 4
    PHP_MAJOR_MINOR: "<?= $major_minor ?>"
    ARCH: "amd64"
  script:
    - make test_c_observer
<?php after_script("tmp/build_extension", has_test_agent: true); ?>
<?php endif; ?>

"ASAN Opcache tests: [<?= $major_minor ?>]":
  extends: .asan_test
  needs:
    - job: "compile extension: debug-zts-asan"
      parallel:
        matrix:
          - PHP_MAJOR_MINOR: "<?= $major_minor ?>"
            ARCH: "amd64"
      artifacts: true
  variables:
    PHP_MAJOR_MINOR: "<?= $major_minor ?>"
    ARCH: "amd64"
  script:
    - make test_opcache
<?php after_script(); ?>
<?php
endforeach;
?>


.debug_test:
  extends: .base_test
  variables:
    SWITCH_PHP_VERSION: debug

<?php
foreach ($all_minor_major_targets as $major_minor):
?>
"test_extension_ci: [<?= $major_minor ?>]":
  extends: .debug_test
  services:
<?php agent_httpbin_service() ?>
  needs:
    - job: "compile extension: debug"
      parallel:
        matrix:
          - PHP_MAJOR_MINOR: "<?= $major_minor ?>"
            ARCH: "amd64"
      artifacts: true
  variables:
    WAIT_FOR: test-agent:9126
    KUBERNETES_CPU_REQUEST: 12
    MAX_TEST_PARALLELISM: 4
    PHP_MAJOR_MINOR: "<?= $major_minor ?>"
    ARCH: "amd64"
  timeout: 120m
  script:
    - make test_extension_ci
<?php after_script("tmp/build_extension", has_test_agent: true); ?>

"Unit tests: [<?= $major_minor ?>]":
  extends: .debug_test
  needs:
    - job: "compile extension: debug"
      parallel:
        matrix:
          - PHP_MAJOR_MINOR: "<?= $major_minor ?>"
            ARCH: "amd64"
      artifacts: true
  variables:
    PHP_MAJOR_MINOR: "<?= $major_minor ?>"
    ARCH: "amd64"
  script:
    - make test_unit <?= ASSERT_NO_MEMLEAKS ?>
<?php after_script(); ?>

"API unit tests: [<?= $major_minor ?>]":
  extends: .debug_test
  needs:
    - job: "compile extension: debug"
      parallel:
        matrix:
          - PHP_MAJOR_MINOR: "<?= $major_minor ?>"
            ARCH: "amd64"
      artifacts: true
  variables:
    PHP_MAJOR_MINOR: "<?= $major_minor ?>"
    ARCH: "amd64"
  script:
    - make test_api_unit <?= ASSERT_NO_MEMLEAKS ?>
<?php after_script(); ?>

"Disabled test_c run: [<?= $major_minor ?>]":
  extends: .debug_test
  needs:
    - job: "compile extension: debug"
      parallel:
        matrix:
          - PHP_MAJOR_MINOR: "<?= $major_minor ?>"
            ARCH: "amd64"
      artifacts: true
  variables:
    PHP_MAJOR_MINOR: "<?= $major_minor ?>"
    ARCH: "amd64"
<?php if (version_compare($major_minor, "7.4", ">=")): ?>
    KUBERNETES_CPU_REQUEST: 8
    MAX_TEST_PARALLELISM: 16
<?php else: /* no test parallelism */ ?>
    KUBERNETES_CPU_REQUEST: 1
  timeout: 40m
<?php endif; ?>
  script:
    - make test_c_disabled <?= ASSERT_NO_MEMLEAKS ?>
<?php after_script(); ?>

"Internal api randomized tests: [<?= $major_minor ?>]":
  extends: .debug_test
  needs:
    - job: "compile extension: debug"
      parallel:
        matrix:
          - PHP_MAJOR_MINOR: "<?= $major_minor ?>"
            ARCH: "amd64"
      artifacts: true
  variables:
    PHP_MAJOR_MINOR: "<?= $major_minor ?>"
    ARCH: "amd64"
  script:
    - make test_internal_api_randomized
<?php after_script(); ?>

"Opcache tests: [<?= $major_minor ?>]":
  extends: .debug_test
  needs:
    - job: "compile extension: debug"
      parallel:
        matrix:
          - PHP_MAJOR_MINOR: "<?= $major_minor ?>"
            ARCH: "amd64"
      artifacts: true
  variables:
    PHP_MAJOR_MINOR: "<?= $major_minor ?>"
    ARCH: "amd64"
  script:
    - make test_opcache
<?php after_script("tmp/build_extension"); ?>

"PHP Language Tests: [<?= $major_minor ?>]":
  extends: .debug_test
  services:
    - !reference [.services, test-agent]
  needs:
    - job: "compile extension: debug"
      parallel:
        matrix:
          - PHP_MAJOR_MINOR: "<?= $major_minor ?>"
            ARCH: "amd64"
      artifacts: true
  variables:
    PHP_MAJOR_MINOR: "<?= $major_minor ?>"
    ARCH: "amd64"
    DD_TRACE_STARTUP_LOGS: "0"
    DD_TRACE_WARN_CALL_STACK_DEPTH: "0"
    DD_TRACE_WARN_LEGACY_DD_TRACE: "0"
    DD_TRACE_GIT_METADATA_ENABLED: "0"
    REPORT_EXIT_STATUS: "1"
    TEST_PHP_JUNIT: "/tmp/artifacts/tests/php-tests.xml"
    SKIP_ONLINE_TEST: "1"
<?php sidecar_logs(); ?>
  timeout: 40m
  script:
    - make install_all
    - export XFAIL_LIST="dockerfiles/ci/xfail_tests/${PHP_MAJOR_MINOR}.list"
    - .gitlab/run_php_language_tests.sh
<?php after_script("/usr/local/src/php"); ?>
<?php
endforeach;
?>

.cli_integration_test:
  extends: .base_test
  variables:
    DD_TRACE_TEST_SAPI: cli-server
    COMPOSER_PROCESS_TIMEOUT: 0
    KUBERNETES_CPU_REQUEST: 2 # generally one for PHP and one for the webserver
    KUBERNETES_MEMORY_REQUEST: 4Gi
    KUBERNETES_MEMORY_LIMIT: 4Gi
    SWITCH_PHP_VERSION: debug
    COMPOSER_VERSION: 2
  before_script:
<?php before_script_steps() ?>
    - if [[ "$MAKE_TARGET" != "test_composer" ]] || ! [[ "$PHP_MAJOR_MINOR" =~ 8.[01] ]]; then sudo composer self-update --$COMPOSER_VERSION --no-interaction; fi
    - COMPOSER_MEMORY_LIMIT=-1 composer update --no-interaction # disable composer memory limit completely
    - make composer_tests_update
    - for host in ${WAIT_FOR:-}; do wait-for $host --timeout=30; done
  script:
    - DD_TRACE_AGENT_TIMEOUT=1000 make $MAKE_TARGET RUST_DEBUG_BUILD=1 PHPUNIT_OPTS="--log-junit artifacts/tests/results.xml" <?= ASSERT_NO_MEMLEAKS ?>
<?php after_script(".", true); ?>
    - find tests -type f \( -name 'phpunit_error.log' -o -name 'nginx_*.log' -o -name 'apache_*.log' -o -name 'php_fpm_*.log' -o -name 'dd_php_error.log' \) -exec cp --parents '{}' artifacts \;
    - make tested_versions && cp tests/tested_versions/tested_versions.json artifacts/tested_versions.json

<?php

// specific service maps:
$services["elasticsearch1"] = "elasticsearch2";
$services["elasticsearch_latest"] = "elasticsearch7";
$services["magento"] = "elasticsearch7";
$services["deferred_loading"] = "mysql";
$services["deferred_loadin"] = "redis";
$services["pdo"] = "mysql";
$services["kafk"] = "zookeeper";

$jobs = [];
preg_match_all('(^TEST_(?<type>INTEGRATIONS|WEB)_(?<major>\d+)(?<minor>\d)[^\n]+(?<targets>.*?)^(?!\t))ms', file_get_contents(__DIR__ . "/../Makefile"), $matches, PREG_SET_ORDER);
foreach ($matches as $m) {
    $major_minor = "{$m["major"]}.{$m["minor"]}";
    $type = strtolower($m["type"]);

    preg_match_all('(\t\K[a-z0-9_]+)', $m["targets"], $targets, PREG_PATTERN_ORDER);
    foreach ($targets[0] as $target) {
        $jobs[$type][$target][] = $major_minor;
    }
}

foreach ($jobs as $type => $type_jobs):
    foreach ($type_jobs as $target => $versions):
        foreach ($versions as $major_minor):
            $sapis = $type == "web" && version_compare($major_minor, "7.2", ">=") ? ["cli-server", "cgi-fcgi", "apache2handler"] : [""];
            foreach ($sapis as $sapi):
?>
"<?= $target ?>: [<?= $major_minor, $sapi ? ", $sapi" : "" ?>]":
  extends: .cli_integration_test
  stage: "<?= $type ?> test"
  needs:
    - job: "compile extension: debug"
      parallel:
        matrix:
          - PHP_MAJOR_MINOR: "<?= $major_minor ?>"
            ARCH: "amd64"
      artifacts: true
    - job: "Prepare code"
      artifacts: true
  services:
<?php agent_httpbin_service() ?>
<?php if ($type == "web"): ?>
    - !reference [.services, mysql]
<?php endif; ?>
<?php foreach ($services as $part => $service): if (str_contains($target, $part)): ?>
    - !reference [.services, <?= $service ?>]
<?php endif; endforeach; ?>
  variables:
    PHP_MAJOR_MINOR: "<?= $major_minor ?>"
    MAKE_TARGET: "<?= $target ?>"
    ARCH: "amd64"
<?php if ($sapi): ?>
    DD_TRACE_TEST_SAPI: "<?= $sapi ?>"
<?php endif; ?>
<?php if (preg_match("(test_web_symfony_(2|30|33|40))", $target)): ?>
    COMPOSER_VERSION: 2.2
<?php endif; ?>

<?php
            endforeach;
        endforeach;
    endforeach;
endforeach;
?>

<?php
foreach ($all_minor_major_targets as $major_minor):
    foreach (["test_auto_instrumentation", "test_composer", "test_integration"] as $test):
?>
"<?= $test ?>: [<?= $major_minor ?>]":
  extends: .cli_integration_test
  needs:
    - job: "compile extension: debug"
      parallel:
        matrix:
          - PHP_MAJOR_MINOR: "<?= $major_minor ?>"
            ARCH: "amd64"
      artifacts: true
    - job: "Prepare code"
      artifacts: true
  services:
<?php agent_httpbin_service() ?>
    - !reference [.services, mongodb]
  variables:
    PHP_MAJOR_MINOR: "<?= $major_minor ?>"
    MAKE_TARGET: "<?= $test ?>"
    ARCH: "amd64"
<?php
    endforeach;
?>

<?php foreach(["cli-server", "cgi-fcgi"] as $sapi): ?>
"test_distributed_tracing: [<?= $major_minor ?>, <?= $sapi ?>]":
  extends: .cli_integration_test
  needs:
    - job: "compile extension: debug"
      parallel:
        matrix:
          - PHP_MAJOR_MINOR: "<?= $major_minor ?>"
            ARCH: "amd64"
      artifacts: true
    - job: "Prepare code"
      artifacts: true
  services:
<?php agent_httpbin_service() ?>
    - !reference [.services, mongodb]
  variables:
    PHP_MAJOR_MINOR: "<?= $major_minor ?>"
    MAKE_TARGET: "test_distributed_tracing"
    ARCH: "amd64"
<?php if ($sapi == "cgi-fcgi"): ?>
    DD_DISTRIBUTED_TRACING: "false"
<?php endif; ?>
<?php
    endforeach;
endforeach;
?>

<?php
$xdebug_test_matrix = [
    ["7.0", "2.7.2"],
    ["7.1", "2.9.2"],
    ["7.1", "2.9.5"],
    ["7.2", "2.9.2"],
    ["7.2", "2.9.5"],
    ["7.3", "2.9.2"],
    ["7.3", "2.9.5"],
    ["7.4", "2.9.2"],
    ["7.4", "2.9.5"],
    ["8.0", "3.0.0"],
    ["8.1", "3.1.0"],
    ["8.2", "3.2.2"],
    ["8.3", "3.3.2"],
    ["8.4", "3.4.0"],
];
foreach ($xdebug_test_matrix as [$major_minor, $xdebug]):
?>
"xDebug tests: [<?= $major_minor ?>, <?= $xdebug ?>]":
  extends: .debug_test
  services:
<?php agent_httpbin_service() ?>
  needs:
    - job: "compile extension: debug"
      parallel:
        matrix:
          - PHP_MAJOR_MINOR: "<?= $major_minor ?>"
            ARCH: "amd64"
      artifacts: true
  variables:
    PHP_MAJOR_MINOR: "<?= $major_minor ?>"
    ARCH: "amd64"
    REPORT_EXIT_STATUS: "1"
  script:
    - make install_all
    - '# Run xdebug tests'
    - php /usr/local/src/php/run-tests.php -g FAIL,XFAIL,BORK,WARN,LEAK,XLEAK,SKIP -p $(which php) --show-all -d zend_extension=xdebug-<?= $xdebug ?>.so "tests/xdebug/<?= $xdebug[0] == 2 ? $xdebug : "3.0.0" ?>"
<?php if ($xdebug != "2.7.2" && $xdebug != "2.9.2"): ?>
    - '# Run unit tests with xdebug'
    - TEST_EXTRA_INI='-d zend_extension=xdebug-<?= $xdebug ?>.so' make test_unit RUST_DEBUG_BUILD=1 PHPUNIT_OPTS="--log-junit test-results/php-unit/results_unit.xml"
<?php endif; ?>
<?php after_script(has_test_agent: true); ?>

<?php endforeach; ?>
