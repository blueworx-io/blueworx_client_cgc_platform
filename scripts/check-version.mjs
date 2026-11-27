#!/usr/bin/env node
// Version check for the CGC Championship plugin.
//
//   node scripts/check-version.mjs [--root <dir>] [--base <ref>]
//
// Always fails if the plugin header Version, the CGC_VERSION constant and
// package.json disagree.
//
// Pre-commit use (no --base): when something is staged, the staged header
// version must differ from the one in HEAD. With no HEAD yet it passes.
// CI use: --base <ref> compares the working tree to that ref instead.

import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const MAIN_FILE = 'cgc-championship.php';

export function headerVersion(php) {
  const m = php.match(/^\s*\*\s*Version:\s*(\S+)/m);
  return m ? m[1] : null;
}

export function constantVersion(php) {
  const m = php.match(/const\s+CGC_VERSION\s*=\s*'([^']+)'/);
  return m ? m[1] : null;
}

// Same ordering as PHP's version_compare(), which WordPress uses for plugin updates.
const SPECIAL = { dev: 0, alpha: 1, a: 1, beta: 2, b: 2, RC: 3, rc: 3, '#': 4, pl: 5, p: 5 };

function canonical(v) {
  return v
    .replace(/[-_+]/g, '.')
    .replace(/([0-9])([^0-9.])/g, "$1.$2")
    .replace(/([^0-9.])([0-9])/g, "$1.$2")
    .split('.')
    .filter((p) => p !== '');
}

function formOrder(part) {
  for (const [name, order] of Object.entries(SPECIAL)) {
    if (part.startsWith(name)) return order;
  }
  return -6; // unknown text sorts below every known form.
}

function comparePart(p, q) {
  const pn = /^[0-9]+$/.test(p);
  const qn = /^[0-9]+$/.test(q);
  if (pn && qn) return Math.sign(parseInt(p, 10) - parseInt(q, 10));
  if (!pn && !qn) return Math.sign(formOrder(p) - formOrder(q));
  return pn ? Math.sign(formOrder('#') - formOrder(q)) : Math.sign(formOrder(p) - formOrder('#'));
}

export function compareVersions(a, b) {
  const x = canonical(a);
  const y = canonical(b);
  const len = Math.min(x.length, y.length);
  for (let i = 0; i < len; i++) {
    const c = comparePart(x[i], y[i]);
    if (c !== 0) return c;
  }
  if (x.length === y.length) return 0;
  const longer = x.length > y.length ? x : y;
  const sign = x.length > y.length ? 1 : -1;
  const next = longer[len];
  if (/^[0-9]+$/.test(next)) return sign;
  return Math.sign(formOrder(next) - formOrder('#')) * sign;
}

function git(root, args) {
  try {
    return execFileSync('git', args, { cwd: root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });
  } catch {
    return null;
  }
}

export function check(root, base = null) {
  const errors = [];
  // Hook mode compares what is staged (Git's snapshot), not the files on disk.
  const stagedFiles = base ? null : git(root, ['diff', '--cached', '--name-only']);
  const hookMode = !base && stagedFiles !== null && stagedFiles.trim() !== '';
  // In hook mode only Git's snapshot counts: a file missing there is missing from the commit.
  const read = (name) => {
    if (hookMode) return git(root, ['show', `:${name}`]);
    return existsSync(join(root, name)) ? readFileSync(join(root, name), 'utf8') : null;
  };

  const current = read(MAIN_FILE);
  if (current === null) return [`${MAIN_FILE} not found${hookMode ? ' in the commit' : ''}`];
  const header = headerVersion(current);
  const constant = constantVersion(current);
  if (!header) errors.push('Version header not found in plugin file.');
  if (header !== constant) errors.push(`Version header (${header}) and CGC_VERSION (${constant}) differ.`);

  const pkgText = read('package.json');
  if (pkgText === null) {
    errors.push(`package.json not found${hookMode ? ' in the commit' : ''}.`);
  } else {
    const pkg = JSON.parse(pkgText).version;
    if (pkg !== header) errors.push(`package.json version (${pkg}) differs from the plugin header (${header}).`);
  }

  let before = null;
  if (base) {
    if (git(root, ['rev-parse', '--verify', '--quiet', `${base}^{commit}`]) === null) {
      errors.push(`Base ref "${base}" does not exist. Fetch it first (for example git fetch origin main).`);
      return errors;
    }
    before = git(root, ['show', `${base}:${MAIN_FILE}`]);
  } else if (hookMode) {
    before = git(root, ['show', `HEAD:${MAIN_FILE}`]);
  } else {
    return errors; // nothing to commit.
  }

  const prev = before === null ? null : headerVersion(before);
  if (prev && header && compareVersions(header, prev) < 0) {
    errors.push(`Version went down (${prev} to ${header}). Raise it instead.`);
  } else if (before !== null && header === prev) {
    errors.push(`Version was not bumped (still ${header}). Raise Version and CGC_VERSION in ${MAIN_FILE} and package.json.`);
  }
  return errors;
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const argv = process.argv.slice(2);
  const opt = (name) => (argv.includes(name) ? argv[argv.indexOf(name) + 1] : null);
  const errors = check(resolve(opt('--root') ?? '.'), opt('--base'));
  if (errors.length) {
    for (const e of errors) console.error(`Version check failed: ${e}`);
    process.exit(1);
  }
  console.log('Version check passed.');
}
