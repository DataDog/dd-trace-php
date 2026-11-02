--TEST--
PHP2Shell mitigation (CVE-2026-109618/109619): the patched-runtime detection reports a patched runtime from the first fixed release of this PHP minor
--FILE--
<?php
// The mitigation detects the upstream fix at runtime (the engine exports
// _femalloc) and, in mode auto, stays inactive on a patched runtime; most
// php2shell tests then skip. This test does not skip: it fails when a release
// at or past the first fixed one of its minor (table below) is not detected
// as patched, so a CI image moving to a fixed point release shows up here
// instead of silently skipping the suite. Below that release either answer
// is accepted: distro builds backport the fix (e.g. Ubuntu noble's 8.3.6).
// A new minor needs a row.
//
// The versions are the first releases of each minor that export _femalloc,
// as listed in php2shell_mitigation.c. null: no fixed release; 7.0-8.1 are
// end of life and did not get one. 8.6 counts as patched from its first
// pre-release (see p2s_policy.c).
require __DIR__ . '/inc/php2shell_cgi.php';
$first_fixed = [
    '7.0' => null, '7.1' => null, '7.2' => null, '7.3' => null, '7.4' => null,
    '8.0' => null, '8.1' => null,
    '8.2' => '8.2.35', '8.3' => '8.3.36', '8.4' => '8.4.27', '8.5' => '8.5.12',
    '8.6' => '8.6.0',
];
$minor = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
$version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
if (!array_key_exists($minor, $first_fixed)) {
    echo "minor $minor missing from the table\n";
} else {
    $fixed = $first_fixed[$minor] !== null && version_compare($version, $first_fixed[$minor], '>=');
    $minfo = p2s_minfo();
    $row = isset($minfo[P2S_ROW_PATCHED]) ? $minfo[P2S_ROW_PATCHED] : 'absent';
    $ok = $row === 'Yes' || ($row === 'No' && !$fixed);
    echo "detection_ok=", var_export($ok, true), "\n";
    if (!$ok) {
        echo "PHP $version: MINFO patched row=$row, fixed release: ", $fixed ? 'yes' : 'no', "\n";
    }
}
--EXPECT--
detection_ok=true
