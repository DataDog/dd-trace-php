#!/usr/bin/env bash
# Run with PHP 7.x and its run-tests.php: bash tooling/tests/parallelize-run-tests.sh /path/to/run-tests.php
set -euo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
tmp=$(mktemp -d)
trap 'status=$?; if [[ $status != 0 ]]; then cat "$tmp/output" "$tmp/results.xml" 2>/dev/null; fi; rm -rf "$tmp"' EXIT
cp "$1" "$tmp/run-tests.php"
mkdir "$tmp/tests" "$tmp/redirected"
cd "$tmp"

cat > tests/ordinary.phpt <<'EOF'
--TEST--
Ordinary test
--FILE--
<?php echo "ordinary\n"; ?>
--EXPECT--
ordinary
EOF
cat > tests/redirect.phpt <<'EOF'
--TEST--
Redirected tests
--REDIRECTTEST--
return ['TESTS' => dirname(__FILE__) . '/redirected', 'ENV' => []];
EOF
for name in first second; do
  cat > "redirected/$name.phpt" <<EOF
--TEST--
$name
--FILE--
<?php echo "$name\n"; ?>
--EXPECT--
$name
EOF
done

run() {
  REPORT_EXIT_STATUS=1 TEST_PHP_JUNIT="$tmp/results.xml" \
    "$root/tooling/parallelize-run-tests.sh" -j2 php -n run-tests.php \
    -q -n -p "$(command -v php)" -g FAIL,XFAIL,BORK,WARN tests > "$tmp/output" 2>&1
}
run || { cat "$tmp/output"; exit 1; }
grep -Eq 'Number of tests : +3 \(3 run\)' "$tmp/output"
grep -Eq 'Tests passed +: +3' "$tmp/output"
php -n -r '$xml = file_get_contents($argv[1]); exit(substr_count($xml, "<testcase ") === 3 ? 0 : 1);' "$tmp/results.xml"

# A failure in an expanded test must fail the whole invocation.
printf '\nwrong\n' >> redirected/second.phpt
status=0
run || status=$?
[[ $status == 1 ]]
grep -Eq 'Number of tests : +3 \(3 run\)' "$tmp/output"
grep -Eq 'Tests failed +: +1' "$tmp/output"
echo 'Parallel runner redirect and failure checks passed.'
