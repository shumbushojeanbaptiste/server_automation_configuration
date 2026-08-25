# Documentation: Running FastAPI in WSL with PostgreSQL and Redis on Windows

This guide documents the setup we used for the **Parking Backend** so you can repeat it in future projects without Docker.

## Architecture

```text
┌─────────────────────────────────────┐
│              WINDOWS                │
│                                     │
│  PostgreSQL ─────── Port 5432       │
│  Redis ──────────── Port 6379       │
│                                     │
└───────────────┬─────────────────────┘
                │
                │ WSL2 Virtual Network
                │
┌───────────────▼─────────────────────┐
│                WSL                  │
│                                     │
│  Ubuntu                            │
│    └── Python venv                  │
│         └── FastAPI                 │
│              ├── PostgreSQL         │
│              └── Redis              │
│                                     │
└─────────────────────────────────────┘
```

---

# 1. Goal

Instead of running everything with Docker Compose:

```text
Docker
├── FastAPI
├── PostgreSQL
└── Redis
```

run:

```text
Windows
├── PostgreSQL
└── Redis

WSL
└── FastAPI
```

### Benefits

* Less RAM usage
* Less CPU usage
* Faster laptop development
* No Docker containers required for local development
* FastAPI still runs in a Linux environment

---

# 2. Install and Start Services on Windows

Install:

* PostgreSQL
* Redis

Verify Redis in Windows PowerShell:

```powershell
redis-cli -h 127.0.0.1 -p 6379 ping
```

Expected:

```text
PONG
```

Verify PostgreSQL:

```powershell
pg_isready -h 127.0.0.1 -p 5432 -U postgres
```

Expected:

```text
127.0.0.1:5432 - accepting connections
```

---

# 3. Find the Windows Host IP from WSL

Open WSL and run:

```bash
ip route | grep default
```

Example:

```text
default via 172.27.240.1 dev eth0
```

The important part is:

```text
172.27.240.1
```

Use it as the Windows host gateway:

```bash
WINDOWS_HOST=$(ip route | awk '/default/ {print $3}')
echo $WINDOWS_HOST
```

Example output:

```text
172.27.240.1
```

> ⚠️ The IP may change after restarting WSL or Windows. Always check it again if connectivity stops working.

---

# 4. Install PostgreSQL Client in WSL

WSL may not have `psql` and `pg_isready`.

Install the PostgreSQL client:

```bash
sudo apt update
sudo apt install -y postgresql-client
```

Verify:

```bash
psql --version
```

```bash
pg_isready --version
```

---

# 5. Install Redis Client in WSL

If necessary:

```bash
sudo apt update
sudo apt install -y redis-tools
```

Verify:

```bash
redis-cli --version
```

---

# 6. Test PostgreSQL from WSL

Get the Windows host:

```bash
WINDOWS_HOST=$(ip route | awk '/default/ {print $3}')
```

Check PostgreSQL:

```bash
pg_isready -h "$WINDOWS_HOST" -p 5432 -U postgres
```

Expected:

```text
172.27.240.1:5432 - accepting connections
```

Test an actual query:

```bash
psql -h "$WINDOWS_HOST" -p 5432 -U postgres -d parking_db -c "SELECT 1;"
```

Expected:

```text
 ?column?
----------
        1
(1 row)
```

---

# 7. Test Redis from WSL

```bash
WINDOWS_HOST=$(ip route | awk '/default/ {print $3}')
redis-cli -h "$WINDOWS_HOST" -p 6379 ping
```

Expected:

```text
PONG
```

If the command hangs, stop it with:

```bash
Ctrl+C
```

Then continue to the troubleshooting section.

---

# 8. Check Windows Ports

Open Windows PowerShell:

```powershell
netstat -ano | findstr :6379
```

For Redis.

And:

```powershell
netstat -ano | findstr :5432
```

For PostgreSQL.

Expected examples:

```text
TCP    0.0.0.0:5432    0.0.0.0:0    LISTENING
```

or:

```text
TCP    127.0.0.1:6379  0.0.0.0:0    LISTENING
```

