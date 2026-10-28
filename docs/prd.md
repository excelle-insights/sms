# PRD: Excelle Insights SMS – Multi-Provider SMS Package

*Draft v0.3 · Package: `excelle-insights/sms` · Status: clarified for implementation*

## 1. Summary

A PHP package that gives Excelle applications one consistent API for sending and receiving SMS across multiple providers. Developers write `$sms->send(...)` once; the package routes the message through the **default provider stored in the database** (globally or per tenant), or an explicitly chosen one. v1 covers single sends, bulk sends, scheduling, delivery reports and inbound SMS.

It follows the conventions of the existing `excelle-insights/whatsapp` and `excelle-insights/quickbooks` packages (facade manager, `.env` configuration, Phinx migrations, prefixed tables, shared `http_request_logs`, injectable HTTP client and PDO) so that anyone who knows one package can use this one.

## 2. Problem and goals

**Problem.** Each SMS provider has its own API, auth scheme, payload shape, status vocabulary and webhook format. Applications that integrate directly get locked in, duplicate code per project, and cannot switch provider (for cost, coverage or outage reasons) without a rewrite.

**Goals**

1. One provider-agnostic API for send, bulk send, schedule, status and inbound.
2. Providers are plug-in adapters behind a single contract; adding one never touches core code.
3. Default provider is a runtime, database-backed setting, changeable without redeploying and overridable per tenant.
4. Normalised message lifecycle and delivery statuses regardless of provider.
5. Reliable by default: local persistence, retry queue, idempotency, full request logging.
6. Consistent with existing Excelle package structure and tooling.

**Non-goals (v1)**

- Automatic failover between providers (the design leaves a hook; see §12).
- MMS, RCS, WhatsApp (covered by the separate `whatsapp` package), voice or USSD.
- A UI/admin panel (the package ships a minimal example only).
- Billing/wallet management beyond reading provider balance where supported.

## 3. Users and use cases

| Persona | Need |
| --- | --- |
| Application developer | Send an OTP or notification with one call, without knowing which provider is active. |
| Platform admin | Switch the default provider, or set one per tenant, from a settings screen or script. |
| Operations | Send large campaigns, schedule reminders, see delivery outcomes and failures. |
| Package maintainer | Add a new provider by implementing one interface and passing a shared contract test suite. |

Typical flows: transactional (OTP, invoice reminders, payment confirmations), bulk (announcements, statement notices), scheduled (due-date reminders), inbound (replies, opt-out keywords).

## 4. Supported providers (v1)

| Provider | Notes |
| --- | --- |
| Africa's Talking | Single send, bulk, delivery reports via webhook, inbound SMS via shortcode/callback, balance. |
| Celcom Africa | Single send, bulk send, balance. Native scheduling exists but is not used (see §5.6). Delivery reports and inbound SMS are not publicly documented as webhooks, so they are not implemented in v1. |

The v1 capability matrix below drives where the package fills gaps locally.

| Capability | Africa's Talking | Celcom Africa |
| --- | --- | --- |
| Single send | Yes | Yes |
| Bulk send | Yes | Yes |
| Scheduling | Local scheduler (package) | Local scheduler (package) |
| DLR webhook | Yes | No (not documented) |
| DLR polling | No | Not implemented in v1 |
| Inbound SMS | Yes | No (not documented) |
| Balance | Yes | Yes |
| Webhook verification | Shared secret / signature | IP allow-list / shared-secret query param |

## 5. Functional requirements

### 5.1 Provider abstraction

- `SmsProviderInterface` with: `send()`, `sendBulk()` (optional), `getStatus()`, `parseDeliveryReport(request)`, `parseInbound(request)`, `getBalance()` (optional), `capabilities()`.
- Capability flags (bulk, scheduling, inbound, balance, delivery-report mode) let core services decide whether to delegate to the provider or emulate locally. v1 uses the local scheduler for all providers; native scheduling is not used.
- Provider registry: providers register by key (`africastalking`, `celcom`). Third parties can register custom providers.
- A shared **provider contract test suite** every adapter must pass (using recorded HTTP fixtures).
- A `log` / `null` provider for local development and tests (writes to log, never sends).

