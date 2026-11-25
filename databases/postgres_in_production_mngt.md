# PostgreSQL + Alembic Production Deployment & Versioning Guide

## 1. Architecture

Production PostgreSQL is hosted on the VPS and is accessible for administration through WireGuard.

```text
Developer PC
   │
   │ WireGuard
   ▼
10.10.0.1:5432
   │
   ▼
Docker PostgreSQL
   │
   ├── parking_db
   ├── payment_db
   └── other application databases
```

Application containers communicate with PostgreSQL through the Docker network.

```text
Application Container
        │
        │ postgres:5432
        ▼
   PostgreSQL Container
```

Do NOT make application containers connect through:

```text
10.10.0.1:5432
```

Use the Docker service/container hostname instead.

---

# 2. Development vs Production

The most important distinction is:

### Development

You can experiment with migrations.

```bash
alembic revision --autogenerate -m "create tenants table"
alembic upgrade head
```

If the schema is still experimental, you may even recreate the development database.

### Production

Production is different.

```text
Migration
    ↓
Review
    ↓
Commit to Git
    ↓
Build application image
    ↓
Backup database
    ↓
Run migration
    ↓
Deploy application
    ↓
Verify
```

Production migrations must never be casually deleted or regenerated.

---

# 3. Recommended project structure

Keep Alembic migrations in Git.

```text
parking-management/
│
├── app/
│   ├── models/
│   ├── core/
│   ├── api/
│   └── ...
│
├── migrations/
│   ├── versions/
│   │   ├── 001_create_tenants.py
│   │   ├── 002_create_users.py
│   │   ├── 003_add_vehicle_table.py
│   │   └── ...
│   │
│   ├── env.py
│   └── script.py.mako
│
├── alembic.ini
├── Dockerfile
├── docker-compose.yml
├── .env
└── requirements.txt
```

The migration files are part of your source code.

Commit them:

```bash
git add migrations/
git commit -m "Add tenants migration"
git push
```

---

# 4. NEVER do this in production

Do NOT run:

```bash
rm -f migrations/versions/*.py
```

Do NOT delete old production migration files.

Do NOT reset Alembic history.

Do NOT recreate the production database simply because the models changed.

Do NOT run:

```bash
alembic revision --autogenerate
```

on the production server as a way of creating production migrations.

The production server should receive migration files that were already created and reviewed during development.

---

# 5. Correct development workflow

Suppose you add a `tenants` model.

## Step 1 — Update SQLAlchemy model

Example:

```python
class Tenant(Base):
    __tablename__ = "tenants"

    id = Column(Integer, primary_key=True)
    name = Column(String(255), nullable=False)
```

## Step 2 — Check SQLAlchemy metadata

Your existing command is useful:

```bash
python -c "from app.core.database import Base; import app.models; print('\n'.join(Base.metadata.tables.keys()))"
```

Make sure the new model appears.

## Step 3 — Generate migration

```bash
alembic revision --autogenerate -m "create tenants table"
```

This generates something like:

```text
migrations/versions/abc123_create_tenants_table.py
```

## Step 4 — REVIEW THE MIGRATION

This is extremely important.

Do not blindly trust Alembic autogenerate.

Open the generated migration:

```python
def upgrade():
    op.create_table(
        "tenants",
        ...
    )
```

Check:

* tables
* columns
* data types
* nullable
* indexes
* foreign keys
* unique constraints
* defaults
* destructive operations

Especially check for:

```python
op.drop_table(...)
op.drop_column(...)
```

These can destroy production data.

## Step 5 — Test locally

```bash
alembic upgrade head
```

Then test the application.

## Step 6 — Commit

```bash
git add migrations/ app/models/
git commit -m "Add tenants table"
git push
```

Now the migration is part of the application release.

---

# 6. Migration versioning

Suppose production currently has:

```text
001_create_tenants
002_create_users
003_create_vehicles
```

Alembic reports:

```text
003
```

Then you add a new feature.

Create:

```text
004_create_parking_sessions
```

Production should move:

```text
003
 ↓
004
```

Later:

```text
005_add_payment_reference
```

Production:

```text
003
 ↓
004
 ↓
005
```

Never replace:

```text
001
002
003
```

with a new migration.

The history is part of the database's lifecycle.

---

# 7. Check current production migration

On production:

```bash
docker exec -it parking-api alembic current
```

or, if Alembic is executed inside another container:

```bash
docker exec -it <app-container> alembic current
```

You can also inspect:

```sql
SELECT * FROM alembic_version;
```

For example:

```text
version_num
------------
004
```

This tells you which migration production currently has.

---

# 8. Check pending migrations

Before deployment:

```bash
alembic current
```

and:

```bash
alembic heads
```

If production is:

```text
003
```

and the repository contains:

```text
004
005
```

then production needs:

```text
003 → 004 → 005
```

---

# 9. Production deployment workflow

A safe deployment should look like:

```text
Developer
   │
   ▼
Change models/code
   │
   ▼
Generate migration locally
   │
   ▼
Review migration
   │
   ▼
Test locally
   │
   ▼
Git commit
   │
   ▼
CI/CD
   │
   ▼
Build Docker image
   │
   ▼
Deploy to VPS
   │
   ▼
Database backup
   │
   ▼
Alembic upgrade
   │
   ▼
Application deployment
   │
   ▼
Health check
   │
   ▼
Done
```

---

# 10. Recommended production deployment order

For normal backward-compatible migrations:

```text
1. Pull/build new application image
2. Backup database
3. Run Alembic migration
4. Start/restart application
5. Health check
6. Verify database
7. Monitor logs
```

Example:

```bash
docker compose pull
```

Then backup:

```bash
docker exec postgres pg_dump \
  -U parking_user \
  -d parking_db \
  -Fc \
  > parking_db_$(date +%Y%m%d_%H%M%S).dump
```

Verify the backup exists:

```bash
ls -lh parking_db_*.dump
```

Then run migrations:

```bash
docker exec parking-api alembic upgrade head
```

Then deploy/restart:

```bash
docker compose up -d
```

Then check:

```bash
docker compose ps
```

and:

```bash
docker logs --tail=100 parking-api
```

---

# 11. IMPORTANT: migration container

For production, a clean approach is to make Alembic available inside the application image.

For example:

```text
parking-api
    ├── FastAPI
    ├── SQLAlchemy
    └── Alembic
```

Then you can run:

```bash
docker exec parking-api alembic upgrade head
```

An even cleaner deployment architecture is a dedicated migration job:

```text
Docker Image
     │
     ├── API container
     │
     └── migration container/job
              │
              ▼
          PostgreSQL
```

The migration job runs:

```bash
alembic upgrade head
```

and exits successfully.

This is especially useful when you later move to Kubernetes.

---

# 12. Docker Compose example

A production Compose architecture can be:

```yaml
services:

  postgres:
    image: postgres:18
    container_name: postgres
    restart: unless-stopped

  parking-api:
    image: your-registry/parking-api:${VERSION}
    container_name: parking-api
    restart: unless-stopped
    depends_on:
      - postgres

  parking-migration:
    image: your-registry/parking-api:${VERSION}
    container_name: parking-migration
    depends_on:
      - postgres
    command: alembic upgrade head
```

However, do not blindly use `depends_on` as a database-readiness guarantee. The migration process should also tolerate/wait for PostgreSQL readiness.

---

# 13. Database backup before migration

For important production migrations:

```bash
docker exec postgres pg_dump \
  -U parking_user \
  -d parking_db \
  -Fc \
  > /opt/backups/parking_db_$(date +%Y%m%d_%H%M%S).dump
```

For the entire PostgreSQL cluster:

```bash
docker exec postgres pg_dumpall \
  -U postgres \
  > /opt/backups/postgres_$(date +%Y%m%d_%H%M%S).sql
```

For production, prefer storing backups somewhere separate from the VPS as well.

For example:

```text
VPS
 │
 ├── PostgreSQL
 │
 └── Local backup
        │
        ▼
    Off-site storage
```

A backup on the same VPS does not protect you from losing the VPS itself.

---

# 14. Test restoration

A backup is not considered reliable simply because `pg_dump` completed successfully.

Periodically test:

```text
Backup
  ↓
Restore
  ↓
Temporary PostgreSQL
  ↓
Run application tests
```

Example:

```bash
createdb parking_restore_test
pg_restore \
  -U postgres \
  -d parking_restore_test \
  parking_backup.dump
```

Then verify:

```sql
SELECT count(*) FROM vehicles;
SELECT count(*) FROM parking_sessions;
```

---

# 15. Dangerous migrations

Be especially careful with:

```sql
DROP TABLE
DROP COLUMN
ALTER COLUMN TYPE
```

and large data migrations.

For example:

```python
op.drop_column("users", "old_phone")
```

can permanently remove data.

A safer production strategy is often:

### Release 1

Add new column:

```text
old_phone
new_phone
```

Application supports both.

### Data migration

Copy old data:

```text
old_phone → new_phone
```

### Release 2

Application uses `new_phone`.

### Later release

Remove `old_phone`.

This is called an **expand-and-contract migration**.

It is much safer for zero/low-downtime production deployments.

---

# 16. Application versioning

Do not rely only on:

```text
latest
```

for production.

Prefer explicit versions:

```text
parking-api:1.0.0
parking-api:1.1.0
parking-api:1.2.0
```

or Git SHA tags:

```text
parking-api:7f82a91
```

A very good approach is to use both:

```text
parking-api:1.4.0
parking-api:7f82a91
```

where:

```text
1.4.0 = human-readable release
7f82a91 = exact Git commit
```

---

# 17. Semantic versioning

Use:

```text
MAJOR.MINOR.PATCH
```

Example:

```text
1.4.2
```

### MAJOR

Breaking changes:

```text
1.x.x → 2.0.0
```

### MINOR

New backward-compatible functionality:

```text
1.4.0 → 1.5.0
```

### PATCH

Bug/security fixes:

```text
1.4.1 → 1.4.2
```

---

# 18. Recommended Git workflow

For your projects:

```text
main
 │
 ├── feature/tenant-management
 ├── feature/payment-report
 └── fix/vehicle-validation
```

Development:

```bash
git checkout -b feature/tenant-management
```

Develop and create migration:

```bash
alembic revision --autogenerate -m "create tenants table"
```

Test:

```bash
alembic upgrade head
```

Commit:

```bash
git add .
git commit -m "feat: add tenant management"
```

Push:

```bash
git push origin feature/tenant-management
```

After review/merge:

```text
feature branch
      ↓
    main
      ↓
CI/CD
      ↓
Docker image
      ↓
Production
```

---

# 19. Recommended release structure

Example:

```text
Release 1.3.0

Application:
parking-api:1.3.0

Git:
commit abc1234

Database:
Alembic 008 → 009

Migration:
009_add_parking_payment_reference.py
```

Your deployment record becomes:

```text
Application: 1.3.0
Git commit: abc1234
Database migration: 009
Deployed: 2026-09-30
```

This gives you traceability.

---

# 20. Rollback

Do NOT assume:

```bash
alembic downgrade -1
```

is always a safe production rollback.

For example, if migration 010 deletes data, downgrading may not recover that data.

A safer production rollback strategy is:

```text
Application
    ↓
Rollback Docker image
    ↓
Database
    ↓
Usually keep schema forward-compatible
```

For destructive database changes, use the database backup if a true database rollback is required.

---

# 21. Production deployment checklist

Before deployment:

```text
[ ] Code reviewed
[ ] Migration generated
[ ] Migration reviewed manually
[ ] Migration tested locally
[ ] Docker image built
[ ] Version tag created
[ ] Database backup completed
[ ] Backup verified
```

During deployment:

```text
[ ] Database reachable
[ ] Current Alembic version checked
[ ] Migration applied
[ ] Application deployed
[ ] Health endpoint checked
[ ] Logs checked
```

After deployment:

```text
[ ] Database schema verified
[ ] API tested
[ ] Application logs normal
[ ] Error rate normal
[ ] Monitoring normal
[ ] Migration version recorded
```

---

# 22. Your current local commands

Your current workflow:

```bash
python -c "from app.core.database import Base; import app.models; print('\n'.join(Base.metadata.tables.keys()))"

rm -f migrations/versions/*.py

alembic revision --autogenerate -m "create tenants table"

alembic upgrade head

docker exec -it parking-postgres psql -U parking_user -d parking_db
```

is appropriate for **an experimental/local database**.

The production equivalent should be:

```bash
# 1. Check migration
alembic current

# 2. Review migration files
git diff

# 3. Build/version application
docker build -t parking-api:1.2.0 .

# 4. Backup production
docker exec postgres pg_dump \
  -U parking_user \
  -d parking_db \
  -Fc \
  > /opt/backups/parking_db_$(date +%Y%m%d_%H%M%S).dump

# 5. Deploy migration
docker exec parking-api alembic upgrade head

# 6. Deploy application
docker compose up -d

# 7. Verify
docker exec parking-api alembic current

# 8. Check application
docker compose ps
docker logs --tail=100 parking-api
```

---

# 23. Golden rule

### Development

```text
Models
  ↓
Autogenerate
  ↓
Review
  ↓
Test
```

### Production

```text
Git
  ↓
Reviewed migration
  ↓
Versioned Docker image
  ↓
Backup
  ↓
alembic upgrade head
  ↓
Deploy
  ↓
Verify
```

**Never do this in production:**

```text
❌ Delete migrations
❌ Reset migration history
❌ Recreate production database
❌ Generate random migrations directly on VPS
❌ Use latest as the only production version
❌ Deploy without backup
❌ Assume backup = tested restore
❌ Use postgres superuser for application access
```

The goal is that at any moment you can answer:

> **Which application version is running, which Git commit produced it, and which database migration version is installed?**

That is the level of traceability you want for a production system.
