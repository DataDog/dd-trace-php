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

$root = dirname(__DIR__);
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
    'system-tests main revision resolution'
);
require_contains(
    $prepare_code,
    "printf 'SYSTEM_TESTS_SHA=%s\\n' \"\$SYSTEM_TESTS_SHA\" > system-tests.env",
    'system-tests dotenv creation'
);
require_contains(
    $prepare_code,
    "    reports:\n" .
        "      dotenv: system-tests.env",
    'system-tests dotenv artifact report'
);

$system_tests = generated_definition($configuration, '.system_tests');
require_contains(
    $system_tests,
    "    - job: \"prepare code\"\n" .
        "      artifacts: true",
    'system-tests revision artifact dependency'
);
require_contains(
    $system_tests,
    "if ! printf '%s\\n' \"\${SYSTEM_TESTS_SHA:-}\" | grep -Eq '^[0-9a-f]{40}\$'; then",
    'resolved revision validation'
);
require_contains(
    $system_tests,
    'git -C system-tests fetch --quiet --depth 1 https://github.com/DataDog/system-tests.git "$SYSTEM_TESTS_SHA"',
    'exact shallow system-tests fetch'
);
require_contains(
    $system_tests,
    'git -C system-tests checkout --quiet --detach "$SYSTEM_TESTS_SHA"',
    'detached system-tests checkout'
);
require_contains(
    $system_tests,
    'test "$(git -C system-tests rev-parse HEAD)" = "$SYSTEM_TESTS_SHA"',
    'checked-out system-tests revision verification'
);
require_contains(
    $system_tests,
    'CI_IMAGE="$CI_JOB_IMAGE" python3 system-tests/utils/ci/gitlab/build_ci_image.py --check-only',
    'runner image compatibility check'
);

echo "Generated system-tests CI contract: OK\n";