### 5.2 Default provider selection (DB-backed, per tenant)

- Provider settings live in the database, not only in `.env`.
- `tenant_id` is an `INT`, nullable for global records.
- Resolution order for every send: **(1)** provider explicitly passed in the call → **(2)** tenant default → **(3)** global default (`tenant_id = null`) → **(4)** optional `.env` bootstrap value (`SMS_DEFAULT_PROVIDER`) used only if the database has no default → otherwise throw `NoDefaultProviderException`.
- Credentials use the same tenant/global fallback: tenant-specific credentials are tried first, then global credentials for that provider. If neither exists, the send fails with a clear exception.
- API: `setDefaultProvider($key, $tenantId = null)`, `getDefaultProvider($tenantId = null)`, `provider($key)` for one-off use, `listProviders($tenantId)`.
- Exactly one default per tenant, enforced at the database level by a partial unique index on `(tenant_id) WHERE is_default = TRUE`.
- Changing the default takes effect on the next send with no restart; in-flight and queued messages keep the provider they were created with.
- Each message records the provider used and how it was resolved (explicit/tenant/global/env).

### 5.3 Provider credentials

- Credentials stored per provider and per tenant, **encrypted at rest** (libsodium, key from `SMS_ENCRYPTION_KEY`).
- Fields: provider key, tenant id, credential payload (API key, username, secret, etc.), default sender ID, enabled flag, is\_default.
- Tenant-specific credentials are used first; if absent, global credentials (`tenant_id = null`) for the same provider are used.
- Secrets are never logged and never returned in plain text by any API.
- A `testConnection($provider)` helper validates credentials (via balance call or a dry-run where supported).

### 5.4 Sending

- `send(to, message, options)` where the message is plain text or a stored template (see 5.15), and options include: sender ID, provider, tenant, reference/metadata, idempotency key, scheduled time, and pending (hold for approval; default false, so messages are queued).
- Messages are persisted locally first with a lifecycle status (queued by default, scheduled when it has a future send time, or pending only when the call explicitly asks for approval; see 5.12 and 5.13), then dispatched; the result object exposes `status`, `local_id`, `provider_message_id`, `error`, mirroring the `synced / queued` result pattern in the QuickBooks package.
- Body and recipient are immutable once the message is created. Pending or scheduled messages may be cancelled or rejected, but never edited.
- Phone validation and normalisation to E.164 with a configurable default region (`SMS_DEFAULT_COUNTRY`, e.g. `KE`).
- Encoding and segment calculation (GSM-7 vs UCS-2) recorded on the message; configurable max length.
- Idempotency key prevents duplicate sends on retries or double-submits. Keys are valid for 24 hours.

### 5.5 Bulk sending

- `sendBulk(recipients, message|template, options)` creates a **batch** with one message row per recipient.
- Supports a shared message or per-recipient personalisation (`{name}`, `{amount}` placeholders) or a stored template (see 5.15) with per-recipient variables.
- Chunks recipients to each provider's maximum per request; uses native bulk endpoints when available, otherwise rate-limited single sends.
- Per-recipient outcomes; one bad number never fails the whole batch. Invalid and duplicate numbers are reported and optionally de-duplicated.
- Batch-level progress: totals for queued, sent, delivered, failed, available through `getBatch($id)`.
- Configurable throttling (messages per second) per provider.

### 5.6 Scheduling

