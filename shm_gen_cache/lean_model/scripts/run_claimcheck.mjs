#!/usr/bin/env node
import { spawn, execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const model = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const root = resolve(model, '..');
const runnerSource = await readFile(fileURLToPath(import.meta.url), 'utf8');
const manifest = JSON.parse(await readFile(join(model, 'claims.json'), 'utf8'));
const html = await readFile(join(root, 'docs', 'invariants.html'), 'utf8');
const htmlDefinitions = plain(html.slice(html.indexOf('<h2 id="1-scope">'),
  html.indexOf('<h2 id="6-invariants-overview">')));
const ids = process.argv.find(arg => arg.startsWith('--ids='))?.slice(6).split(',');
const selected = manifest.groups.filter(group => !ids || ids.includes(group.id));
if (ids && selected.length !== ids.length) throw new Error('Unknown or duplicate --ids value');
const statusOnly = process.argv.includes('--status-only');
const cacheStatus = [];

function decode(s) {
  return s.replace(/&(#x[0-9a-f]+|#[0-9]+|lt|gt|amp|quot|apos|nbsp);/gi, (_, entity) => {
    if (entity.startsWith('#x')) return String.fromCodePoint(parseInt(entity.slice(2), 16));
    if (entity.startsWith('#')) return String.fromCodePoint(parseInt(entity.slice(1), 10));
    return ({ lt:'<', gt:'>', amp:'&', quot:'"', apos:"'", nbsp:' ' })[entity.toLowerCase()];
  });
}

function plain(fragment) {
  return decode(fragment.replace(/<\/(?:p|li|ul|ol)>/g, '\n').replace(/<[^>]*>/g, ''))
    .replace(/[ \t\n]+/g, ' ').trim();
}

const headings = [...html.matchAll(/<h3 id="([^"]+)">([\s\S]*?)<\/h3>/g)];
function statement(id) {
  const heading = headings.find(match => match[1].startsWith(`${id.toLowerCase()}-`));
  if (!heading) throw new Error(`Missing HTML section ${id}`);
  const next = headings.find(match => match.index > heading.index);
  const body = html.slice(heading.index + heading[0].length, next?.index ?? html.length);
  const start = body.search(/<p><strong>Statement(?: [^<]*)?\.<\/strong>/);
  if (start < 0) throw new Error(`Missing Statement for ${id}`);
  const rest = body.slice(start);
  const end = rest.search(/<p><strong>(?:Outline|Proof)\./);
  return plain(end < 0 ? rest : rest.slice(0, end))
    .replace(/^Statement(?: \([^)]*\))?\.\s*/, '');
}

function documentation(file, source) {
  const open = source.indexOf('/-!');
  if (open < 0 || open > 1000) return '';
  const close = source.indexOf('-/', open + 3);
  return close < 0 ? '' : `-- ${file}.lean module documentation\n${source.slice(open + 3, close).trim()}`;
}

