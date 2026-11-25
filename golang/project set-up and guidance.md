# Professional Go Project Engineering & Maintenance Guide

## 1. Purpose

This document defines the recommended standards for developing, testing, monitoring, securing, containerizing, deploying, and maintaining a production-grade Go application.

The goal is to ensure that the project remains:

* Maintainable
* Testable
* Observable
* Secure
* Performant
* Scalable
* Easy to deploy
* Easy for another developer to understand

The principles in this document should be applied consistently throughout the project lifecycle.

---

# 2. Recommended Project Structure

A production Go application should separate application entry points, business logic, infrastructure, and shared functionality.

```text
payment-orchestrator/
│
├── cmd/
│   ├── api/
│   │   └── main.go
│   │
│   └── payment-events/
│       └── main.go
│
├── internal/
│   ├── config/
│   ├── database/
│   ├── http/
│   │   ├── middleware/
│   │   ├── router.go
│   │   ├── response.go
│   │   └── health.go
│   │
│   ├── auth/
│   ├── admin/
│   ├── client/
│   ├── payment/
│   ├── provider/
│   │   └── pesapal/
│   │
│   ├── webhook/
│   ├── idempotency/
│   ├── ratelimit/
│   ├── redis/
│   ├── kafka/
│   ├── outbox/
│   ├── events/
│   └── metrics/
│
├── migrations/
│
├── k6/
│
├── configs/
│   └── .env.example
│
├── .github/
│   └── workflows/
│
├── Dockerfile
├── docker-compose.yml
├── .dockerignore
├── go.mod
├── go.sum
└── README.md
```

### Principle

Keep `cmd/` small.

`main.go` should primarily:

1. Load configuration
2. Initialize infrastructure
3. Initialize services
4. Initialize routes
5. Start the application
6. Handle graceful shutdown

Business logic should not live inside `main.go`.

---

# 3. Go Version

Always explicitly define the Go version in `go.mod`.

Example:

```go
module payment-orchestrator

go 1.26
```

The development machine, CI, and Docker builder should use the same Go major/minor version.

Check:

```bash
go version
```

Check dependencies:

```bash
go mod tidy
```

Do not randomly upgrade dependencies in production.

Dependency upgrades should be deliberate and tested.

---

# 4. Dependency Management

Add a dependency with:

```bash
go get package/path
```

Then:

```bash
go mod tidy
```

Run:

```bash
go test ./...
```

Before committing dependency changes.

Important files:

```text
go.mod
go.sum
```

Never manually edit `go.sum`.

---

# 5. Formatting

Go code should always be formatted with `gofmt`.

Check:

```bash
gofmt -l .
```

If files are returned:

```bash
gofmt -w .
```

Recommended CI rule:

```bash
test -z "$(gofmt -l .)"
```

Formatting should fail CI if code is not formatted.

---

# 6. Static Analysis

Run:

```bash
go vet ./...
```

`go vet` detects suspicious code patterns that may compile successfully but indicate possible bugs.

Recommended local validation:

```bash
gofmt -w .
go vet ./...
go test ./...
```

---

# 7. Testing Strategy

Use multiple testing layers.

```text
                 ┌─────────────────────┐
                 │      k6 / API       │
                 │   integration/load  │
                 └──────────▲──────────┘
                            │
                 ┌──────────┴──────────┐
                 │ Integration Tests   │
                 └──────────▲──────────┘
                            │
                 ┌──────────┴──────────┐
                 │    Unit Tests       │
                 └─────────────────────┘
```

Run all Go tests:

```bash
go test ./...
```

Run with race detection:

```bash
go test -race ./...
```

Run coverage:

```bash
go test -cover ./...
```

For important packages:

```bash
go test -coverprofile=coverage.out ./...
```

View coverage:

```bash
go tool cover -html=coverage.out
```

---

# 8. API Testing With k6

k6 should test the application from the HTTP/API perspective.

Example:

```bash
k6 run k6/health.js
```

Typical tests:

```text
k6/
├── health.js
├── authentication.js
├── payments.js
├── idempotency.js
└── rate-limit.js
```

Use k6 for:

* API smoke testing
* Authentication testing
* Payment API testing
* Idempotency testing
* Rate-limit testing
* Load testing
* Performance regression testing

Do not perform large payment load tests against a real payment provider.

Use a mock provider for high-volume tests.

---

# 9. Idempotency Testing

Payment APIs must explicitly test idempotency.

Example scenarios:

### Request 1

```text
Idempotency-Key: abc123
```

Result:

```text
201 Created
```

### Request 2

Same key and same request:

