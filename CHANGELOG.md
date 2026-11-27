# Changelog

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
