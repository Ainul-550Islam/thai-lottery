# PHASE 5.3.6: PRODUCTION OBSERVABILITY, MONITORING, ALERTING & OPERATIONAL HARDENING REPORT

## 1. Executive Summary & Scope

Phase 5.3.6 delivers an enterprise-grade observability, health assessment, telemetry export, and operational alerting framework for the Thai Lottery financial and lottery domain platform. The implementation strictly adheres to the core system tenets:
- **Zero Schema Mutations**: Unaltered database tables, columns, indexes, and primary/foreign keys.
- **Zero Business Logic Mutations**: Financial calculations, ledger invariants, money abstractions (`Money` / `bcmath`), and state machines remain intact.
- **Zero Sensitive Data Leakage**: Automated recursive redaction of credentials, API keys, webhook signatures, tokens, passwords, card data, and bank account numbers across all structured logs, audit records, exception handlers, and health endpoints.
- **Strict Correlation Traceability**: End-to-end correlation ID lifecycle tracking across HTTP headers, application logs, database audit entries, queued background jobs, and error diagnostics.

---

## 2. Observability Architecture & Components

```
                     ┌──────────────────────────────────────────────┐
                     │            Incoming HTTP Request             │
                     │ (Headers: X-Correlation-ID / X-Request-ID)   │
                     └──────────────────────┬───────────────────────┘
                                            │
                                            ▼
                     ┌──────────────────────────────────────────────┐
                     │          CorrelationIdMiddleware             │
                     │  - Ingests / Generates UUID v4               │
                     │  - Sets CorrelationContext (Async / Request) │
                     │  - Binds Monolog Context                     │
                     └──────────────────────┬───────────────────────┘
                                            │
               ┌────────────────────────────┼────────────────────────────┐
               │                            │                            │
               ▼                            ▼                            ▼
┌──────────────────────────────┐ ┌─────────────────────────┐ ┌─────────────────────────────┐
│      StructuredLogger        │ │    SystemHealthService  │ │  FinancialMetricsCollector  │
│ - JSON structured format     │ │ - Multi-tier checks:    │ │ - OpenMetrics / Prometheus  │
│ - Correlation ID injection   │ │   * Liveness (/up/live) │ │ - JSON Telemetry (/metrics) │
│ - Recursive key redaction    │ │   * Readiness (/up/ready│ │ - Queue, Webhook, Deposit,  │
│ - Dedicated [FINANCE] channel│ │   * Degraded (/up/health│ │   Withdrawal, Settlement    │
└──────────────┬───────────────┘ └──────────┬──────────────┘ └──────────────┬──────────────┘
               │                            │                               │
               ▼                            ▼                               ▼
┌──────────────────────────────┐ ┌─────────────────────────┐ ┌─────────────────────────────┐
│     AuditLog Boot Hook       │ │ OperationalAlertService │ │     Observability CLI       │
│ - Auto-stamps correlation ID │ │ - Cache deduplication   │ │ - ops:metrics-export        │
│ - Auto-redacts metadata      │ │ - Cooldown throttling   │ │ - ops:evaluate-alerts       │
│ - Immutable append-only trail│ │ - Dispatches async jobs │ │                             │
└──────────────────────────────┘ └─────────────────────────┘ └─────────────────────────────┘
```

---

## 3. Core Observability Implementations

### 3.1 Distributed Correlation Context (`App\Services\Observability\CorrelationContext`)
Provides static, thread-safe access to correlation identifiers across all runtime contexts:
- Resolves existing ID from request headers or generates a secure UUID v4.
- Injects correlation context into Laravel's `Context` facade and Monolog context processors.
- Propagates across asynchronous job dispatch boundaries.

### 3.2 Request Tracing Middleware (`App\Http\Middleware\CorrelationIdMiddleware`)
- Intercepts all incoming HTTP requests.
- Accepts incoming `X-Correlation-ID` or `X-Request-ID`.
- Guarantees both `X-Correlation-ID` and `X-Request-ID` are present on outgoing HTTP responses (including error responses).

### 3.3 Structured Logging & Secret Redaction (`App\Services\Observability\StructuredLogger`)
- Writes structured JSON logs with contextual metadata and correlation IDs.
- Provides specialized `financial()` log method tagging money movements with `is_financial: true` and operation classification.
- Implements recursive redaction against an exhaustive blacklist of sensitive keys:
  - `password`, `password_confirmation`, `current_password`
  - `secret`, `api_key`, `api_secret`, `private_key`, `webhook_secret`
  - `token`, `access_token`, `refresh_token`
  - `payout_details`, `card_number`, `cvv`, `pin`, `bank_account`, `authorization`
  - `x-bkash-signature`, `x-signature`, `x-crypto-signature`