To identify which process owns a port:

```powershell
Get-NetTCPConnection -LocalPort 6379 |
Format-Table LocalAddress,LocalPort,RemoteAddress,RemotePort,State,OwningProcess
```

Then:

```powershell
tasklist /FI "PID eq <PID>"
```

Example:

```powershell
tasklist /FI "PID eq 4992"
```

---

# 9. Important Docker Conflict

During our setup, Docker Desktop was still running.

We discovered this:

```text
PID 6224
com.docker.backend.exe
```

Docker Desktop was listening on port `6379` through the WSL-facing interface.

The native Redis process was:

```text
redis-server.exe
```

This created a conflict:

```text
WSL
 │
 ├── Redis request
 │
 ▼
172.27.240.1:6379
 │
 ▼
Docker Desktop ❌
 │
 └── Native Redis was not reached correctly
```

Check which process owns the port:

```powershell
Get-CimInstance Win32_Process -Filter "ProcessId = <PID>" |
Select-Object ProcessId,CommandLine
```

If the output contains:

```text
com.docker.backend.exe
```

Docker may be intercepting the port.

## Solution

Quit Docker Desktop completely if you don't need it.

Then optionally run:

```powershell
wsl --shutdown
```

Restart WSL and test again.

> ⚠️ Do not delete Docker volumes until you have migrated any database data you need.

Avoid:

```bash
docker compose down -v
```

unless you intentionally want to delete Docker volumes.

---

# 10. Windows Firewall

If the port is listening but WSL cannot connect, test from WSL:

```bash
nc -vz "$WINDOWS_HOST" 5432
```

```bash
nc -vz "$WINDOWS_HOST" 6379
```

Install `nc` if needed:

```bash
sudo apt install -y netcat-openbsd
```

If Windows Firewall blocks access, open PowerShell as Administrator.

Allow PostgreSQL:

```powershell
New-NetFirewallRule `
  -DisplayName "PostgreSQL WSL2" `
  -Direction Inbound `
  -Protocol TCP `
  -LocalPort 5432 `
  -Action Allow
```

Allow Redis:

```powershell
New-NetFirewallRule `
  -DisplayName "Redis WSL2" `
  -Direction Inbound `
  -Protocol TCP `
  -LocalPort 6379 `
  -Action Allow
```

For development only, turning off the firewall may help diagnose the problem, but it is **not recommended as the permanent solution**.

---

# 11. FastAPI Environment Configuration

Do not use:

```env
POSTGRES_HOST=localhost
REDIS_HOST=localhost
```

inside WSL if the services are running on Windows and localhost forwarding is not working.

Instead:

```env
POSTGRES_HOST=172.27.240.1
POSTGRES_PORT=5432

REDIS_HOST=172.27.240.1
REDIS_PORT=6379
```

Example full configuration:

```env
DATABASE_URL=postgresql+asyncpg://postgres:YOUR_PASSWORD@172.27.240.1:5432/parking_db

REDIS_HOST=172.27.240.1
REDIS_PORT=6379
REDIS_DB=0
```

Or Redis URL:

```env
REDIS_URL=redis://172.27.240.1:6379/0
```

> The WSL gateway IP can change. Avoid assuming that the same IP will always be used.

---

# 12. Recommended Dynamic Configuration

Before starting FastAPI, get the current Windows host:

```bash
WINDOWS_HOST=$(ip route | awk '/default/ {print $3}')
```

Check it:

```bash
echo $WINDOWS_HOST
```

Then export variables:

```bash
export POSTGRES_HOST="$WINDOWS_HOST"
export REDIS_HOST="$WINDOWS_HOST"
```

Start FastAPI:

```bash
uvicorn app.main:app --reload
```

For your project, you can create:

```bash
run-dev.sh
```

with:

```bash
#!/bin/bash

WINDOWS_HOST=$(ip route | awk '/default/ {print $3}')

export POSTGRES_HOST="$WINDOWS_HOST"
export REDIS_HOST="$WINDOWS_HOST"

echo "Windows Host: $WINDOWS_HOST"
echo "PostgreSQL: $POSTGRES_HOST:5432"
echo "Redis: $REDIS_HOST:6379"

uvicorn app.main:app --reload
```

