#!/usr/bin/env bash
# run-tests.php only learned -j in PHP 7.4. Prefix a run-tests.php invocation
# with this script to get the same on older versions: the tests are split into
# small batches, -jN run-tests.php processes work through them concurrently
# (each run with -r <batch>), and their output is merged into one stream of
# results, followed by a combined summary. -s and TEST_PHP_JUNIT outputs are
# merged too. Tests sharing a CONFLICTS key are kept in one batch, so they
# never run concurrently; tests conflicting with "all" run alone at the end.
#
# Usage: parallelize-run-tests.sh [-jN] [VAR=value...] php [php-opts] run-tests.php [opts] tests...
#
# Invocations it cannot split (no test paths, -r/-l/-w/-a/-W/--html,
# TEST_PHP_ARGS) are executed unchanged.

set -o pipefail

jobs=${MAX_TEST_PARALLELISM:-$(nproc 2>/dev/null || echo 1)}
if [[ $1 == -j ]]; then
  jobs=$2
  shift 2
elif [[ $1 == -j* ]]; then
  jobs=${1#-j}
  shift
fi

passthrough() {
  [[ -n $1 ]] && echo "parallelize-run-tests: $1, running serially" >&2
  shift
  exec env "$@"
}

argv=("$@")
argc=${#argv[@]}
assigns=()
launcher=()
runner_opts=()
tests=()
output_file=
junit=$TEST_PHP_JUNIT
i=0

[[ -n $TEST_PHP_ARGS ]] && passthrough "TEST_PHP_ARGS is set" "$@"

while ((i < argc)) && [[ ${argv[i]} =~ ^[A-Za-z_][A-Za-z0-9_]*= ]]; do
  [[ ${argv[i]} == TEST_PHP_JUNIT=* ]] && junit=${argv[i]#TEST_PHP_JUNIT=}
  assigns+=("${argv[i]}")
  ((i++))
done

while ((i < argc)); do
  launcher+=("${argv[i]}")
  ((i++))
  [[ ${launcher[${#launcher[@]}-1]} == *run-tests.php ]] && break
done
[[ ${launcher[${#launcher[@]}-1]} == *run-tests.php ]] || passthrough "no run-tests.php in command" "$@"

while ((i < argc)); do
  arg=${argv[i]}
  case $arg in
    -s)
      output_file=${argv[i+1]}
      ((i += 2))
      continue
      ;;
    -r|-l|-w|-a|-W|--html|-j*)
      passthrough "$arg is not supported" "$@"
      ;;
    -c|-d|-g|-p|--set-timeout|--show-slow|--temp-source|--temp-target|--temp-urlbase)
      runner_opts+=("$arg" "${argv[i+1]}")
      ((i += 2))
      continue
      ;;
    -*)
      runner_opts+=("$arg")
      ;;
    *)
      tests+=("$arg")
      ;;
  esac
  ((i++))
done

((${#tests[@]})) || passthrough "" "$@"

tmp=$(mktemp -d "${TMPDIR:-/tmp}/parallelize-run-tests.XXXXXX") || exit 1
trap 'rm -rf "$tmp"' EXIT
mkdir "$tmp/parallel" "$tmp/serial"

find "${tests[@]}" -type f -name '*.phpt' > "$tmp/all" || exit 1
total=$(wc -l < "$tmp/all")
((jobs > 1 && total > 1)) || passthrough "" "$@"

batch_size=$(((total + jobs * 8 - 1) / (jobs * 8)))

# The helpers below run on the system php, so they must stay PHP 7.0 compatible.

# Groups tests by CONFLICTS keys (from a CONFLICTS file in the test's directory
# or a --CONFLICTS-- section, as in 7.4+), unioning groups sharing any key.
# Each group becomes a single batch; the remaining tests are shuffled (like
# 7.4's --shuffle, so that e.g. request-replayer tests don't cluster) and
# chunked.
read -r -d '' planner <<'EOF'
list(, $dir, $size, $list) = $argv;
$parent = [];
$find = function ($k) use (&$parent) {
    while ($parent[$k] !== $k) {
        $k = $parent[$k] = $parent[$parent[$k]];
    }
    return $k;
};
$parse = function ($lines) {
    $keys = [];
    foreach ($lines as $line) {
        $line = preg_replace("/#.*|\s+/", "", $line);
        if ($line !== "") {
            $keys[] = $line;
        }
    }
    return $keys;
};
$dir_keys = $solo = $serial = $grouped = [];
foreach (file($list, FILE_IGNORE_NEW_LINES) as $test) {
    $d = dirname($test);
    if (!isset($dir_keys[$d])) {
        $dir_keys[$d] = is_file("$d/CONFLICTS") ? $parse(file("$d/CONFLICTS")) : [];
    }
    $keys = $dir_keys[$d];
    if (preg_match("/^--CONFLICTS--\s*$(.*?)(?=^--[A-Z_]+--\s*$|\z)/ms", file_get_contents($test), $m)) {
        $keys = array_merge($keys, $parse(explode("\n", $m[1])));
    }
    if (!$keys) {
        $solo[] = $test;
    } elseif (in_array("all", $keys, true)) {
        $serial[] = $test;
    } else {
        foreach ($keys as $k) {
            if (!isset($parent[$k])) {
                $parent[$k] = $k;
            }
            $parent[$find($k)] = $find($keys[0]);
        }
        $grouped[$test] = $keys[0];
    }
}
$batches = 0;
$write = function ($subdir, $tests) use ($dir, &$batches) {
    file_put_contents(sprintf("%s/%s/%05d.lst", $dir, $subdir, ++$batches), implode("\n", $tests) . "\n");
};
$groups = [];
foreach ($grouped as $test => $k) {
    $groups[$find($k)][] = $test;
}
foreach ($groups as $tests) {
    $write("parallel", $tests);
}
shuffle($solo);
foreach (array_chunk($solo, $size) as $tests) {
    $write("parallel", $tests);
}
if ($serial) {
    $write("serial", $serial);
}
EOF

# Copies a batch's output to its log and tags every line with the worker id,
# splitting long lines so that each record stays below PIPE_BUF and records of
# concurrent workers never interleave. Record types: h(eader line),
# c (output line), p(artial line), e (result line). Drops the per-batch
# preamble (except for the first batch) and summary.
read -r -d '' filter <<'EOF'
list(, $w, $header, $log) = $argv;
$log = fopen($log, "w");
$out = function ($type, $s) use ($w) {
    $chunks = str_split($s, 1000);
    $last = array_pop($chunks);
    foreach ($chunks as $chunk) {
        fwrite(STDOUT, "$w\tp\t$chunk\n");
    }
    fwrite(STDOUT, "$w\t$type\t$last\n");
};
$state = 0;
while (($line = fgets(STDIN)) !== false) {
    fwrite($log, $line);
    // Progress lines ("TEST 1/9 [file]\r") and their blank overwrites.
    $line = preg_replace("/^(?:(?:TEST \d+\/\d+ \[[^\r]*\]| *)\r)+/", "", rtrim($line, "\n"));
    if ($state == 0) {
        if ($line === "Running selected tests.") {
            $state = 1;
        } elseif ($header) {
            $out("h", $line);
        }
    } elseif ($state == 1) {
        if ($line === str_repeat("=", 69)) {
            $state = 2;
        } else {
            $out(preg_match("/^(PASS|FAIL|SKIP|BORK|WARN|LEAK|XFAIL|XLEAK)(&[A-Z]+)* /", $line) ? "e" : "c", $line);
        }
    }
}
EOF

# Prints each test output block (anything since the previous result of that
# worker, followed by the result line) at once, numbered, and a final summary.
# Worker records "r" mark the end of a batch: exit code, test count, list file.
read -r -d '' merge <<'EOF'
$total = (int)$argv[1];
$start = time();
$sep = str_repeat("=", 69);
$width = strlen($total);
$buf = $seen = $count = $tests = $crashed = [];
$done = 0;
$failed = false;
while (($line = fgets(STDIN)) !== false) {
    list($w, $type, $s) = explode("\t", rtrim($line, "\n"), 3) + ["", "", ""];
    if (!isset($buf[$w])) {
        $buf[$w] = "";
        $seen[$w] = 0;
    }
    switch ($type) {
        case "h":
            echo "$s\n";
            break;
        case "p":
            $buf[$w] .= $s;
            break;
        case "c":
            $buf[$w] .= "$s\n";
            break;
        case "e":
            printf("%s[%{$width}d/%d] %s\n", $buf[$w], ++$done, $total, $s);
            $buf[$w] = "";
            $seen[$w]++;
            list($result, $name) = explode(" ", $s, 2) + ["", ""];
            foreach (explode("&", $result) as $r) {
                $count[$r] = (isset($count[$r]) ? $count[$r] : 0) + 1;
                $tests[$r][] = $name;
            }
            break;
        case "r":
            list($rc, $expected, $batch) = explode("\t", $s);
            echo $buf[$w];
            $buf[$w] = "";
            if ($rc != 0) {
                $failed = true;
            }
            if (($rc != 0 && $rc != 1) || $seen[$w] != $expected) {
                $failed = true;
                printf("\n%s\nBATCH FAILED: run-tests.php exited with %d after %d of %d tests, output tail:\n", $sep, $rc, $seen[$w], $expected);
                $tail = [];
                $log = fopen("$batch.log", "r");
                while (($l = fgets($log)) !== false) {
                    $tail[] = rtrim($l, "\n");
                    if (count($tail) > 100) {
                        array_shift($tail);
                    }
                }
                fclose($log);
                foreach ($tail as $l) {
                    echo "    $l\n";
                }
                echo "$sep\n";
                $crashed = array_merge($crashed, file($batch, FILE_IGNORE_NEW_LINES));
            }
            $seen[$w] = 0;
            break;
    }
}
echo implode("", $buf);
printf("%s\nNumber of tests : %4d (%d run)\n", $sep, $total, $done);
$summary = [
    "Tests skipped   " => ["SKIP"],
    "Tests borked    " => ["BORK"],
    "Tests warned    " => ["WARN"],
    "Tests failed    " => ["FAIL"],
    "Expected fail   " => ["XFAIL"],
    "Tests leaked    " => ["LEAK", "XLEAK"],
    "Tests passed    " => ["PASS"],
];
foreach ($summary as $label => $results) {
    $n = 0;
    foreach ($results as $r) {
        $n += isset($count[$r]) ? $count[$r] : 0;
    }
    printf("%s: %4d\n", $label, $n);
}
printf("Time taken      : %4d seconds\n%s\n", time() - $start, $sep);
$headings = [
    "XFAIL" => "EXPECTED FAILED",
    "BORK" => "BORKED",
    "FAIL" => "FAILED",
    "WARN" => "WARNED",
    "LEAK" => "LEAKED",
    "XLEAK" => "EXPECTED LEAK",
];
foreach ($headings as $r => $heading) {
    if (isset($tests[$r])) {
        printf("%s TEST SUMMARY\n%s\n%s\n", $heading, implode("\n", $tests[$r]), $sep);
    }
}
if ($crashed) {
    printf("TESTS IN FAILED BATCHES\n%s\n%s\n", implode("\n", $crashed), $sep);
}
exit($failed || $done != $total ? 1 : 0);
EOF

php -n -r "$planner" -- "$tmp" "$batch_size" "$tmp/all" || exit 1

run_batch() {
  local worker=$1 batch=$2 header=$3 extra_env=() extra_opts=() rc
  [[ -n $junit ]] && extra_env+=("TEST_PHP_JUNIT=$batch.xml")
  [[ -n $output_file ]] && extra_opts+=(-s "$batch.out")
  env "${assigns[@]}" "${extra_env[@]}" "${launcher[@]}" "${runner_opts[@]}" "${extra_opts[@]}" -r "$batch" 2>&1 \
    | php -n -r "$filter" -- "$worker" "$header" "$batch.log"
  rc=${PIPESTATUS[0]}
  printf '%s\tr\t%s\t%s\t%s\n' "$worker" "$rc" "$(wc -l < "$batch")" "$batch"
}

worker() {
  local worker=$1 dir=$2 batch
  for batch in "$dir"/*.lst; do
    [[ -e $batch ]] || continue
    mkdir "$batch.claim" 2> /dev/null || continue
    run_batch "$worker" "$batch" "$([[ $batch == "$tmp/parallel/00001.lst" ]] && echo 1)"
  done
}

run_all() {
  local id
  for ((id = 1; id <= jobs; id++)); do
    worker "$id" "$tmp/parallel" &
  done
  wait
  worker 1 "$tmp/serial"
}

echo "Running $total tests in $(ls "$tmp"/*/*.lst | wc -l) batches on $jobs workers."
run_all | php -n -r "$merge" -- "$total"
status=$?

if [[ -n $output_file ]]; then
  cat "$tmp"/*/*.out > "$output_file" 2> /dev/null
fi

if [[ -n $junit ]]; then
  {
    echo '<?xml version="1.0" encoding="UTF-8"?>'
    echo '<testsuites>'
    for xml in "$tmp"/*/*.xml; do
      [[ -s $xml ]] && sed '1,2d;$d' "$xml"
    done
    echo '</testsuites>'
  } > "$junit"
fi

exit $status
