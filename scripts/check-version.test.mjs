// Tests for check-version.mjs. Run: node --test scripts/check-version.test.mjs
import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { check } from './check-version.mjs';

// Scratch repos live in .tmp/ (git-ignored) inside this folder.
mkdirSync('.tmp', { recursive: true });

const plugin = (v, extra = '') => `<?php\n/**\n * Plugin Name: X\n * Version: ${v}\n */\nconst CGC_VERSION = '${v}';\n${extra}`;
const pkg = (v) => JSON.stringify({ name: 'x', version: v });

function repo() {
  const dir = mkdtempSync(resolve('.tmp', 'ver-'));
  const g = (...a) => execFileSync('git', a, { cwd: dir, stdio: 'ignore' });
  g('init', '-q');
  g('config', 'user.email', 't@example.com');
  g('config', 'user.name', 't');
  const write = (v, extra) => {
    writeFileSync(join(dir, 'cgc-championship.php'), plugin(v, extra));
    writeFileSync(join(dir, 'package.json'), pkg(v));
  };
  return { dir, g, write };
}

test('first commit passes', () => {
  const r = repo();
  r.write('0.1.0');
  r.g('add', '-A');
  assert.deepEqual(check(r.dir), []);
  rmSync(r.dir, { recursive: true, force: true });
});

test('commit without a bump is blocked, with a bump passes', () => {
  const r = repo();
  r.write('0.1.0');
  r.g('add', '-A');
  r.g('commit', '-q', '-m', 'one');
  r.write('0.1.0', '// change\n');
  r.g('add', '-A');
  assert.match(check(r.dir).join(' '), /not bumped/);
  r.write('0.1.1', '// change\n');
  r.g('add', '-A');
  assert.deepEqual(check(r.dir), []);
  rmSync(r.dir, { recursive: true, force: true });
});

test('header, constant and package.json must agree', () => {
  const r = repo();
  writeFileSync(join(r.dir, 'cgc-championship.php'), plugin('0.1.0').replace("CGC_VERSION = '0.1.0'", "CGC_VERSION = '0.2.0'"));
  writeFileSync(join(r.dir, 'package.json'), pkg('0.3.0'));
  const errors = check(r.dir).join(' ');
  assert.match(errors, /CGC_VERSION/);
  assert.match(errors, /package\.json/);
  rmSync(r.dir, { recursive: true, force: true });
});

test('nothing staged passes', () => {
  const r = repo();
  r.write('0.1.0');
  r.g('add', '-A');
  r.g('commit', '-q', '-m', 'one');
  assert.deepEqual(check(r.dir), []);
  rmSync(r.dir, { recursive: true, force: true });
});

test('staged bump with unstaged package.json is blocked', () => {
  const r = repo();
  r.write('0.1.0');
  r.g('add', '-A');
  r.g('commit', '-q', '-m', 'one');
  writeFileSync(join(r.dir, 'cgc-championship.php'), plugin('0.1.1'));
  r.g('add', 'cgc-championship.php');
  writeFileSync(join(r.dir, 'package.json'), pkg('0.1.1')); // on disk only, not staged.
  assert.match(check(r.dir).join(' '), /package.json/);
  rmSync(r.dir, { recursive: true, force: true });
});

test('a missing base ref is an error', () => {
  const r = repo();
  r.write('0.1.0');
  r.g('add', '-A');
  r.g('commit', '-q', '-m', 'one');
  assert.match(check(r.dir, 'origin/nope').join(' '), /does not exist/);
  rmSync(r.dir, { recursive: true, force: true });
});

test('staged deletion of package.json is blocked even if the file is on disk', () => {
  const r = repo();
  r.write('0.1.0');
  r.g('add', '-A');
  r.g('commit', '-q', '-m', 'one');
  writeFileSync(join(r.dir, 'cgc-championship.php'), plugin('0.1.1'));
  r.g('add', 'cgc-championship.php');
  r.g('rm', '-q', '--cached', 'package.json');
  assert.match(check(r.dir).join(' '), /package.json not found/);
  rmSync(r.dir, { recursive: true, force: true });
});
