# Production Queue & Worker Infrastructure

## 1. Overview
The Thai Lottery platform utilizes Laravel's queue subsystem for asynchronous execution of financial workflows, including real-money prize settlements, withdrawal disbursements, inbound payment webhooks, reconciliation audits, and alert notifications.

## 2. Priority Queue Topology
Workers must process queues in strict priority order to prevent low-priority jobs (e.g. notifications) from starving high-priority financial operations:

1. `financial-critical`: Real-money prize payouts, outbound withdrawal disbursements.
2. `webhooks`: Inbound payment gateway notifications and callbacks.
3. `reconciliation`: Financial ledger and wallet balance reconciliation audits.
4. `default`: General platform maintenance and background domain tasks.
5. `notifications`: Non-blocking player emails, SMS, and operator alerts.

## 3. Worker Execution Command
To start production queue workers with priority ordering and memory limits:
```bash
php artisan queue:work --queue=financial-critical,webhooks,reconciliation,default,notifications --sleep=3 --tries=3 --max-time=3600 --max-jobs=1000 --timeout=180
```

## 4. Supervisor Configuration
A production Supervisor configuration file is provided at:
`deployment/supervisor/thai-lottery-worker.conf`

To install and activate on Debian/Ubuntu:
```bash
sudo cp deployment/supervisor/thai-lottery-worker.conf /etc/supervisor/conf.d/
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start all
```

## 5. Graceful Restart & Deployments
During application deployments, gracefully restart queue workers using:
```bash
php artisan queue:restart
```
This instructs running workers to finish their current job before exiting, ensuring zero interrupted transactions or partial ledger writes.

## 6. Health & Liveness Monitoring
Check queue backend health, pending job backlogs, stuck jobs, and failed job counts via CLI:
```bash
php artisan queue:health
# Or JSON formatted for Datadog / Prometheus:
php artisan queue:health --json
```

## 7. Failed Jobs & Poison Job Management
Inspect failed jobs:
```bash
php artisan queue:failed
```
Retry a specific failed job:
```bash
php artisan queue:retry <uuid-or-id>
```
Retry all failed jobs:
```bash
php artisan queue:retry all
```
Flush failed jobs:
```bash
php artisan queue:flush
```
