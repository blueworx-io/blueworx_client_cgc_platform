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

  const current = readFileSync(file, 'utf8');
  const header = headerVersion(current);
  const constant = constantVersion(current);
  if (!header) errors.push('Version header not found in plugin file.');
  if (header !== constant) errors.push(`Version header (${header}) and CGC_VERSION (${constant}) differ.`);

  const pkgPath = join(root, 'package.json');
  if (existsSync(pkgPath)) {
    const pkg = JSON.parse(readFileSync(pkgPath, 'utf8')).version;
    if (pkg !== header) errors.push(`package.json version (${pkg}) differs from the plugin header (${header}).`);
  }

  let before;
  let staged = header;
  if (base) {
    before = git(root, ['show', `${base}:${MAIN_FILE}`]);
  } else {
    const stagedFiles = git(root, ['diff', '--cached', '--name-only']);
    if (stagedFiles === null || stagedFiles.trim() === '') return errors; // nothing to commit.
    before = git(root, ['show', `HEAD:${MAIN_FILE}`]);
    const stagedPhp = git(root, ['show', `:${MAIN_FILE}`]);
    staged = stagedPhp ? headerVersion(stagedPhp) : header;
  }

  if (before !== null && staged === headerVersion(before)) {
    errors.push(`Version was not bumped (still ${staged}). Raise Version and CGC_VERSION in ${MAIN_FILE} and package.json.`);
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
