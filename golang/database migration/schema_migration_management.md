# Windows Migration Troubleshooting — Central SMS Platform

## 1. Problem

The `golang-migrate` CLI may fail to locate migration files on Windows, especially when the project path contains spaces.

Example errors:

```text
invalid character "\\" in host name
The filename, directory name, or volume label syntax is incorrect
URL cannot be empty
```

## 2. Configure the database connection

Open PowerShell in the project directory:

```powershell
cd "C:\Users\Dev Jean\GO\central-sms-platform"

$env:DATABASE_URL = "postgres://sms_app:<PASSWORD>@localhost:5432/central_sms?sslmode=disable"
```

Verify connectivity:

```powershell
psql "$env:DATABASE_URL" -c "SELECT current_database();"
```

Expected database: `central_sms`.

**Security:** Use your actual database password locally. Never commit credentials to Git.

## 3. Fix the Windows path

Create a directory junction to avoid the space in the original path:

```powershell
New-Item -ItemType Junction `
  -Path "C:\central-sms-platform" `
  -Target "C:\Users\Dev Jean\GO\central-sms-platform"
```

Verify the migration file is accessible:

```powershell
Test-Path "C:\central-sms-platform\migrations\000001_initial_schema.up.sql"
```

Expected output: `True`.

If the junction already exists, do not create it again.

## 4. Run migrations

Check the current version:

```powershell
migrate -path "C:/central-sms-platform/migrations" `
  -database "$env:DATABASE_URL" version
```

Apply pending migrations:

```powershell
migrate -path "C:/central-sms-platform/migrations" `
  -database "$env:DATABASE_URL" up
```

Verify the database:

```powershell
psql "$env:DATABASE_URL" -c "\dt"
```

## 5. Adding a new schema

Never modify an already-applied migration to introduce new tables or columns. Create a new migration instead:

```powershell
migrate create -ext sql -dir migrations -seq add_new_schema
```

This generates two files:

- `000002_add_new_schema.up.sql` — applies the changes.
- `000002_add_new_schema.down.sql` — reverses the changes.

The actual number depends on your existing migration history.

Write the SQL, review it, and apply it using the migration command above.

## 6. Important precautions

- Reconfigure `$env:DATABASE_URL` when opening a new PowerShell session.
- Back up production databases before applying schema changes.
- Never use `migrate drop` to troubleshoot path errors.
- Never use `migrate force` unless you have diagnosed and intentionally corrected migration version state.
- Keep passwords and other secrets out of source control.

**Goal:** Maintain a repeatable, version-controlled database migration process for the Central SMS Platform.