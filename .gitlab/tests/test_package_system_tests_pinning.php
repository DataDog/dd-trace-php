<?php

function fail(string $message): void
{
    fwrite(STDERR, "$message\n");
    exit(1);
}

function require_contains(string $configuration, string $expected, string $contract): void
{
    if (!str_contains($configuration, $expected)) {
        fail("Generated pipeline is missing $contract");
    }
}

function generated_definition(string $configuration, string $name): string
{
    $marker = "$name:\n";
    $start = strpos($configuration, $marker);
    if ($start === false) {
        fail("Generated pipeline is missing $name");
    }

    $following = substr($configuration, $start + strlen($marker));
    if (!preg_match('/^(?=(?:"[^\n]+"|[A-Za-z_.][^:\n]*):\n)/m', $following, $match, PREG_OFFSET_CAPTURE)) {
        return substr($configuration, $start);
    }

    return substr($configuration, $start, strlen($marker) + $match[0][1]);
}

$root = dirname(__DIR__, 2);
$original_directory = getcwd();
chdir("$root/.gitlab");
ob_start();
require "$root/.gitlab/generate-package.php";
$configuration = ob_get_clean();
chdir($original_directory);

$prepare_code = generated_definition($configuration, '"prepare code"');
require_contains(
    $prepare_code,
    "SYSTEM_TESTS_SHA=\$(git ls-remote https://github.com/DataDog/system-tests.git refs/heads/main | " .
        "awk 'NR == 1 { print \$1 }')",
    'system-tests revision resolution'
);
require_contains(
    $prepare_code,
    "if ! printf '%s\\n' \"\$SYSTEM_TESTS_SHA\" | grep -Eq '^[0-9a-f]{40}\$'; then\n" .
        "        echo \"Failed to resolve a valid system-tests commit: \$SYSTEM_TESTS_SHA\"\n" .
        "        exit 1\n" .
        "      fi",
    'resolved system-tests revision validation'
);
require_contains(
    $prepare_code,
    "printf 'SYSTEM_TESTS_SHA=%s\\n' \"\$SYSTEM_TESTS_SHA\" > system-tests.env",
    'system-tests dotenv creation'
);
require_contains(
    $prepare_code,
    "  artifacts:\n" .
        "    paths:\n" .
        "      - VERSION\n" .
        "      - ./src/bridge/_generated*.php\n" .
        "    reports:\n" .
        "      dotenv: system-tests.env",
    'system-tests dotenv artifact report'
);

$system_tests = generated_definition($configuration, '.system_tests');
require_contains(
    $system_tests,
    "- job: \"prepare code\"\n      artifacts: true",
    'prepare code artifact dependency'
);
require_contains(
    $system_tests,
    "if ! printf '%s\\n' \"\${SYSTEM_TESTS_SHA:-}\" | grep -Eq '^[0-9a-f]{40}\$'; then\n" .
        "        echo \"Missing or invalid SYSTEM_TESTS_SHA: \${SYSTEM_TESTS_SHA:-}\"\n" .
        "        exit 1\n" .
        "      fi",
    'checkout revision validation'
);
require_contains(
    $system_tests,
    "git init -q system-tests\n" .
        "      git -C system-tests remote add origin https://github.com/DataDog/system-tests.git",
    'system-tests checkout initialization'
);
require_contains(
    $system_tests,
    'git -C system-tests fetch --depth=1 origin "$SYSTEM_TESTS_SHA"',
    'exact shallow system-tests fetch'
);
require_contains(
    $system_tests,
    'git -C system-tests checkout --detach "$SYSTEM_TESTS_SHA"',
    'detached system-tests checkout'
);
require_contains(
    $system_tests,
    'CHECKED_OUT_SYSTEM_TESTS_SHA=$(git -C system-tests rev-parse HEAD)',
    'checked-out system-tests revision lookup'
);
require_contains(
    $system_tests,
    "if [ \"\$CHECKED_OUT_SYSTEM_TESTS_SHA\" != \"\$SYSTEM_TESTS_SHA\" ]; then\n" .
        "        echo \"Checked out system-tests \$CHECKED_OUT_SYSTEM_TESTS_SHA, expected \$SYSTEM_TESTS_SHA\"\n" .
        "        exit 1\n" .
        "      fi",
    'checked-out system-tests revision mismatch failure'
);

if (!preg_match_all(
    '/^"System Tests: \[[^,\]\n]+, tracer-release\]":$/m',
    $configuration,
    $tracer_release_jobs
) || count($tracer_release_jobs[0]) !== 25) {
    fail('Generated pipeline must contain 25 tracer-release definitions');
}

foreach ($tracer_release_jobs[0] as $heading) {
    $name = substr($heading, 0, -1);
    $definition = generated_definition($configuration, $name);
    require_contains($definition, "  parallel: 4\n", "$name four-way parallel expansion");
    require_contains($definition, "      set -o pipefail\n", "$name scenario pipeline failure detection");
    require_contains(
        $definition,
        "PYTHONPATH=. venv/bin/python utils/scripts/compute-workflow-parameters.py php -g tracer_release -f json |\n" .
            "          python3 \"\$CI_PROJECT_DIR/.gitlab/select-system-tests-shard.py\"",
        "$name scenario producer-to-selector pipeline"
    );
    require_contains($definition, ') || exit $?', "$name selector failure propagation");
}

echo "Generated pipeline system-tests pinning contract: OK\n";
