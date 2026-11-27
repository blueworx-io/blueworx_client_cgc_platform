# HANDOVER

For the production dev team. Updated at the end of every milestone. Spec: `docs/BUILD_BRIEF.md`.

Current state: **M0 Scaffold, v0.1.0.**

## What is real vs mocked

| Area | Status |
|---|---|
| Custom tables, roles, Clock, Settings, audit logger | Real (M0) |
| Handicap, brackets, match state, results | Not built yet (M1+) |
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

## Data model

All tables are `{prefix}cgc_*`, and each has `id`, `tenant_id`, `created_at`, `updated_at`, `is_demo`. The full list and key columns are in BUILD_BRIEF section 5; the code is `src/Infrastructure/Schema.php`.

Main relationships: tournament → stages → groups → rounds/matches; teams belong to a tournament and a group; team_members link players to teams; matches → match_lineups, match_proposals, match_results, disputes; payments → team; golden_ticket_draws → golden_tickets → team.

## Layout

`src/Domain` (rules engines, empty until M1), `src/Infrastructure` (Installer, Schema, Roles, AuditLogger), `src/Http`, `src/Jobs`, `src/Cli`, `src/Demo` (demo-only code), `assets/src` (React), `templates/emails`, `tests`.

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
