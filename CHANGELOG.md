# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.3] - 2026-10-29

### Fixed
- Renamed DSN parser in `phinx.php` to avoid fatal redeclaration with `migrate.php`.

## [1.0.2] - 2026-10-28

### Added
- `vlucas/phpdotenv` dependency for `.env` configuration loading.
- `.env.example` with the new database credential names.

### Changed
- Database credentials now use `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` and `DB_DSN`.
- `config/sms.php`, `phinx.php`, `migrate.php` and `examples/send.php` load `.env` automatically.
- Documentation updated to show `.env` based setup.

## [1.0.1] - 2026-10-28

### Added
- `migrate.php` CLI script to run database migrations directly from the package.

### Changed
- Moved `robmorgan/phinx` from require-dev to require.

## [1.0.0] - 2026-10-28

### Added
- Initial package scaffold, contracts, provider registry and migrations.
- Sending pipeline: single send, bulk send, templates, status workflow, scheduler, retry queue and worker.
- Africa's Talking provider adapter with send, bulk, DLR webhooks, inbound SMS and balance.
- Celcom Africa provider adapter with send, bulk and balance.
- Webhook service with shared-secret / IP allow-list verification and opt-out keyword handling.
- Documentation and example scripts.
