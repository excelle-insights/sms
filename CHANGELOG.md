# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.7] - 2026-10-29

### Added
- Clear runtime errors when provider credentials are missing or empty. Africa's Talking and Celcom now throw before making an unauthenticated API call.

## [1.0.6] - 2026-10-29

### Fixed
- Bind integer parameters with `PDO::PARAM_INT` in `MessageRepository::findDueScheduled()`, `findQueued()` and `findFailedRetryable()` so MySQL does not receive `LIMIT '100'` as a string.

## [1.0.5] - 2026-10-29

### Added
- `origin` field on `sms_messages` with default `campaign` to track where a message was sent from.
- `priority` field on `sms_messages` with default `5`. The worker dispatches lower numbers first (priority 1 before priority 5).
- `options['origin']` and `options['priority']` support in `SmsManager::send()` and `SmsManager::sendBulk()`.
- Migration to add `origin` and `priority` columns to existing installs.

## [1.0.4] - 2026-10-29

### Fixed
- Use unprefixed names in Phinx `table()` calls now that `table_prefix` is configured, preventing `ALTER TABLE` from failing because the table was created with a double prefix.
- Rename default migration log table to `phinxlog`.

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
