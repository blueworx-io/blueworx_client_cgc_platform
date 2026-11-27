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

export function compareVersions(a, b) {
  const split = (v) => {
    const [core, ...pre] = v.split('-');
    return { nums: core.split('.').map((n) => parseInt(n, 10) || 0), pre: pre.join('-') };
  };
  const x = split(a);
  const y = split(b);
  for (let i = 0; i < Math.max(x.nums.length, y.nums.length); i++) {
    const d = (x.nums[i] ?? 0) - (y.nums[i] ?? 0);
    if (d !== 0) return d < 0 ? -1 : 1;
  }
  // A prerelease is lower than the release it leads up to.
  if (x.pre === y.pre) return 0;
  if (x.pre === '') return 1;
  if (y.pre === '') return -1;
  return x.pre < y.pre ? -1 : 1;
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
