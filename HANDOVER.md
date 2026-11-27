# HANDOVER

For the production dev team. Updated at the end of every milestone. Spec: `docs/BUILD_BRIEF.md`.

Current state: **M1 Handicap engine, v0.2.3.** Checked: 132 PHPUnit tests and 4 Playwright tests pass.

## What is real vs mocked

| Area | Status |
|---|---|
| Custom tables, roles, Clock, Settings, audit logger | Real (M0) |
| Handicap engine, stroke allocation, handicap snapshots, course/tee/hole data with validation, Stableford and matchplay scorers | Real (M1) |
| Brackets, match lifecycle, results storage | Not built yet (M3+) |
| Payments (`PaymentGateway`) | Will be mocked (M2) |
| Wedge scoring link, WHS handicap lookup, email delivery | Will be mocked (M2, M5) |

## Mocks and the interface a real version must implement

None yet. Each mock added in a later milestone is listed here with its interface.

## Known shortcuts and limits

- `CGC_DEMO_MODE` defaults to `false`. Set `define( 'CGC_DEMO_MODE', true );` in `wp-config.php` on the demo site.
- Schema is created with `dbDelta` from PHP arrays in `src/Infrastructure/Schema.php`. No foreign keys (dbDelta does not manage them); integrity is the code's job.
- `team_members.active_player_id` equals `player_id` while a member is active and is set to NULL when they leave. The unique key `(tournament_id, active_player_id)` then enforces "one active team per player per tournament".
- The first install creates tenant 1 (`default`, "Creator Golf Club"). Every table defaults `tenant_id` to 1.
- `cgc_group_manager` has the tournament admin capabilities for now. Scoping to assigned regions/groups through `map_meta_cap` comes later.
- `vendor/` is not committed. Run `composer install` (the plugin shows an admin notice if it is missing).
- Activation was tested on the Foundation test site (SQLite). Run it once on MySQL/MariaDB as well before relying on index behaviour.

## Handicap engine and scorers (M1)

