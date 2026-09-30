<?php

require __DIR__ . "/ci-targets.php";

$yaml = file_get_contents(__DIR__ . "/portable-builds.yml");
$errors = [];

function portable_job_block(string $yaml, string $job): ?string
{
    $pattern = '/^"' . preg_quote($job, '/')
        . '":\R((?:^[ \t].*(?:\R|$)|^\R)*)/m';
    if (!preg_match($pattern, $yaml, $matches)) {
        return null;
    }
    return $matches[1];
}

foreach ($arch_targets as $arch) {
    foreach ([
        "cache portable cargo deps: [$arch]",
        "compile portable tracing sidecar: [$arch]",
        "compile portable loader: [$arch]",
        "collect portable tracing artifacts: [7.x, $arch]",
        "collect portable tracing artifacts: [8.x, $arch]",
        "collect portable component artifacts: [$arch]",
        "collect portable runtime artifacts: [$arch]",
        "portable builds complete: [$arch]",
        "compile portable profiler rust tests: [8.5, $arch]",
    ] as $job) {
        if (portable_job_block($yaml, $job) === null) {
            $errors[] = "missing job: $job";
        }
    }

    foreach ($php_versions_to_abi as $version => $abi) {
        foreach (["appsec", "tracing"] as $component) {
            $job = "compile portable $component extension: [$version, $arch]";
            $block = portable_job_block($yaml, $job);
            if ($block === null) {
                $errors[] = "missing job: $job";
                continue;
            }
            if (!str_contains($block, "PHP_VERSION: \"$version\"")
                || !str_contains($block, "ABI_NO: \"$abi\"")) {
                $errors[] = "wrong PHP version or ABI: $job";
            }
        }
    }

    foreach ($profiler_minor_major_targets as $version) {
        $abi = $php_versions_to_abi[$version];
        $job = "compile portable profiler extension: [$version, $arch]";
        $block = portable_job_block($yaml, $job);
        if ($block === null) {
            $errors[] = "missing job: $job";
            continue;
        }
        if (!str_contains($block, "PHP_VERSION: \"$version\"")
            || !str_contains($block, "ABI_NO: \"$abi\"")) {
            $errors[] = "wrong PHP version or ABI: $job";
        }
    }
}

if (portable_job_block(
    $yaml,
    "compile portable profiler benchmarks: [8.2, amd64]"
) === null) {
    $errors[] = "missing job: compile portable profiler benchmarks: [8.2, amd64]";
}

if (!str_contains($yaml, "datadog-profiling-tests/")) {
    $errors[] = "portable profiler jobs do not produce test artifacts";
}

if ($errors) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}

echo "Portable build jobs match the CI target matrix.\n";
