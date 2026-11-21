#!/usr/bin/env bash
# Usage: ci-patch-bp-steps.sh <benchmarking-platform checkout>
set -euo pipefail

steps="$1/steps"

patch_file() {
    local file="$1" expr="$2"
    PATCH_EXPR="$expr" perl -0pi -e '
        my $n = eval $ENV{PATCH_EXPR};
        die "pattern did not match in $ARGV\n" unless $n;
    ' "$file"
}

run="$steps/run-benchmarks.sh"
patch_file "$run" 's{^(  # Run benchmarks for baseline release\n)}{  cp "\$CANDIDATE_SRC/benchmark/runall.sh" "\$BASELINE_SRC/benchmark/runall.sh"\n$1}m'
patch_file "$run" 's{^(    awk [^\n]*tracer-bench-results-opcache\.csv > [^\n]*\n    mv [^\n]*tracer-bench-results-opcache\.csv) && \\\n}{    if [ -f \$ARTIFACTS_DIR/tracer-bench-results-opcache.csv ]; then\n$1\n    fi\n}m'
patch_file "$run" 's{^([ \t]*)cp (\$ARTIFACTS_DIR/tracer-[a-z-]+\.(?:csv|json)) "\$OUTPUT_DIR"$}{$1if [ -f $2 ]; then cp $2 "\$OUTPUT_DIR"; fi}mg'

comment="$steps/post-pr-comment.sh"
patch_file "$comment" 's{--header="Benchmarks \[ \$SCENARIO \]"}{--header="Benchmarks [ \$SCENARIO \$TRACER_PART ]"}'

analyze="$steps/analyze-results.sh"
patch_file "$analyze" 's{^([ \t]*)BASELINE_BRANCH=\$\(github-find-merge-into-branch ([^\n]*) \|\| :\)$}{$1BASELINE_BRANCH=\$(cat "\$ARTIFACTS_DIR/baseline_branch.txt" 2>/dev/null || github-find-merge-into-branch $2 || :)}m'
patch_file "$analyze" 's{^([ \t]*)(benchmark_analyzer convert \\\n(?:[^\n]*\\\n)*?\s*("\$ARTIFACTS_DIR/(?:candidate|baseline)/\$\{SCENARIO\}-(?:bench-results(?:-opcache)?\.csv|tea-bench-results\.json)")\n)}{$1if [ -f $3 ]; then\n$1$2$1fi\n}mg'
patch_file "$analyze" 's{^([ \t]*)(benchmark_analyzer compare pairwise \\\n(?:[^\n]*\\\n)*?)\s*"\$ARTIFACTS_DIR/baseline_\$\{SCENARIO\}_bench\.converted\.json"[^\n]*\\\n[^\n]*bench_opcache[^\n]*\\\n[^\n]*tea_bench[^\n]*\n}{$1pairs=()\n$1for p in bench bench_opcache tea_bench; do\n$1  b="\$ARTIFACTS_DIR/baseline_\${SCENARIO}_\$p.converted.json"; c="\$ARTIFACTS_DIR/candidate_\${SCENARIO}_\$p.converted.json"\n$1  if [ -f "\$b" ] && [ -f "\$c" ]; then pairs+=("\$b" "\$c"); fi\n$1done\n$1$2$1  "\${pairs[\@]}"\n}m'
