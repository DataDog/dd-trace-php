<?php

$arch_targets = ["amd64", "arm64"];

$php_versions_to_abi = [
    "7.0" => "20151012",
    "7.1" => "20160303",
    "7.2" => "20170718",
    "7.3" => "20180731",
    "7.4" => "20190902",
    "8.0" => "20200930",
    "8.1" => "20210902",
    "8.2" => "20220829",
    "8.3" => "20230831",
    "8.4" => "20240924",
    "8.5" => "20250925",
];

$all_minor_major_targets = array_keys($php_versions_to_abi);

$asan_minor_major_targets = array_values(array_filter(
    $all_minor_major_targets,
    fn($version) => version_compare($version, "7.4", ">=")
));
$windows_minor_major_targets = array_values(array_filter(
    $all_minor_major_targets,
    fn($version) => version_compare($version, "7.2", ">=")
));
$profiler_minor_major_targets = array_values(array_filter(
    $all_minor_major_targets,
    fn($version) => version_compare($version, "7.1", ">=")
));
