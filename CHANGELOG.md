# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-10-28

### Added
- Initial package scaffold, contracts, provider registry and migrations.
- Sending pipeline: single send, bulk send, templates, status workflow, scheduler, retry queue and worker.
- Africa's Talking provider adapter with send, bulk, DLR webhooks, inbound SMS and balance.
- Celcom Africa provider adapter with send, bulk and balance.
- Webhook service with shared-secret / IP allow-list verification and opt-out keyword handling.
- Documentation and example scripts.