```text
Idempotency-Key: abc123
```

Expected:

```text
Replay previous response
```

### Request 3

Same key but different payment:

```text
Idempotency-Key: abc123
```

Expected:

```text
409 Conflict
```

This protects the payment system from duplicate transactions.

---

# 10. Database Standards

PostgreSQL should remain the source of truth for transactional data.

Use transactions when multiple database operations must succeed together.

Example:

```text
Payment update
      +
Status history
      +
Webhook delivery
      +
Outbox event
```

These should be committed atomically where required.

Never rely on application memory as the source of truth.

---

# 11. Database Connection Pool

The connection pool must be configured deliberately.

Example:

```text
MaxConns: 50
MinConns: 5
MaxConnLifetime: 30m
MaxConnIdleTime: 5m
```

Monitor:

```text
Active connections
Idle connections
Waiting connections
Connection errors
Query latency
```

A database connection pool is shared by application requests.

If the application needs 100 concurrent database operations but the pool allows only 50 connections, the remaining operations wait.

Increasing the pool indefinitely is not a solution.

The database itself has a connection limit.

---

# 12. Redis

Use Redis for high-speed, temporary, or frequently accessed data.

Good examples:

```text
Rate limiting
Caching
Temporary locks
Short-lived tokens
Counters
Session-related temporary state
```

Do not automatically move all PostgreSQL data into Redis.

PostgreSQL:

```text
Source of truth
```

Redis:

```text
High-speed supporting layer
```

Always define TTL for temporary data where appropriate.

---

# 13. Kafka

Kafka should be used for asynchronous event processing.

Example:

```text
Payment completed
       │
       ▼
PostgreSQL
       │
       ▼
Outbox
       │
       ▼
Kafka
       │
       ├── Notification service
       ├── Analytics
       ├── SMS
       ├── Reporting
       └── Other consumers
```

Do not use Kafka as a replacement for PostgreSQL transactional storage.

Kafka is an event streaming platform.

PostgreSQL remains the transactional source of truth.

---

# 14. Outbox Pattern

For important business events:

```text
Database transaction
        │
        ├── Update payment
        ├── Insert history
        └── Insert outbox event
```

All succeed or fail together.

Then:

```text
Outbox Worker
      │
      ▼
Kafka
```

This prevents the dangerous situation where:

```text
Payment saved successfully
BUT
Kafka event failed
```

The outbox provides reliable asynchronous delivery.

Delivery should be treated as **at-least-once**.

Therefore consumers must be idempotent.

---

# 15. Kafka Consumer Idempotency

Use a processed-events table or equivalent mechanism.

Example:

```text
consumer_name
event_id
processed_at
```

Unique constraint:

```text
UNIQUE(consumer_name, event_id)
```

This allows:

```text
Consumer A → Event 123 → processed
Consumer A → Event 123 → ignored

Consumer B → Event 123 → processed
```

Different consumers can process the same event independently.

---

# 16. Observability

Every production service should provide:

```text
Logs
Metrics
Health checks
Tracing (when required)
```

Recommended stack:

```text
Application
    │
    ├── Prometheus → Metrics
    │
    ├── Grafana → Dashboards
    │
    └── ELK → Logs
```

---

# 17. Prometheus Metrics

Expose:

```text
GET /metrics
```

Example metrics:

```text
payment_http_requests_total
payment_http_request_duration_seconds
payment_http_active_requests

payment_requests_total
payment_status_transitions_total

payment_outbox_events_total

payment_kafka_events_consumed_total

payment_idempotency_requests_total

payment_rate_limit_exceeded_total
```

Prometheus automatically provides Go runtime metrics such as:

```text
go_goroutines
go_memstats_*
process_cpu_seconds_total
process_resident_memory_bytes
```

---

# 18. Prometheus Label Rules

Avoid high-cardinality labels.

Do NOT use:

```text
payment_id
transaction_id
customer_id
email
phone
UUID
```

as Prometheus labels.

Bad:

```text
payment_requests_total{
    payment_id="PAY-123456"
}
```

Good:

```text
payment_requests_total{
    operation="create",
    result="success"
}
```

Good labels are usually small, predictable sets.

---

# 19. Grafana Dashboard

Create dashboards for:

### Application

```text
Request rate
Error rate
Latency
Active requests
HTTP status codes
```

### Payments

```text
Payment attempts
Successful payments
Failed payments
Pending payments
Reversed payments
```

### Provider

```text
Provider request rate
Provider latency
Provider errors
```

### Infrastructure

```text
CPU
Memory
Disk
Network
Go goroutines
```

### PostgreSQL

