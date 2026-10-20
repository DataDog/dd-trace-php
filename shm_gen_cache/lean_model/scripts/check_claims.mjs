#!/usr/bin/env node
import { execFileSync } from 'node:child_process';
import { mkdir, readFile, readdir, writeFile } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const model = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const sourceDir = join(model, 'SgcProofs');
const manifest = JSON.parse(await readFile(join(model, 'claims.json'), 'utf8'));
const html = await readFile(join(model, '..', 'docs', 'invariants.html'), 'utf8');
const files = (await readdir(sourceDir)).filter(name => name.endsWith('.lean'));
const sources = new Map(await Promise.all(files.map(async name =>
  [name.slice(0, -5), await readFile(join(sourceDir, name), 'utf8')]
)));

function withoutComments(source) {
  let result = '', depth = 0, line = false;
  for (let i = 0; i < source.length; i++) {
    if (source[i] === '\n') { result += '\n'; line = false; continue; }
    if (line) continue;
    const pair = source.slice(i, i + 2);
    if (pair === '/-') { depth++; i++; continue; }
    if (pair === '-/' && depth) { depth--; i++; continue; }
    if (depth) continue;
    if (pair === '--') { line = true; i++; continue; }
    result += source[i];
  }
  return result;
}

function signatureEnd(declaration) {
  // Named arguments and binder defaults also contain `:=`. Only the
  // assignment outside the signature's delimiters starts the proof.
  let depth = 0, quoted = false, escapedString = false;
  for (let i = 0; i < declaration.length; i++) {
    const ch = declaration[i];
    if (quoted) {
      if (escapedString) escapedString = false;
      else if (ch === '\\') escapedString = true;
      else if (ch === '"') quoted = false;
      continue;
    }
    if (ch === '"') { quoted = true; continue; }
    if (ch === ':' && declaration[i + 1] === '=' && depth === 0) {
      return i;
    }
    if ('([{⦃'.includes(ch)) depth++;
    else if (')]}⦄'.includes(ch)) depth--;
  }
  return -1;
}

function checkSignatureExtraction() {
  for (const statement of [
    'theorem named : scanReady 5 true (SlotScan.done (epoch := 5) true) = false',
    'theorem defaulted (n : Nat := 0) : f (x := (n + 1)) = n',
    'theorem literal : "the characters := and (" = "the characters := and ("',
  ]) {
    const declaration = `${statement} := by exact proof (arg := 0)`;
    if (declaration.slice(0, signatureEnd(declaration)).trim() !== statement) {
      throw new Error(`Signature extraction self-check failed: ${statement}`);
    }
  }
}

function theoremSignature(file, name) {
  const source = sources.get(file);
  if (!source) throw new Error(`Missing Lean module ${file}`);
  const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const match = new RegExp(`^theorem\\s+${escaped}(?=\\s|\\()`, 'm').exec(source);
  if (!match) throw new Error(`Missing theorem ${file}.${name}`);
  const declaration = withoutComments(source.slice(match.index));
  const end = signatureEnd(declaration);
  if (end < 0) throw new Error(`Missing statement end ${file}.${name}`);
  return declaration.slice(0, end).trim();
}

function importsOf(file) {
  const source = sources.get(file);
  if (!source) throw new Error(`Missing Lean module ${file}`);
  return [...source.matchAll(/^import SgcProofs\.([A-Za-z0-9_]+)\s*$/gm)]
    .map(match => match[1]);
}

function importClosure(file, result = new Set()) {
  if (result.has(file)) return result;
  result.add(file);
  for (const imported of importsOf(file)) importClosure(imported, result);
  return result;
}

function htmlLineFor(id) {
  const heading = new RegExp(`^<h3 id="${id.toLowerCase()}-`, 'm').exec(html);
  if (!heading) throw new Error(`Missing HTML claim heading ${id}`);
  return html.slice(0, heading.index).split('\n').length;
}

function allTheorems(file) {
  const source = sources.get(file);
  return [...source.matchAll(/^theorem\s+([^\s(]+)(?:\s|\()/gm)]
    .map(match => ({ name: match[1], statement: theoremSignature(file, match[1]) }));
}

function checkHoles() {
  const errors = [];
  for (const [file, source] of sources) {
    const code = withoutComments(source);
    for (const match of code.matchAll(/\b(sorry|admit)\b|^\s*(axiom|constant|constants|opaque)\b/gm)) {
      const line = code.slice(0, match.index).split('\n').length;
      errors.push(`${file}.lean:${line}: ${match[0].trim()}`);
    }
  }
  if (errors.length) throw new Error(`Proof holes or new axioms:\n${errors.join('\n')}`);
}

function checkManifest() {
  const ids = new Set();
  for (const group of manifest.groups) {
    if (ids.has(group.id)) throw new Error(`Duplicate claim ${group.id}`);
    ids.add(group.id);
    if (!group.theorems.length) throw new Error(`No theorem for ${group.id}`);
    for (const ref of group.theorems) {
      const dot = ref.indexOf('.');
      if (dot < 0) throw new Error(`Invalid theorem reference ${ref}`);
      theoremSignature(ref.slice(0, dot), ref.slice(dot + 1));
    }
  }
  for (const id of manifest.outsideLean) {
    if (ids.has(id)) throw new Error(`Claim ${id} is both inside and outside Lean`);
    ids.add(id);
  }
  const htmlIds = new Set([...html.matchAll(/^<h3 id="([a-z]{2}[0-9]+)-/gm)]
    .map(match => match[1].toUpperCase()));
  if (htmlIds.size !== ids.size || [...htmlIds].some(id => !ids.has(id))) {
    throw new Error(`Manifest and HTML claim IDs differ: HTML=${[...htmlIds]}, manifest=${[...ids]}`);
  }
}

function build() {
  const output = execFileSync('lake', ['build'], { cwd: model, encoding: 'utf8' });
  process.stdout.write(output);
}

async function writeContext(id) {
  const group = manifest.groups.find(group => group.id === id);
  if (!group) throw new Error(`Unknown claim ${id}`);
  const primary = group.theorems.map(ref => {
    const dot = ref.indexOf('.');
    return { file: ref.slice(0, dot), name: ref.slice(dot + 1) };
  });
  const modules = [...new Set([...primary.map(item => item.file), ...group.modules]
    .flatMap(file => [...importClosure(file)]))].sort();
  const outDir = join(model, '.lake', 'claim-context', id);
  await mkdir(outDir, { recursive: true });
  const index = {
    id, title: group.title, htmlLine: htmlLineFor(id), modules,
    targets: primary.map(item => ({ ...item, statement: theoremSignature(item.file, item.name) })),
    supportingTheorems: modules.flatMap(file => allTheorems(file)
      .filter(item => !primary.some(target => target.file === file && target.name === item.name))
      .map(item => ({ file, ...item }))),
  };
  await writeFile(join(outDir, 'theorems.json'), JSON.stringify(index, null, 2) + '\n');
  for (const file of modules) {
    await writeFile(join(outDir, `${file}.lean`), sources.get(file));
  }
  console.log(`${id}: wrote ${modules.length} complete modules and ` +
    `${index.supportingTheorems.length} supporting theorem signatures to ${outDir}`);
}

checkSignatureExtraction();
checkHoles();
checkManifest();
build();
const id = process.argv.find(arg => arg.startsWith('--context='))?.slice('--context='.length);
if (id) await writeContext(id);
else if (process.argv.includes('--all-contexts')) {
  for (const group of manifest.groups) await writeContext(group.id);
}
else console.log(`Validated ${manifest.groups.length} theorem groups and ${manifest.outsideLean.length} outside-Lean groups`);