function declarations(file, source) {
  const out = [];
  for (const match of source.matchAll(/^(?:structure|inductive|def|abbrev)\s+([^\s(]+)(?:\s|\()/gm)) {
    const start = match.index;
    const rest = source.slice(start);
    const boundary = /\n(?=(?:structure|inductive|def|abbrev|theorem|namespace|end|variable|include|open|section|instance|attribute)\b)/g.exec(rest);
    const end = start + (boundary?.index ?? rest.length);
    const before = source.slice(0, start);
    const close = before.lastIndexOf('-/');
    const open = before.lastIndexOf('/--');
    const doc = open >= 0 && close > open && !before.slice(close + 2).trim()
      ? before.slice(open, close + 2).trim() + '\n' : '';
    out.push(`-- ${file}.${match[1]}\n${(doc + source.slice(start, end)).trim()}`);
  }
  return out;
}

function schema(properties, required = Object.keys(properties)) {
  return { type:'object', required, properties:Object.fromEntries(
    Object.entries(properties).map(([key, value]) => [key, value === 'verdict'
      ? { type:'string', enum:['confirmed','coverage_gap','uncertain','false_candidate'] }
      : { type:'string' }])) };
}

async function callClaudeOnce(modelName, prompt, outputSchema) {
  const args = ['-p', '--tools', '', '--output-format', 'json', '--max-turns', '12',
    '--json-schema', JSON.stringify(outputSchema), '--model', modelName];
  const env = { ...process.env };
  delete env.CLAUDECODE;
  return await new Promise((resolveCall, reject) => {
    const proc = spawn('claude', args, { cwd: root, env, stdio:['pipe','pipe','pipe'] });
    proc.stdin.end(prompt);
    let stdout = '', stderr = '';
    let timedOut = false;
    const timeout = setTimeout(() => { timedOut = true; proc.kill('SIGTERM'); }, 600_000);
    proc.stdout.on('data', chunk => { stdout += chunk; });
    proc.stderr.on('data', chunk => { stderr += chunk; });
    proc.on('error', error => { clearTimeout(timeout); reject(error); });
    proc.on('close', code => {
      clearTimeout(timeout);
      if (timedOut) return reject(new Error('claude timed out after 600 seconds'));
      if (code !== 0) {
        let summary = `${stderr.slice(0, 500)} ${stdout.slice(0, 500)}`;
        try {
          const failure = JSON.parse(stdout);
          summary = [failure.subtype, failure.stop_reason,
            `turns=${failure.num_turns}`, ...(failure.errors ?? [])].filter(Boolean).join(', ');
        } catch { /* Keep the raw, truncated diagnostic. */ }
        return reject(new Error(`claude exited ${code}: ${summary}`));
      }
      try {
        const envelope = JSON.parse(stdout);
        if (!envelope.structured_output) throw new Error(envelope.result || 'No structured output');
        resolveCall(envelope.structured_output);
      } catch (error) { reject(error); }
    });
  });
}

async function callClaude(modelName, prompt, outputSchema) {
  for (let attempt = 1; attempt <= 3; attempt++) {
    try { return await callClaudeOnce(modelName, prompt, outputSchema); }
    catch (error) {
      if (attempt === 3) throw error;
      console.error(`claude ${modelName} failed on attempt ${attempt}: ${error.message}`);
      await new Promise(resolveWait => setTimeout(resolveWait, attempt * 2000));
    }
  }
}

const auditDir = join(model, '.lake', 'claim-audit');
await mkdir(auditDir, { recursive:true });
execFileSync('node', [join(model, 'scripts', 'check_claims.mjs'), '--all-contexts'],
  { cwd:model, stdio:'ignore' });

for (const group of selected) {
  const resultPath = join(auditDir, `${group.id}.json`);
  const contextDir = join(model, '.lake', 'claim-context', group.id);
  const index = JSON.parse(await readFile(join(contextDir, 'theorems.json'), 'utf8'));
  const sources = await Promise.all(index.modules.map(async file =>
    [file, await readFile(join(contextDir, `${file}.lean`), 'utf8')]));
  const fullFiles = new Set(index.targets.map(item => item.file));
  fullFiles.add('ClaimClosure');
  const context = [
    ...sources.map(([file, source]) => documentation(file, source)).filter(Boolean),
    ...sources.flatMap(([file, source]) => fullFiles.has(file)
      ? [`-- Complete Lean source: ${file}.lean\n${source}`]
      : declarations(file, source)),
    ...index.supportingTheorems.map(item => `-- ${item.file}.${item.name}\n${item.statement}`),
  ].join('\n\n');
  const codeFiles = {
    SL1:['src/participant.rs'], OW1:['src/participant.rs','src/cache/rotation.rs',
      'src/cache/mod.rs','src/lock.rs','src/assert.rs'],
    OW2:['src/cache/rotation.rs','src/participant.rs',
      'src/cache/mod.rs','src/lock.rs'], EP1:['src/cache/rotation.rs'],
    EP2:['src/cache/rotation.rs'], EP3:['src/cache/rotation.rs'],
    EP4:['src/cache/rotation.rs','src/cache/store.rs'],
    PN1:['src/participant.rs'],
    PN2:['src/participant.rs','src/cache/store.rs','src/lock.rs','src/ffi.rs',
      'src/cache/mod.rs','src/cache/rotation.rs','src/cache/lookup.rs'],
    PN3:['src/participant.rs','src/cache/rotation.rs'],
    QU1:['src/cache/rotation.rs','src/cache/store.rs'],
    QU2:['src/cache/rotation.rs','src/cache/store.rs','src/cache/mod.rs'],
    SP1:['src/cache/rotation.rs','src/cache/store.rs'],
    SP2:['src/cache/store.rs','src/config.rs','src/arena/ctl.rs',
      'src/cache/rotation.rs'],
    SP3:['src/cache/store.rs','src/participant.rs'],
    SP4:['src/cache/store.rs'], SP5:['src/cache/store.rs','src/arena/mod.rs'],
    IX1:['src/cache/store.rs','src/arena/put.rs','src/arena/mod.rs',
      'src/arena/index.rs','src/config.rs'],
    HT1:['src/cache/lookup.rs','src/arena/probe.rs','src/util.rs'],
    HT2:['src/cache/lookup.rs','src/arena/probe.rs','src/arena/records.rs',
      'src/cache/store.rs','src/arena/put.rs','src/arena/index.rs',
      'src/util.rs'],
    HT3:['src/cache/lookup.rs','src/cache/store.rs','src/arena/probe.rs',
      'src/arena/records.rs','src/util.rs','src/arena/put.rs'],
    BD1:['src/config.rs','src/util.rs','src/cache/mod.rs','src/cache/store.rs',
      'src/cache/rotation.rs','src/cache/promotion.rs','src/participant.rs',
      'src/arena/mod.rs','src/arena/put.rs','src/lock.rs','src/assert.rs'],
    BD2:['src/config.rs','src/cache/mod.rs','src/cache/lookup.rs','src/cache/rotation.rs',
      'src/arena/mod.rs','src/arena/probe.rs','src/arena/put.rs',
      'src/arena/index.rs','src/arena/records.rs','src/participant.rs','src/util.rs','src/output.rs',
      'src/wait.rs','src/lock.rs','src/assert.rs',
      'src/cache/store.rs','src/cache/promotion.rs','src/occupancy.rs'],
    RB1:['src/participant.rs','src/pid.rs','src/linux_pid.rs','src/windows_pid.rs'],
    RB2:['src/participant.rs','src/cache/rotation.rs','src/lock.rs','src/pid.rs',
      'src/linux_pid.rs','src/windows_pid.rs','src/assert.rs','src/wait.rs',
      '../Cargo.toml','tests/owner_unwind.rs'],
    RB3:['src/cache/store.rs','src/arena/put.rs','src/arena/records.rs'],
    RB4:['src/cache/lookup.rs','src/arena/put.rs'],
    RB5:['src/cache/store.rs','src/cache/lookup.rs','src/cache/rotation.rs',
      'src/participant.rs','src/lock.rs','src/arena/probe.rs','src/assert.rs',
      'src/arena/put.rs','src/arena/index.rs','src/arena/records.rs','src/util.rs',
      'src/occupancy.rs','src/config.rs','src/pid.rs','src/cache/mod.rs',
      'src/arena/mod.rs','src/arena/ctl.rs'],
    RD1:['src/cache/promotion.rs','src/cache/rotation.rs','src/participant.rs',
      'src/cache/store.rs','src/wait.rs'],
    RD2:['src/cache/promotion.rs','src/cache/rotation.rs'],
    FR1:['src/cache/promotion.rs','src/cache/store.rs'],
    FR2:['src/cache/promotion.rs','src/cache/store.rs','src/arena/put.rs'],
    FR3:['src/cache/lookup.rs','src/cache/store.rs'],
    FR4:['src/cache/lookup.rs','src/cache/store.rs',
      'verification/src/bin/promotion_keeps_newer_stale_epoch_insert.rs'],
    FR5:['src/cache/lookup.rs','src/cache/store.rs','src/arena/put.rs',
      'src/cache/rotation.rs','src/cache/promotion.rs',
      'src/arena/probe.rs',
      'verification/src/bin/promotion_keeps_newer_stale_epoch_insert.rs'],
  }[group.id] ?? [];
  const codeContext = (await Promise.all(codeFiles.map(async file =>
    `-- Implementation source: ${file}\n${await readFile(join(root, file), 'utf8')}`))).join('\n\n');
  const targets = index.targets.map(item => `-- ${item.file}.${item.name}\n${item.statement}`).join('\n\n');
  const htmlStatement = statement(group.id);
  const hash = createHash('sha256').update(runnerSource + targets + context + htmlDefinitions +
    htmlStatement + codeContext).digest('hex');
  const existing = await readFile(resultPath, 'utf8').catch(() => '');
  const current = !!existing && JSON.parse(existing).contextSha256 === hash;
  if (statusOnly) {
    cacheStatus.push({ id:group.id, current });
    continue;
  }
  if (current && !process.argv.includes('--fresh')) {
    console.error(`${group.id}: current audit already exists`);
    continue;
  }
  const blindPrompt = `Back-translate the target Lean 4 theorem signatures into plain English. The HTML claim is not supplied. Use the full relevant declaration and proved-theorem index below to unfold every term and to derive consequences. Structure fields are assumptions of the modeled implementation; source comments document intended event meanings but do not add Lean facts. State exact hypotheses, conclusions, and supporting theorem names. Do not infer meaning solely from a name when a declaration or source comment is available. If a term is an abstract structure field, say so and cite its constraints. Do not claim an HTML mismatch in this pass.\n\nTARGETS:\n${targets}\n\nCONTEXT:\n${context}`;
  console.error(`${group.id}: blind pass (${blindPrompt.length} chars)`);
  const blind = await callClaude('haiku', blindPrompt,
    schema({ translation:'string', preconditions:'string', postconditions:'string',
      grounding:'string', uncertainty:'string' }, ['translation']));
  const groupCheck = group.id === 'RB5'
    ? ' For RB5, release_claim entry is unconditional: assess no_std panic/unwinding into ParticipantLock::drop as well as ordinary API returns. Do not dismiss unwinding merely because it is not a return, and do not assume fatal always aborts. Check the pinned-helper arithmetic and closed-call-graph source-site inventory against Rust. Source comments explain the manual correspondence but do not add Lean facts; identify any remaining unsupported unwind edge or assertion condition.'
    : '';
  const comparePrompt = `Compare every clause of the HTML statement with the joint guarantee of the target and supporting Lean theorems. The blind translation was generated without the HTML claim. You have the full proof bodies of primary modules, all relevant definitions, supporting theorem signatures, the HTML's event definitions and code facts, and relevant Rust implementation files. Structure fields are explicit assumptions; check each field used to bridge the HTML and Lean against the Rust and against the HTML's own definitions. A field is not established merely because another field in the same structure matches the code. In particular, inspect universal premises when an event can play more than one role or a thread can hold more than one registration. Include numeric bounds, tightness or reachability assertions, exact output claims, and quantifiers over failed paths. Classify confirmed only when *every* statement clause follows under justified Lean protocol assumptions. Classify coverage_gap if *any* clause lacks a formal obligation, even if the main safety conclusion follows without it. Classify uncertain when you cannot settle it, and false_candidate only when you can give a concrete counterexample or implementation path contradicting the statement. A missing theorem or assumption never by itself makes a statement false. For confirmed, the missing field must be exactly 'none'. Quote exact missing premises and source locations. Never infer a term's meaning solely from its name.${groupCheck}\n\nHTML CLAIM:\n${htmlStatement}\n\nHTML SCOPE, EVENT DEFINITIONS AND CODE FACTS:\n${htmlDefinitions}\n\nBLIND TRANSLATION:\n${JSON.stringify(blind)}\n\nTARGETS:\n${targets}\n\nLEAN CONTEXT:\n${context}\n\nRUST IMPLEMENTATION:\n${codeContext}`;
  console.error(`${group.id}: comparison (${comparePrompt.length} chars)`);
  const comparison = { classification:'', counterexample:'',
    ...await callClaude('sonnet', comparePrompt,
      schema({ verdict:'verdict', explanation:'string', missing:'string',
        classification:'string', counterexample:'string' },
      ['verdict', 'explanation', 'missing'])) };
  if (comparison.verdict === 'confirmed' && comparison.missing.trim().toLowerCase() !== 'none') {
    comparison.verdict = 'coverage_gap';
    comparison.classification = `Checker claimed complete coverage but listed open work: ${comparison.classification}`;
  }
  const result = { id:group.id, title:group.title, htmlStatement, targets:index.targets,
    modules:index.modules, codeFiles, supportingTheoremCount:index.supportingTheorems.length,
    contextSha256:hash, blind, comparison };
  await writeFile(resultPath, JSON.stringify(result, null, 2) + '\n');
  console.error(`${group.id}: ${comparison.verdict}`);
}
if (statusOnly) console.log(JSON.stringify(cacheStatus));