```text
Connections
Query latency
Connection pool usage
```

### Redis

```text
Commands
Errors
Memory
Hit/miss ratio
```

### Kafka

```text
Events produced
Events consumed
Consumer errors
Consumer lag
```

### Outbox

```text
Pending events
Published events
Failed events
Retry count
```

---

# 20. Logging

Use structured logs.

Prefer:

```json
{
  "level": "error",
  "event": "payment_provider_request_failed",
  "payment_ref": "PAY-123",
  "provider": "pesapal",
  "error": "timeout"
}
```

over:

```text
Payment failed because something went wrong.
```

Never log:

```text
API keys
Passwords
JWT tokens
Card information
Customer secrets
Provider credentials
```

---

# 21. Authentication

For admin authentication:

```text
Access Token
+
Refresh Token
```

Access tokens should have a relatively short lifetime.

Refresh tokens should be:

* stored securely
* revocable
* hashed in the database where appropriate

For client authentication:

```text
X-API-Key
```

Store only the hash in the database.

Return the raw API key only when:

```text
Creating
or
Rotating
```

a key.

---

# 22. Rate Limiting

Protect expensive endpoints.

Examples:

```text
POST /payments
GET /payments
Admin authentication
Webhook endpoints
```

Redis is appropriate for distributed rate limiting.

Example:

```text
Payment creation
60 requests/minute/client
```

Rate limits should be monitored with Prometheus.

---

# 23. HTTP Security

Production API should use:

```text
HTTPS
```

Behind:

```text
Nginx
```

or an equivalent load balancer/reverse proxy.

Only expose the public API.

Do not publicly expose:

```text
PostgreSQL
Redis
Kafka
```

Example:

```text
Internet
   │
   ▼
Nginx :443
   │
   ▼
Go API :8080
   │
   ├── PostgreSQL
   ├── Redis
   └── Kafka
```

---

# 24. Configuration

Never hardcode secrets.

Use environment variables:

```text
DATABASE_URL
REDIS_URL
KAFKA_BROKERS

PESAPAL_CONSUMER_KEY
PESAPAL_CONSUMER_SECRET

JWT_SECRET
```

Commit:

```text
.env.example
```

Never commit:

```text
.env
```

or real credentials.

---

# 25. Docker

Use multi-stage builds.

```text
Go source
    │
    ▼
Go builder image
    │
    ▼
Compiled binary
    │
    ▼
Small runtime image
```

Benefits:

* Smaller image
* Faster deployment
* Smaller attack surface
* No Go compiler in production

Build locally:

```bash
docker build -t payment-orchestrator:local .
```

Run:

```bash
docker compose up -d
```

Check:

```bash
docker compose ps
```

Logs:

```bash
docker compose logs -f payment-api
```

---

# 26. Docker Image Versioning

Avoid depending only on:

```text
latest
```

Use immutable tags.

Example:

```text
0788644687/payment-orchestrator:latest
0788644687/payment-orchestrator:a8c92d1
```

The Git commit SHA is an excellent production image tag.

This makes it possible to know exactly which source code produced a running container.

---

# 27. CI/CD Pipeline

Recommended pipeline:

```text
Git Push
   │
   ▼
Checkout
   │
   ▼
gofmt
   │
   ▼
go vet
   │
   ▼
go test
   │
   ▼
Docker Build
   │
   ▼
Start Test Environment
   │
   ▼
k6 API Tests
   │
   ▼
SonarQube
   │
   ▼
Quality Gate
   │
   ▼
Push Docker Image
   │
   ▼
Deploy
```

A failed step should stop the pipeline.

---

# 28. GitHub Actions

The pipeline should eventually perform:

```bash
gofmt -l .
go vet ./...
go test ./...
```

Then build the Docker image.

Run k6 against the test environment.

Only after all tests pass:

```text
Docker image → Docker Hub
```

Never push an image before the required tests pass.

---

# 29. SonarQube

SonarQube should be used for code quality and static analysis.

Monitor:

```text
Bugs
Vulnerabilities
Code smells
Duplicated code
Coverage
Maintainability
Security
```

Recommended rule:

```text
Quality Gate = PASS
```

before production deployment.

---

# 30. Health Checks

Provide at least:

```text
/health
/ready
/metrics
```

### `/health`

Indicates that the process is alive.

### `/ready`

Indicates whether the application is ready to serve traffic.

For example:

```text
Database
Redis
Kafka
```

can be checked according to the service's requirements.

Do not make `/health` unnecessarily dependent on every external service.

---

# 31. Graceful Shutdown

The application should handle:

```text
SIGTERM
SIGINT
```

