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

function git(root, args) {
  try {
    return execFileSync('git', args, { cwd: root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });
  } catch {
    return null;
  }
}

export function check(root, base = null) {
  const errors = [];
  const file = join(root, MAIN_FILE);
  if (!existsSync(file)) return [`${MAIN_FILE} not found`];

  // Hook mode compares what is staged (Git's snapshot), not the files on disk.
  const stagedFiles = base ? null : git(root, ['diff', '--cached', '--name-only']);
  const hookMode = !base && stagedFiles !== null && stagedFiles.trim() !== '';
  const read = (name) => (hookMode ? git(root, ['show', `:${name}`]) : null) ?? (existsSync(join(root, name)) ? readFileSync(join(root, name), 'utf8') : null);

  const current = read(MAIN_FILE);
  const header = headerVersion(current);
  const constant = constantVersion(current);
  if (!header) errors.push('Version header not found in plugin file.');
  if (header !== constant) errors.push(`Version header (${header}) and CGC_VERSION (${constant}) differ.`);

  const pkgText = read('package.json');
  if (pkgText !== null) {
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

  if (before !== null && header === headerVersion(before)) {
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