- `schedule(...)` or `scheduled_at` option, with a timezone-aware timestamp stored in UTC.
- v1 uses a **local scheduler** only. Provider-native scheduling (e.g. Celcom's `timeToSend`) is not used, so the package can support cancellation, rescheduling and uniform retry behaviour.
- A CLI worker (`examples/worker.php`) run from cron or a supervisor handles due scheduled messages and retries.
- Scheduled messages can be listed, rescheduled and cancelled until released.
- Batches can be scheduled as a whole.

### 5.7 Delivery reports

- `WebhookController` with `verify()` and `process()` per provider (same shape as the WhatsApp package), with routes like `/sms/webhook/{provider}/dlr`.
- Signature or shared-secret verification where the provider documents it; otherwise configurable IP allow-listing or shared-secret query parameter.
- Provider statuses are mapped to the normalised delivery status set (5.12); delivery reports never change the lifecycle status: `with_carrier, delivered, undelivered, expired, unknown`, with the raw provider status and code preserved.
- v1 is **webhook-driven only**. Polling for DLR is not implemented. Consequently, Celcom Africa delivery status will not be tracked until Celcom documents a webhook format.
- Raw payloads stored in `webhook_logs` for audit and replay.

### 5.8 Inbound SMS

- Inbound webhook per provider, normalised into an `InboundMessage` entity (from, to/shortcode, text, received\_at, provider id).
- v1 inbound SMS is implemented for Africa's Talking only. Celcom Africa inbound webhooks are not publicly documented.
- Stored in `inbound_messages`; an event/callback hook lets the host app react (e.g. auto-reply, ticket creation).
- Built-in handling of opt-out keywords (e.g. STOP) feeding the opt-out list.

### 5.9 Retry queue

- Failed sends caused by transient errors (timeouts, 5xx, rate limits) are retried with exponential backoff: 1, 2, 4, 8, 16 minutes, up to a maximum of 5 attempts.
- Permanent errors (invalid number, insufficient balance, auth failure) are not retried and are surfaced clearly.
- Final failure status and last error stored on the message.

### 5.10 Opt-outs and compliance

- An `opt_outs` table checked before send (per tenant).
- Marketing sends are blocked if the recipient is in the opt-out list. Transactional sends may bypass the check with an explicit `bypass_opt_out: true` flag.
- Compliance footers (e.g. opt-out wording) are added by the provider; the package does not append them.
- Logs support auditing under the Kenya Data Protection Act and communications regulator rules for sender IDs.

### 5.11 Logging and observability

- Every provider HTTP call logged to the shared `http_request_logs` table, with `Authorization`, API key and password fields redacted.
- PSR-3 compatible logger via the existing `LoggerInterface` contract.
- Balance check helper and optional low-balance event.

### 5.12 Message lifecycle vs delivery status

Every message carries **two independent status fields**: `status` (what our system has done with it) and `delivery_status` (what the carrier network reports). Delivery reports update only `delivery_status`; only the application, the approval flow and the worker update `status`.

**Lifecycle `status`** (our side, from creation to hand-off to the provider)

| Status | Meaning | Set by |
| --- | --- | --- |
| `pending` | Created but awaiting approval; will not be sent until approved | Package (on create) |
| `scheduled` | Approved (or approval not required) and waiting for its send time | Package / application |
| `queued` | Ready for dispatch; waiting for a worker | Package / application |
| `processing` | Claimed by a worker, provider call in progress | Worker only |
| `sent` | Accepted by the provider; delivery tracking takes over | Worker only |
| `failed` | Could not be sent: permanent error or retries exhausted | Worker, or application |
| `rejected` | Declined by an approver; never sent | Application |
| `cancelled` | Stopped before submission | Application |

**Delivery `delivery_status`** (carrier side, only meaningful once `status` is `sent`)

| Delivery status | Meaning |
| --- | --- |
| `none` | Not sent yet, so nothing to report |
| `with_carrier` | Provider has forwarded it to the carrier |
| `delivered` | Handset confirmed receipt |
| `undelivered` | Carrier or handset reported failure (error code kept) |
| `expired` | Not delivered within the validity period |
| `unknown` | Sent but no report received within the configured wait |

**Rules**

- `delivery_status` stays `none` until the message is `sent`; a delivery report for any other lifecycle state is logged but ignored.
- Delivery status only moves forward (`with_carrier` → `delivered`); a late `with_carrier` never overwrites `delivered`.
- A delivery report never changes `status`, and a status change never rewrites `delivery_status`.
- Both fields are indexed separately, so "everything the app is still holding" and "everything the carriers failed to deliver" are two simple queries.

&#91;embedded content: message lifecycle · 8 states\]

Approval is an optional gate that a call opts into with `pending: true`; messages created without it start directly at queued or scheduled. Once a message is sent, only its delivery status keeps changing.

### 5.13 Approval workflow

Messages are created as queued by default (or scheduled, when they have a future send time). Only when the call explicitly asks for approval is a message created as `pending` and does nothing until someone approves it. Approval is an application-level decision; the package records who approved and when, and enforces the state rules.

- **Pending is opt-in:** a message is never held for approval by default. It is created `queued` (or `scheduled` when `scheduled_at` is in the future) unless the call explicitly sets `pending: true`. There is no tenant-level or global approval default, and no automatic approval rules in v1.
- **Pending messages are inert:** no queue row is created, no provider call is made, and scheduled times are stored but not armed until approval.
- **Approve:** moves `pending` to `queued`, or to `scheduled` if `scheduled_at` is still in the future. If `scheduled_at` has already passed on approval, the caller chooses: send now (default) or reject as stale.
- **Reject:** moves `pending` to `rejected` with a required reason. A rejected message can be resubmitted, which returns it to `pending`.
- **Batches:** a bulk send creates the batch and all its messages as `pending` only when called with `pending: true`; otherwise they are queued (or scheduled). Approving or rejecting a pending batch applies to every message still `pending` in it; individual messages can also be rejected before the batch is approved (for example, one wrong recipient).
- **Provider and content are frozen at creation:** approvers approve exactly what was written. Editing a pending message's body or recipient is out of scope for v1; the application cancels and recreates it.
- **Authorisation is the host app's job:** the package ships an optional `ApprovalAuthorizerInterface` hook, called with the actor and the messages, so the app can enforce roles; without it the package trusts the caller.
- **Audit:** every approval, rejection and other transition is written to the status history table with the actor, reason and timestamp (section 7).

### 5.14 Status management interface

The application changes message lifecycle `status` only through one contract, `MessageStatusServiceInterface`, exposed on the facade as `$sms->status()`. It works on one message, a list of message ids, or a whole batch, and every change goes through the same transition rules. Delivery status is deliberately not part of it: it is written only by delivery reports.

```php
namespace ExcelleInsights\Sms\Contracts;

interface MessageStatusServiceInterface
{
    // Generic: any allowed transition
    public function change(int $messageId, MessageStatus $to, StatusContext $ctx): StatusResult;
    public function changeMany(array $messageIds, MessageStatus $to, StatusContext $ctx): BulkStatusResult;
    public function changeBatch(int $batchId, MessageStatus $to, StatusContext $ctx): BulkStatusResult;

    // Introspection for UI buttons
    public function allowedTransitions(int $messageId): array;

    // Named shortcuts (each also exists as ...Many and ...Batch)
    public function approve(int $messageId, StatusContext $ctx): StatusResult;
    public function reject(int $messageId, StatusContext $ctx): StatusResult;     // reason required
    public function cancel(int $messageId, StatusContext $ctx): StatusResult;     // reason required
    public function reschedule(int $messageId, StatusContext $ctx): StatusResult; // needs scheduled_at
    public function retry(int $messageId, StatusContext $ctx): StatusResult;      // failed -> queued
    public function resubmit(int $messageId, StatusContext $ctx): StatusResult;   // rejected -> pending
}
```

```php
// Approve a whole batch, then reject one bad message in another batch
$sms->status()->approveBatch(42, new StatusContext(actor: $user->id));
$sms->status()->rejectMany([501, 502], new StatusContext(actor: $user->id, reason: 'Wrong audience'));
```

**StatusContext** carries: `actor` (required, stored in history), `reason`, `scheduled_at`, `expected_from` (optional guard: only change if the message is still in this status), `atomic` (default false) and `dry_run` (validate and report without writing).

**Behaviour**

- **Transition rules are enforced centrally** by a `StatusTransitionPolicy`. The application cannot set `processing` or `sent`; those belong to the worker.
- **Single change:** an invalid transition throws `InvalidTransitionException` naming the current status and the allowed ones.
- **Bulk change:** best effort by default. Each message is validated and changed on its own (processed in chunks, for example 500 per transaction), and the call returns a per-message outcome (`changed`, `unchanged`, `invalid_transition`, `not_found`, `stale_state`, `denied`) plus totals. With `atomic: true` any failure rolls everything back.
- **Idempotent:** asking for the status a message already has returns `unchanged`, not an error, so a double-clicked Approve is safe.
- **Concurrency-safe:** rows are locked and the current status is re-checked inside the transaction, so an approval cannot race the worker or a cancel.
- **Side effects:** approving arms the schedule and makes the message available for dispatch; cancelling stops it from being dispatched. Because v1 uses the local scheduler only, no provider-native scheduled send needs to be cancelled.
- **Events:** after commit, a `MessageStatusChanged` event (message id, from, to, actor, reason) is passed to registered listeners.
- **History:** every change writes a row to the status history table.

**Allowed transitions**

| From | Application may move to | Worker / system only |
| --- | --- | --- |
| `pending` | `queued` or `scheduled` (approve), `rejected`, `cancelled` | none |
| `scheduled` | `queued` (send now), `scheduled` (reschedule), `cancelled` | `queued` (when due) |
| `queued` | `scheduled` (reschedule), `cancelled` | `processing` |
| `processing` | none | `sent`, `failed`, `queued` (retry with backoff) |
| `failed` | `queued` (retry), `cancelled` | none |
| `rejected` | `pending` (resubmit) | none |
| `sent` | none (final) | none |
| `cancelled` | none (final) | none |

### 5.15 Message templates

Applications create reusable SMS templates once and send them by key, with variables filled in at send time. Templates work for single sends, bulk sends and scheduled sends, and are independent of the provider.

**Template fields**

| Field | Rule |
| --- | --- |
| `key` | Short unique name such as `invoice_due`; unique per tenant |
| `name`, `description` | Human-readable label for admin screens |
| `body` | Message text with `{variable}` placeholders, for example `Hi {name}, invoice {ref} of {amount} is due on {due_date}.` |
| `variables` | Detected from the body automatically; each can be marked required (default) or given a default value |
| `sender_id` | Optional default sender ID; a send can override it |
| `category` | `marketing` (default) or `transactional`. Determines opt-out enforcement. |
| `status` | `draft`, `active` or `archived`; only `active` templates can be sent |
| `tenant_id` | A tenant's own template, or null for a global one |

**Behaviour**

- **Interface:** `$sms->templates()` exposes `create`, `update`, `get`, `list`, `archive`, `activate`, `preview` and `validate`, with the same tenant handling as the rest of the package.
- **Lookup:** a tenant's template with a given key wins over a global one with the same key. A missing or archived key throws `TemplateNotFoundException`.
- **Versioning:** editing a template creates a new version; old versions are kept. Every message stores the template id, version and variables used, so you can always see what was sent and from which wording.
- **Frozen at send:** the body is rendered when the message is created and the final text is stored on the message. Later template edits never change messages already `pending`, `scheduled` or `queued`.
- **Rendering rules:** a missing required variable throws `MissingTemplateVariableException` on a single send. Values are cast to plain text (no markup is interpreted). Unknown extra variables are ignored. The template category controls whether opt-out checks are enforced.
- **Bulk sends:** each recipient row carries its own variables. A recipient with a missing variable is skipped and reported in the batch result with the reason; it never fails the whole batch.
- **Preview:** `preview($key, $variables)` returns the rendered text, the encoding (GSM-7 or UCS-2) and the segment count, so users see the cost of a message before sending.
- **Validation on save:** rejects empty bodies, malformed placeholders and bodies whose longest rendered form is likely to exceed the configured maximum segments.
- **Approval:** templates do not change the approval rule. A message from a template is queued unless the call explicitly sets `pending: true`.

```php
// Create once
$sms->templates()->create([
    'tenant_id' => 7,
    'key'       => 'invoice_due',
    'name'      => 'Invoice due reminder',
    'body'      => 'Hi {name}, invoice {ref} of {amount} is due on {due_date}.',
]);

// Preview before using
$p = $sms->templates()->preview('invoice_due', ['name' => 'Wanjiru', 'ref' => 'INV-001', 'amount' => 'KES 5,000', 'due_date' => '5 Nov'], tenantId: 7);

// Single send from a template (queued by default)
$sms->sendTemplate('0712345678', 'invoice_due', ['name' => 'Wanjiru', 'ref' => 'INV-001', 'amount' => 'KES 5,000', 'due_date' => '5 Nov'], ['tenant_id' => 7]);

// Bulk send, each recipient with its own variables, held for approval
$sms->sendBulk([
    ['to' => '0712345678', 'vars' => ['name' => 'Wanjiru', 'ref' => 'INV-001', 'amount' => 'KES 5,000', 'due_date' => '5 Nov']],
    ['to' => '0723456789', 'vars' => ['name' => 'Otieno', 'ref' => 'INV-002', 'amount' => 'KES 3,200', 'due_date' => '5 Nov']],
], ['template' => 'invoice_due', 'tenant_id' => 7, 'pending' => true]);
```

Storage is two tables, `templates` (identity, status, current version) and `template_versions` (body, variables, author, timestamp); see section 7.

## 6. Architecture

Structure mirrors the existing packages:

```
src/
├── Contracts/      SmsProviderInterface, HttpClientInterface, LoggerInterface, CapabilityInterfaces, MessageStatusServiceInterface, ApprovalAuthorizerInterface
├── Providers/      AfricasTalking/ Celcom/ Log/  (Provider, Config, StatusMapper, WebhookParser)
├── Facade/         SmsManager
├── Services/       SendService, BulkService, SchedulerService, RetryService, DeliveryReportService, ProviderResolver, MessageStatusService, StatusTransitionPolicy, ApprovalService, TemplateService, TemplateRenderer
├── Repositories/   Message, Batch, ProviderConfig, InboundMessage, WebhookLog, OptOut, StatusChange, TenantSettings, Template, TemplateVersion
├── Entities/       Message, Batch, SendResult, DeliveryReport, InboundMessage, MessageStatus (enum), DeliveryStatus (enum), StatusContext, StatusResult, SmsTemplate, RenderedMessage
├── Validation/     PhoneNumberValidator, MessageValidator, TemplateValidator
├── Controller/     WebhookController
├── Config/         SmsConfig
├── Support/        EnvLoader, DatabaseLogger, DefaultHttpClient, Crypto
└── Exceptions/
database/migrations/   scripts/   examples/   tests/
```

**Key design decisions**

- **Adapter pattern + registry** for providers; core services depend only on the contract.
- **ProviderResolver** is the single place that implements default-provider resolution (§5.2).
- **Capabilities over assumptions**: services ask the provider what it supports and emulate the rest.
- **Local-first persistence** for every message, as in QuickBooks, so nothing is lost if a provider is down.
- **Dependency injection** of HTTP client and PDO via the manager constructor, as in both existing packages.

## 7. Data model (prefix from `SMS_TABLE_PREFIX`)

| Table | Purpose |
| --- | --- |
| `{prefix}_provider_configs` | Provider per tenant or global: encrypted credentials, sender ID, enabled, `is_default` (unique default per tenant via partial index) |
| `{prefix}_messages` | Every outbound message: provider, recipient, body, encoding/segments, lifecycle status, delivery status (separate column), approval fields (approved\_by, approved\_at, rejected\_reason), template id, version and variables (when sent from a template), provider id, scheduled\_at, attempts, idempotency key |
| `{prefix}_batches` | Bulk send header, its own lifecycle status (including pending approval) and counters |
| `{prefix}_delivery_reports` | Delivery status history per message (raw and normalised delivery status); never touches lifecycle status |
| `{prefix}_status_changes` | Audit trail of every lifecycle status change: message or batch, from, to, actor, reason, source (application, worker or system), timestamp |
| `{prefix}_tenant_settings` | Per-tenant options: default timezone |
| `{prefix}_templates` | Template identity per tenant (or global): key, name, category, sender ID, status (draft, active, archived), current version |
| `{prefix}_template_versions` | One row per template version: body, variable definitions (required, default), author, timestamp; never edited, only added |
| `{prefix}_inbound_messages` | Received SMS |
| `{prefix}_opt_outs` | Numbers that must not be messaged |
| `{prefix}_webhook_logs` | Raw webhook audit |
| `http_request_logs` | Shared, non-prefixed |

All tables carry a nullable `tenant_id` of type `INT`.

## 8. Developer experience

```php
use ExcelleInsights\Sms\Facade\SmsManager;

$sms = new SmsManager();

// Admin: choose the default (global, or per tenant)
$sms->setDefaultProvider('africastalking');
$sms->setDefaultProvider('africastalking', tenantId: 7);

// Single send via the default provider
$r = $sms->send('0712345678', 'Your OTP is 4821', ['tenant_id' => 7]);

// One-off with a specific provider
$sms->provider('celcom')->send('+254712345678', 'Hello');

// Bulk and scheduled
$batch = $sms->sendBulk($recipients, 'Hi {name}, invoice {ref} is due', [
    'scheduled_at' => '2026-11-05 09:00:00',
    'timezone' => 'Africa/Nairobi',
]);
```

`.env` carries only bootstrap values: `SMS_TABLE_PREFIX`, `SMS_ENCRYPTION_KEY`, `SMS_DEFAULT_COUNTRY`, `SMS_WEBHOOK_SECRET`, optional `SMS_DEFAULT_PROVIDER`, plus the shared `DB_*` variables. Provider credentials live in the database.

## 9. Non-functional requirements

- PHP ≥ 8.1, PSR-4 autoloading, MySQL via PDO (extensible later), Composer-installable from Packagist with tagged releases (not `dev-main`).
- Security: encrypted credentials, redacted logs, webhook verification, no secrets in exceptions.
- Performance: a 10,000-recipient batch is accepted and persisted without blocking the request; dispatch happens in the worker.
- Reliability: at-least-once dispatch with idempotency protecting against duplicates.
- Quality bar (improving on current packages): MIT `LICENSE`, PHPUnit with provider contract tests, static analysis (PHPStan), code style check, GitHub Actions CI, `CHANGELOG.md`, semantic versioning.

## 10. Success metrics

- A new provider adapter can be added in under one day by following the contract and passing the shared tests.
- Switching a tenant's default provider requires no code change or deploy.
- ≥ 95% of messages reach a final delivery status (delivered, undelivered or expired) after submission.
- Zero duplicate sends from retried requests in testing.

## 11. Open questions

All open questions from v0.2 have been resolved; see the relevant sections above.

## 12. Future (post-v1)

- Automatic failover and priority routing across providers.
- Cost-based and country-based routing rules.
- Framework bridges and an admin UI.
- Additional providers (Infobip, Vonage, etc.).

## 13. Proposed milestones

| Phase | Scope |
| --- | --- |
| M0 | Finalise PRD, repo scaffold, CI, licence |
| M1 | Contracts, provider registry, DB-backed resolver, credentials encryption, log provider, migrations |
| M2 | Sending pipeline: single send, bulk send, templates, retry queue, local scheduler, worker |
| M3 | Africa's Talking adapter: single send, bulk, DLR webhooks, inbound SMS, balance |
| M4 | Celcom Africa adapter: single send, bulk, balance (no DLR/inbound in v1) |
| M5 | Webhook service, opt-outs, documentation, examples, v1.0.0 release |