- `Domain/Handicap/HandicapProfile`: every rule as a setting (`cgc()`, `whs_2024()`, `whs_old()` presets; `from_array()` for a stage's `handicap_profile_json`; `from_setting()` maps the global `handicap_method` setting). Bad values throw, they are never guessed.
- `HandicapEngine::calculate( players, profile, format )` returns a `PlayerHandicap` per player; `->to_snapshot( profile )` is the audit record. Formats: `singles_mp`, `fourball_mp` (off the lowest), `individual_sp`, `stableford` (no lowest). Foursomes uses `foursomes_side_strokes()`.
- `Infrastructure/LineupSnapshotRepository::snapshot_match()` stores one snapshot per player in `cgc_match_lineups.snapshot_json`. Replacing one needs a reason and writes an audit row (`handicap.snapshot_replace`).
- `Infrastructure/CourseRepository`: course, tee and hole CRUD, validated by `Domain/Course/CourseValidator` (slope 55 to 155, 18 holes, SI 1 to 18 once each, hole pars add up). It does not check capabilities: the REST controller that calls it must.
- `Domain/Scoring/MatchplayScorer` (hole result, "2 UP thru 12", margin "3&2", dormie, extra holes) and `StablefordScorer`.
- Checked by unit tests: worked example 0/7/15/20 (CGC) and 0/7/15/19 (allowance on index), WHS check cases 0/8/14/22 (2024) and 0/9/15/22 (old), +4 to +3 (Tier 1) and +3.6 to +4 (Tier 2), 20 strokes = 2 on SI 1 and 2, "3&2". `tests/e2e/handicap-m1.spec.js` checks the repositories on a real site.

Choices made where the brief was silent (easy to change in `HandicapEngine`; confirm with CGC):

- Allowance on the index (`allowance_applies_to = index`): the allowance is applied to the index, so `method` makes no difference.
- `diff_then_pct`: the percentage applies to the difference to the lowest rounded course handicap. With CGC tiers it uses each player's own tier percentage.
- Per-team penalty (`penalty_scope = per_team`): taken once per team, from the player with the highest playing handicap. Per-player takes it from every flagged player. Both are applied before the lowest is found.
- A player under `min_rounds_12m` is refused (an exception) unless an organiser sets a tier by hand. An index outside -10 to 54 is refused.
- Extra holes: a level match after 18 needs extra holes (the first hole won decides, margin text "19th hole"). A putt-off is not scored here (M5). Pass `allow_halved` for formats where a halved match is allowed.
- Holes entered after a match is decided are ignored and counted in `ignored_holes`.
- `freeze_at` only says when the index must be read (`HandicapEngine::freeze_instant()`). Reading the index at that moment is the caller's job.
- The database allows one snapshot per tenant, match and player (unique key on `cgc_match_lineups`, `CGC_DB_VERSION` 4, still enforced). A whole lineup is saved in one transaction; replacing a lineup removes players who are no longer in it and audits each removal (`handicap.snapshot_remove`).
- A snapshot freezes the tee's hole pars and stroke indexes, so later edits to a scorecard cannot change an old result.
- With `diff_then_pct` the penalty comes off the final shots (not off the number being scaled). Minimum rounds apply to every allowance source; an organiser tier skips them. In stroke-play formats the snapshot's playing handicap is the shots received.
- Four-ball Stableford is scored per player on that player's own tee (`score_fourball` takes a `tee` per player). Matchplay `holes_from_gross` still takes one tee for stroke indexes.
- A tee's course rating must be 40 to 90, its par 54 to 80, and each hole par 3 to 6.
- Saving a lineup takes a MySQL named lock (`GET_LOCK`, `Db::lock()`) for the match before it starts the transaction, and releases it after commit or rollback. If the lock, the engine check or the locked read fails, nothing is written. A wait that ran out says "another change in progress"; a server that refuses GET_LOCK (Galera/Percona clusters) gives its own error (`cgc_lock_unavailable`, via `Db::lock_unavailable()`) and lineups cannot be saved there until a different lock is built. On the SQLite test site the lock is skipped (one writer at a time already).
- `Db::is_sqlite()` checks that `$wpdb` is the `WP_SQLite_DB` class (the drop-in defines `SQLITE_DB_DROPIN_VERSION` even on MySQL sites). Lock names are prefixed with a hash of the database name and table prefix. `Db::begin()` records `CONNECTION_ID()`, and `Db::commit()` fails if WordPress reconnected since (a retried write would have run outside the transaction).
- While a lock or transaction is open, `Db` switches off wpdb's reconnect-and-retry (the protected `reconnect_retries` setting, by reflection) and puts it back after. Otherwise a write retried on a new connection would run outside the transaction. If the setting cannot be changed, `Db::lock()` and `Db::begin()` fail and nothing is written. A dropped connection then ends the request with WordPress's database error page, and MySQL undoes the open transaction. `Db::begin()` also checks `CONNECTION_ID()` before and after START TRANSACTION, and `Db::commit()` before and after COMMIT. Each upgrade delete names its own audit row id.
- `Db::begin()` fails closed: if the InnoDB check cannot be run, or a table is missing or not InnoDB, no transaction starts.
- `Infrastructure/Migrations` runs before dbDelta on every install or upgrade (`CGC_DB_VERSION` 5): converts non-InnoDB plugin tables to InnoDB and removes duplicate snapshot rows (newest kept, each removal audited as `handicap.snapshot_duplicate_removed`; the DELETE only matches while its audit row exists).
- A per-team penalty tie is decided on the rounded shots each player receives; the first listed pays on a tie.
- The lock, engine and upgrade code was only tested against a scripted database (`tests/Support/FakeWpdb.php`) and on SQLite. It has not run on real MySQL or MariaDB: do that before relying on it (two overlapping saves for one match, and an upgrade of a site whose tables are MyISAM).
- The repositories are only tested through `tests/e2e/handicap-m1.spec.js` on the SQLite test site. Run it once on MySQL/MariaDB too.

## Data model

All tables are `{prefix}cgc_*`, and each has `id`, `tenant_id`, `created_at`, `updated_at`, `is_demo`. The full list and key columns are in BUILD_BRIEF section 5; the code is `src/Infrastructure/Schema.php`.

Main relationships: tournament → stages → groups → rounds/matches; teams belong to a tournament and a group; team_members link players to teams; matches → match_lineups, match_proposals, match_results, disputes; payments → team; golden_ticket_draws → golden_tickets → team.

## Layout

`src/Domain` (rules engines: `Handicap`, `Course`, `Scoring` so far), `src/Infrastructure` (Installer, Schema, Roles, AuditLogger), `src/Http`, `src/Jobs`, `src/Cli`, `src/Demo` (demo-only code), `assets/src` (React), `templates/emails`, `tests`.

Domain code takes "now" only from `CGC\Championship\Clock::now()`. Never call `time()` or `current_time()` there.

## Running things

- Install: `composer install` (and `npm install` when front-end code exists).
- Unit tests: `vendor/bin/phpunit`. Version check tests: `npm run test:version`.
- Coding standards: `vendor/bin/phpcs`. Static analysis: `vendor/bin/phpstan analyse`.
- Activation check on a real WordPress: `php tests/integration/activation-check.php <path-to-wordpress>`.
- Turn on the pre-commit version check once per clone: `npm run hooks`. It blocks a commit if the plugin `Version:` header is unchanged from HEAD, or if the header, `CGC_VERSION` and `package.json` disagree. In CI use `node scripts/check-version.mjs --base origin/main`.
- Demo commands (`wp cgc seed`, `simulate`, `reset`): not built yet (M7).

## Versioning

Bump `Version:` in `cgc-championship.php`, `CGC_VERSION` and `package.json` together. Change `CGC_DB_VERSION` only when the schema changes.

## Open questions (BUILD_BRIEF section 10) and the default used

Not yet wired. Unconfirmed rules are settings in `src/Settings.php`; current defaults: handicap method `cgc`, confirm window 48h, escalation 48h, 2nd Chance entry rule `first_played_match`, 2nd Chance Regionals penalty 1 shot.