### 3.4 Multi-Tier Health Assessment (`App\Services\Observability\SystemHealthService`)
Provides three operational endpoints:
1. **Liveness (`/up/live` or `/up`)**: Confirms web runtime is responsive (HTTP 200 `{"status": "UP"}`).
2. **Readiness (`/up/ready`)**: Verifies database PDO connection, cache backend read/write, and queue worker readiness (HTTP 200 or 503).
3. **Comprehensive Detailed Health (`/up/health`)**: Non-destructive evaluation of:
   - Database query latency (flagged if > 500 ms).
   - Cache storage read/write availability.
   - Queue backlog and stuck/reserved job threshold.
   - Failed job count (degraded if > 10).
   - Financial reconciliation staleness (degraded if last run > 24 hours ago).
   - Financial reconciliation anomaly status (degraded if last run status was `critical`).

### 3.5 Financial & Operational Metrics Collector (`App\Services\Observability\FinancialMetricsCollector`)
Exposes standardized metrics in both JSON format and Prometheus / OpenMetrics format via `/metrics` and CLI:
- `thai_lottery_up`: Application health gauge.
- `thai_lottery_queue_pending_jobs`: Total pending jobs count.
- `thai_lottery_queue_pending_by_priority{queue="..."}`: Pending jobs broken down by priority queue (`financial-critical`, `high`, `default`, `low`).
- `thai_lottery_queue_failed_jobs`: Failed jobs count.
- `thai_lottery_queue_stuck_jobs`: Stuck/reserved jobs count.
- `thai_lottery_deposits_total{status="..."}`: Deposits count by status.
- `thai_lottery_deposits_confirmed_amount_sum`: Sum of confirmed deposit funds.
- `thai_lottery_withdrawals_total{status="..."}`: Withdrawals count by status.
- `thai_lottery_withdrawals_completed_amount_sum`: Sum of completed withdrawal funds.
- `thai_lottery_payouts_total`: Total prize payouts count.
- `thai_lottery_payouts_amount_sum`: Total prize payout monetary volume.
- `thai_lottery_reconciliation_staleness_hours`: Hours since last successful reconciliation run.
- `thai_lottery_reconciliation_last_anomalies`: Anomaly count from last reconciliation run.

### 3.6 Deduplicated Operational Alerting (`App\Services\Observability\OperationalAlertService`)
- Asynchronously evaluates system health and financial anomalies.
- Enforces atomic cache lock deduplication (`Cache::add`) to prevent alert flooding during outage events.
- Dispatches alert events via `SendFinancialAlertJob` into background queues.
- Configurable cooldown windows (e.g. 15 minutes for queue backlogs, 1 hour for reconciliation staleness).

### 3.7 Console Commands
- `php artisan ops:metrics-export [--json] [--format=prometheus|json]`: Exports telemetry to stdout for Prometheus scrapers or log aggregators.
- `php artisan ops:evaluate-alerts`: Evaluates operational health thresholds, triggers deduplicated alerts, and logs anomaly state.

---

## 4. Comprehensive Test Suite & Platform Verification

### 4.1 Observability Test Suite (`ProductionObservabilityComprehensiveTest.php`)
All 25 test cases pass cleanly:

| Test Case | Condition Tested | Assertions | Result |
|---|---|---|---|
| `test_01` | Correlation ID generated when absent | UUID v4 pattern assertion | **PASS** |
| `test_02` | Correlation ID propagates through HTTP headers | `X-Correlation-ID`, `X-Request-ID` match | **PASS** |
| `test_03` | Safe correlation logging in Monolog/Audit context | AuditLog request_id assertion | **PASS** |
| `test_04` | Secret redaction in structured logs & audit records | Passwords, keys, bank details redacted | **PASS** |
| `test_05` | Financial operation context enriched in logs | Structured `is_financial: true` log entry | **PASS** |
| `test_06` | System health reporting healthy state | DB, Cache, Queue pass status | **PASS** |
| `test_07` | System health reporting degraded state on stale reconciliation | Reconciliation staleness warning | **PASS** |
| `test_08` | System health reporting unhealthy state when DB down | Liveness UP, Readiness validation | **PASS** |
| `test_09` | Stale reconciliation detection (>24 hours) | Warn status on >24h elapsed | **PASS** |
| `test_10` | Failed-job threshold detection (>10 failed jobs) | Degraded status & warning output | **PASS** |
| `test_11` | Financial-critical backlog detection (>50 pending) | Priority queue threshold warning | **PASS** |
| `test_12` | Webhook and deposit metrics collection | Deposit count by status telemetry | **PASS** |
| `test_13` | Withdrawal metrics collection | Withdrawal count & sum telemetry | **PASS** |
| `test_14` | Prize settlement metrics collection | Draw & Payout volume telemetry | **PASS** |
| `test_15` | Reconciliation discrepancy metrics collection | Critical anomaly count telemetry | **PASS** |
| `test_16` | Provider timeout observability | 60s timeout on disbursement job | **PASS** |
| `test_17` | Transient retry observability | 3 tries with [10, 30, 90] backoff | **PASS** |
| `test_18` | Permanent failure observability | Audit log recording on terminal fail | **PASS** |
| `test_19` | Health endpoints do not mutate financial data | Wallet balances & ledger row count identical | **PASS** |
| `test_20` | Health endpoints do not leak secrets | Zero credentials or keys in response | **PASS** |
| `test_21` | API production exception does not leak internals | Zero SQLSTATE, stack traces, or paths | **PASS** |
| `test_22` | Scheduled task evaluation command | `ops:evaluate-alerts` exit code 0 | **PASS** |
| `test_23` | Alert deduplication & cooldown throttling | Duplicate alerts within window blocked | **PASS** |
| `test_24` | Financial anomaly alert creation & dispatch | `SendFinancialAlertJob` dispatched | **PASS** |
| `test_25` | Prometheus / OpenMetrics format export | Valid OpenMetrics text format & HTTP endpoint | **PASS** |

### 4.2 Full Platform Regression Suite
```
Tests:    5 skipped, 699 passed (93,514 assertions)
Duration: ~63.9s
Pass Rate: 100.0%
```

---

## 5. Production Deployment & Operational Runbook

### 5.1 Health Check Endpoints
- **Kubernetes / Load Balancer Liveness Probe**:
  `GET /up/live` (or `/up`)
  Expected response: `200 OK`, `{"status":"UP","timestamp":"..."}`
- **Kubernetes / Load Balancer Readiness Probe**:
  `GET /up/ready`
  Expected response: `200 OK`, `{"ready":true,"database":true,"cache":true,"queue":true}`
- **Operator Health Dashboard**:
  `GET /up/health`
  Expected response: `200 OK` (or `503 Service Unavailable` if unhealthy). Returns latency, queue statistics, and reconciliation status.

### 5.2 Metrics Scraping (Prometheus / Grafana)
- Add scrape configuration:
  ```yaml
  scrape_configs:
    - job_name: 'thai_lottery'
      metrics_path: '/metrics'
      scrape_interval: 15s
      static_configs:
        - targets: ['app.thailottery.internal:80']
  ```

### 5.3 Scheduled Monitoring Cron Job
Add to production crontab:
```bash
* * * * * cd /var/www/thai-lottery && php artisan schedule:run >> /dev/null 2>&1
```
This automatically executes:
- `ops:evaluate-alerts` every 5 minutes.
- `queue:prune-failed --hours=168` weekly.
- `queue:prune-batches --hours=48` daily.

---

## 6. Deliverables & Artifacts Index

1. `app/Services/Observability/CorrelationContext.php` — Correlation Context Engine
2. `app/Http/Middleware/CorrelationIdMiddleware.php` — HTTP Request Tracing Middleware
3. `app/Services/Observability/StructuredLogger.php` — Structured JSON Logger with Secret Redaction
4. `app/Services/Observability/FinancialMetricsCollector.php` — Prometheus & OpenMetrics Telemetry Engine
5. `app/Services/Observability/SystemHealthService.php` — Multi-Tier System Health Assessor
6. `app/Services/Observability/OperationalAlertService.php` — Deduplicated Operational Anomaly Alerting Service
7. `app/Http/Controllers/HealthController.php` — Multi-Tier Health Endpoints Controller
8. `app/Http/Controllers/MetricsController.php` — Prometheus Metrics HTTP Controller
9. `app/Console/Commands/Observability/MetricsExportCommand.php` — CLI Metrics Exporter
10. `app/Console/Commands/Observability/EvaluateAlertsCommand.php` — CLI Alert Evaluator
11. `app/Models/AuditLog.php` — Boot-hooked correlation stamping & sensitive data redaction
12. `config/security.php` — Exhaustive sensitive fields redaction configuration
13. `bootstrap/app.php` — Observability middleware registration & scheduled tasks
14. `routes/web.php` — Health and metrics route bindings
15. `tests/Feature/Observability/ProductionObservabilityComprehensiveTest.php` — 25 mandatory observability test cases
16. `PHASE-5.3.6-PRODUCTION-OBSERVABILITY-REPORT.md` — Full operational report and runbook