before exiting.

Shutdown sequence should approximately be:

```text
Stop accepting new requests
        │
        ▼
Finish active requests
        │
        ▼
Stop background workers
        │
        ▼
Close Kafka producer
        │
        ▼
Close Redis
        │
        ▼
Close PostgreSQL
        │
        ▼
Exit
```

This is particularly important in Docker and Kubernetes.

---

# 32. Error Handling

Do not expose internal errors directly to clients.

Bad:

```json
{
  "error": "pq: password authentication failed"
}
```

Better:

```json
{
  "success": false,
  "message": "Internal server error"
}
```

Log the real error internally.

---

# 33. API Response Consistency

Keep one response structure throughout the API.

Example:

```json
{
  "success": true,
  "data": {},
  "message": "Payment created successfully"
}
```

Errors:

```json
{
  "success": false,
  "message": "Invalid payment request"
}
```

Consistency makes client integration easier.

---

# 34. Database Migrations

Never manually modify production databases without recording the change.

Use migrations:

```text
001_initial.sql
002_auth.sql
003_outbox_events.sql
004_processed_events.sql
```

Every schema change should have a migration.

Migration files should be version controlled.

---

# 35. Backups

Production PostgreSQL requires:

```text
Automated backups
+
Remote backup storage
+
Retention
+
Restore testing
```

A backup that has never been restored is not fully trusted.

Recommended strategy:

```text
Database
   │
   ├── Frequent backup
   │
   ├── Remote copy
   │
   └── Periodic restore test
```

---

# 36. Performance Engineering

Do not optimize based on assumptions.

Measure first.

Monitor:

```text
Request latency
Database latency
Redis latency
Kafka latency
Provider latency
CPU
Memory
Connection pools
```

Typical bottleneck flow:

```text
API
 │
 ▼
Database
 │
 ▼
Connection Pool
 │
 ▼
Query
```

If database connections are exhausted, increasing API instances alone may make the problem worse.

---

# 37. Load Testing

Use k6 for controlled load testing.

Example:

```text
10 VUs
30 seconds
```

Then:

```text
50 VUs
1 minute
```

Then gradually increase.

Monitor:

```text
p50
p90
p95
p99
error rate
requests/sec
CPU
memory
database connections
```

Do not jump directly to extreme traffic.

---

# 38. Production Deployment

Recommended:

```text
Developer
   │
   ▼
GitHub
   │
   ▼
GitHub Actions
   │
   ▼
Docker Hub
   │
   ▼
Production Server
   │
   ▼
Docker Compose / Kubernetes
```

Production should pull a specific image version.

Example:

```bash
docker compose pull
docker compose up -d
```

Verify:

```bash
docker compose ps
docker compose logs -f payment-api
```

Then verify:

```text
/health
/ready
/metrics
```

---

# 39. Deployment Safety

Before deployment:

```text
✓ Tests pass
✓ Docker image builds
✓ k6 tests pass
✓ SonarQube passes
✓ Database migration reviewed
✓ Backup verified
✓ Environment variables verified
```

After deployment:

```text
✓ Health check
✓ Ready check
✓ API test
✓ Metrics available
✓ Kafka consumer healthy
✓ Outbox not accumulating
✓ Error rate normal
```

---

# 40. Rollback

Always know how to return to the previous version.

Example:

```text
Current:
0788644687/payment-orchestrator:a8c92d1

Previous:
0788644687/payment-orchestrator:91f2e11
```

If the new version fails:

```bash
docker compose pull
docker compose up -d
```

using the previous image tag.

Never deploy without knowing the rollback procedure.

---

# 41. Git Commit Standards

Use meaningful commits.

Good:

```text
feat: add payment idempotency
feat: add prometheus metrics
fix: handle pesapal timeout
perf: optimize payment query
refactor: separate provider service
test: add payment status tests
docs: update deployment guide
chore: upgrade dependencies
```

Avoid:

```text
update
changes
fix
new
test
```

---

# 42. Pull Request Checklist

Before merging:

```text
[ ] Code formatted
[ ] go vet passes
[ ] Unit tests pass
[ ] Integration tests pass
[ ] k6 smoke tests pass
[ ] No secrets committed
[ ] Database migration reviewed
[ ] Logging reviewed
[ ] Metrics added where necessary
[ ] Error handling reviewed
[ ] Documentation updated
```

---

# 43. Professional Development Workflow

Recommended daily workflow:

```bash
git pull

go mod tidy

gofmt -w .

go vet ./...

go test ./...

git status
```

Then run the application locally.

Test:

