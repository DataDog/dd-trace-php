#!/usr/bin/env node
import { execFileSync } from 'node:child_process';
import { readFile } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const model = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const manifest = JSON.parse(await readFile(join(model, 'claims.json'), 'utf8'));
const dir = join(model, '.lake', 'claim-audit');
const freshness = new Map(JSON.parse(execFileSync('node',
  [join(model, 'scripts', 'run_claimcheck.mjs'), '--status-only'],
  { cwd:model, encoding:'utf8' })).map(row => [row.id, row.current]));
const rows = [];
for (const group of manifest.groups) {
  const source = await readFile(join(dir, `${group.id}.json`), 'utf8').catch(() => '');
  if (!source) {
    rows.push({ id:group.id, verdict:'not_run', detail:'No saved claim audit.' });
    continue;
  }
  const result = JSON.parse(source);
  if (!freshness.get(group.id)) {
    rows.push({ id:group.id, verdict:'stale',
      detail:'The HTML, Lean context, Rust source, or audit runner changed after this result.' });
    continue;
  }
  rows.push({ id:group.id, verdict:result.comparison.verdict,
    detail:result.comparison.missing || result.comparison.explanation,
    counterexample:result.comparison.counterexample || '' });
}
for (const id of manifest.outsideLean) {
  rows.push({ id, verdict:'outside_lean', detail:'No Lean theorem target.' });
}

console.log('# HTML claim audit\n');
console.log('| Claim | Status |');
console.log('| --- | --- |');
for (const row of rows) console.log(`| ${row.id} | ${row.verdict} |`);
console.log('\n## Open obligations\n');
for (const row of rows.filter(row => row.verdict !== 'confirmed')) {
  console.log(`### ${row.id}: ${row.verdict}\n`);
  console.log(`${row.detail}\n`);
  if (row.verdict === 'false_candidate') {
    console.log(`Proposed counterexample: ${row.counterexample}\n`);
  }
}
if (process.argv.includes('--fail-on-open') &&
    rows.some(row => row.verdict !== 'confirmed' && row.verdict !== 'outside_lean')) {
  process.exitCode = 1;
}