Make executable:

```bash
chmod +x run-dev.sh
```

Run:

```bash
./run-dev.sh
```

---

# 13. Migrate an Existing Docker PostgreSQL Database

If your old database was running inside Docker, **do not directly copy the PostgreSQL volume directory** into the Windows PostgreSQL installation.

Use a logical backup.

First find the container:

```bash
docker ps -a
```

Start the old PostgreSQL container if necessary:

```bash
docker start <POSTGRES_CONTAINER>
```

Create the dump:

```bash
docker exec -t <POSTGRES_CONTAINER> \
  pg_dump -U postgres -d parking_db > parking_db.sql
```

Check the dump:

```bash
head -n 20 parking_db.sql
```

A valid dump should contain something similar to:

```text
--
-- PostgreSQL database dump
--
```

Check its size:

```bash
ls -lh parking_db.sql
```

---

# 14. Important Dump Error

We encountered this error:

```text
ERROR: syntax error at or near "pg_dump"
```

The cause was that the dump file contained an error message instead of SQL:

```text
pg_dump: error: connection to server ...
```

This happens when `pg_dump` fails but the output is redirected into a file.

For example:

```bash
pg_dump ... > parking_db.sql
```

If `pg_dump` fails, the generated file may not be usable.

Always verify:

```bash
head -n 20 parking_db.sql
```

Before importing.

---

# 15. Import the Database into Windows PostgreSQL

From WSL:

```bash
WINDOWS_HOST=$(ip route | awk '/default/ {print $3}')
```

Import:

```bash
psql \
  -h "$WINDOWS_HOST" \
  -U postgres \
  -d parking_db \
  < parking_db.sql
```

Test:

```bash
psql \
  -h "$WINDOWS_HOST" \
  -U postgres \
  -d parking_db \
  -c "SELECT 1;"
```

---

# 16. Update Python Requirements

Activate the Python environment:

```bash
source venv/bin/activate
```

Check installed packages:

```bash
pip freeze
```

Backup the existing requirements:

```bash
cp requirements.txt requirements.txt.backup
```

Generate the updated file:

```bash
pip freeze > requirements.txt
```

Check:

```bash
cat requirements.txt
```

---

# 17. Final Development Checklist

Before starting the Parking Backend:

```bash
WINDOWS_HOST=$(ip route | awk '/default/ {print $3}')

echo "Windows Host: $WINDOWS_HOST"

echo "Checking PostgreSQL..."
pg_isready -h "$WINDOWS_HOST" -p 5432 -U postgres

echo "Checking Redis..."
redis-cli -h "$WINDOWS_HOST" -p 6379 ping
```

Expected:

```text
Windows Host: 172.27.240.1

Checking PostgreSQL...
172.27.240.1:5432 - accepting connections

Checking Redis...
PONG
```

Then:

```bash
source venv/bin/activate
uvicorn app.main:app --reload
```

## Final architecture for development

```text
┌───────────────────────────────────────────┐
│ Windows                                    │
│                                            │
│  Native PostgreSQL :5432                   │
│          ▲                                 │
│          │                                 │
│  Native Redis      :6379                   │
│          ▲                                 │
└──────────┼─────────────────────────────────┘
           │
           │ WSL2 Network
           │
┌──────────┼─────────────────────────────────┐
│          ▼                                 │
│ WSL Ubuntu                                 │
│                                            │
│  Python Virtual Environment                │
│        │                                   │
│        ▼                                   │
│  FastAPI Parking Backend                   │
│        │                                   │
│        ├── SQLAlchemy / AsyncPG → Postgres │
│        │                                   │
│        └── Redis Client → Redis            │
│                                            │
└────────────────────────────────────────────┘
```

**Important rule:** use Docker again when you need containerized testing or production parity, but for lightweight local development, **WSL + native Windows PostgreSQL + native Windows Redis** can be a very practical setup.