```text
Health
Authentication
Payment
Idempotency
Webhook
Kafka
Outbox
Metrics
```

Before pushing:

```bash
go test ./...
```

---

# 44. Production Troubleshooting Workflow

When an incident occurs, do not immediately change code.

Follow:

```text
1. Identify symptom
        ↓
2. Check Grafana
        ↓
3. Check Prometheus
        ↓
4. Check application logs
        ↓
5. Check database
        ↓
6. Check Redis
        ↓
7. Check Kafka
        ↓
8. Identify bottleneck
        ↓
9. Apply controlled fix
        ↓
10. Verify metrics
```

Use evidence instead of assumptions.

---

# 45. Golden Rules

## Rule 1

PostgreSQL is the transactional source of truth.

## Rule 2

Redis is a supporting high-speed layer, not the primary database.

## Rule 3

Kafka transports events; it does not replace transactional storage.

## Rule 4

Important database changes and outbox events should be atomic.

## Rule 5

Kafka consumers must be idempotent.

## Rule 6

Never expose secrets in logs or Git.

## Rule 7

Never use high-cardinality identifiers as Prometheus labels.

## Rule 8

Measure performance before optimizing.

## Rule 9

Every production deployment must have a rollback strategy.

## Rule 10

A backup is only trusted after restore testing.

## Rule 11

Production configuration must be separated from source code.

## Rule 12

Do not change working business logic during infrastructure refactoring without a specific reason.

---

# 46. Recommended Final Architecture

```text
                         INTERNET
                            │
                            ▼
                     ┌────────────┐
                     │    Nginx   │
                     │ HTTPS/TLS  │
                     └─────┬──────┘
                           │
                           ▼
                  ┌──────────────────┐
                  │   Go API         │
                  │                  │
                  │ Auth             │
                  │ Payments         │
                  │ Rate Limiting    │
                  │ Idempotency      │
                  │ Metrics          │
                  └────┬─────┬───────┘
                       │     │
             ┌─────────┘     └──────────┐
             ▼                          ▼
       ┌──────────┐                ┌──────────┐
       │PostgreSQL│                │  Redis   │
       │          │                │          │
       │Payments  │                │RateLimit │
       │Outbox    │                │Cache     │
       └─────┬────┘                └──────────┘
             │
             ▼
       ┌──────────┐
       │  Outbox  │
       │  Worker  │
       └─────┬────┘
             │
             ▼
       ┌──────────┐
       │  Kafka   │
       │          │
       │ Events   │
       └────┬─────┘
            │
       ┌────┴───────────────┐
       ▼                    ▼
Payment Events       Future Services
Consumer             SMS / Analytics /
                     Notifications

Observability:

       Go API
         │
         ├──────────► Prometheus ─────► Grafana
         │
         └──────────► Structured Logs ─► ELK
```

---

# 47. Final Pre-Production Checklist

Before declaring the application production-ready:

### Code

```text
[ ] gofmt
[ ] go vet
[ ] Unit tests
[ ] Integration tests
[ ] Race tests
```

### API

```text
[ ] Authentication
[ ] Authorization
[ ] Rate limiting
[ ] Idempotency
[ ] Validation
[ ] Error handling
```

### Database

```text
[ ] Migrations
[ ] Indexes
[ ] Connection pool
[ ] Backup
[ ] Restore test
```

### Redis

```text
[ ] Authentication/security
[ ] Persistence strategy where required
[ ] TTL
[ ] Monitoring
```

### Kafka

```text
[ ] Topics
[ ] Consumer groups
[ ] Retry strategy
[ ] Consumer idempotency
[ ] Monitoring
```

### Observability

```text
[ ] /health
[ ] /ready
[ ] /metrics
[ ] Prometheus
[ ] Grafana
[ ] Logs
[ ] Alerts
```

### CI/CD

```text
[ ] GitHub Actions
[ ] Tests
[ ] Docker build
[ ] k6
[ ] SonarQube
[ ] Docker Hub
```

### Production

```text
[ ] HTTPS
[ ] Secrets
[ ] Firewall
[ ] Backups
[ ] Monitoring
[ ] Rollback procedure
```

---

# 48. Engineering Principle

The most important principle is:

> Build a system that is easy to understand, easy to observe, easy to test, and safe to change.

A professional system is not simply one that works.

It should also allow the engineering team to answer:

```text
Is it working?
Why is it slow?
Where is the bottleneck?
What failed?
When did it fail?
How many users are affected?
Can we safely deploy a new version?
Can we roll back?
Can we recover the data?
```

That is the difference between an application that merely runs and an application that can be professionally operated in production.
