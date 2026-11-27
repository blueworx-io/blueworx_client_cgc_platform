# Changelog

## 0.1.6

- Added approved-deps.json so the dependency check passes.
- Index check works on SQLite; reactivation clears the setup-failure notice.
- Browser test setup refuses a non-local site unless its wp-content folder is given, and installs packages in the plugin copy the site loads.

## 0.1.5

- Index checks now compare columns and uniqueness; failed database setup retries every 5 minutes with an admin notice.
- Version check follows WordPress version ordering.
- Lint script no longer needs Composer packages; browser tests install them when missing.

## 0.1.4

- Added a lint script and browser tests that check activation, the database tables and their indexes, and repeat activation.

## 0.1.3

- Database version raised so existing sites recheck their indexes.
- A failed activation no longer switches the plugin off on a site where it was already active.
- The version check treats a prerelease as lower than its release.

## 0.1.2

- Install now checks every custom index, stops activation with a clear error if setup fails, and no longer depends on table-name case.
- The version check now rejects a version that goes down.

## 0.1.1

- Added the standard BlueWorx WordPress CI workflow (needs Luke to add it) and a Vercel config so Vercel no longer builds this repo.
- Added the assets/src/index.js entry file so the front-end build runs.

## 0.1.0

- New plugin scaffold: Composer (Action Scheduler, Faker), `@wordpress/scripts`, PSR-4 `CGC\Championship\`.
- Activation and upgrade routine creates all 32 `cgc_*` tables with `dbDelta`. Safe to run again.
- Roles and capabilities: super admin, tournament admin, group manager, player.
- Clock (demo-aware), Settings, audit logger and the `CGC_DEMO_MODE` switch.
- PHPCS, PHPStan, PHPUnit and a pre-commit check that blocks a commit without a version bump.
- `HANDOVER.md` skeleton.
