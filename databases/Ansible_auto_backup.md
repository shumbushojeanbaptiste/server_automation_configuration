# Ansible PostgreSQL Backup

## Purpose

This Ansible setup is used to create and validate PostgreSQL backups before deploying the Parking application.

The backup is taken from the PostgreSQL Docker container running on the same VPS.

## Directory Structure

```text
/root/apps/parkings/ansible/
├── ansible.cfg
├── inventory/
│   └── production.ini
├── deploy_parking.yml
└── backup_postgres.yml
```

## Inventory

`inventory/production.ini`:

```ini
[parking_servers]
localhost ansible_connection=local
```

Ansible runs locally on the VPS.

## Ansible Configuration

`ansible.cfg`:

```ini
[defaults]
inventory = ./inventory/production.ini
host_key_checking = False
```

## PostgreSQL Backup Playbook

Create:

```text
backup_postgres.yml
```

Example:

```yaml
- name: Backup PostgreSQL
  hosts: parking_servers
  become: true

  vars:
    backup_dir: /opt/backups/postgres
    database: parking_db
    postgres_container: postgres

  tasks:

    - name: Create backup directory
      ansible.builtin.file:
        path: "{{ backup_dir }}"
        state: directory
        mode: "0700"

    - name: Generate backup filename
      ansible.builtin.set_fact:
        backup_file: "{{ backup_dir }}/{{ database }}_{{ ansible_date_time.iso8601_basic_short }}.dump"

    - name: Create PostgreSQL backup
      ansible.builtin.shell:
        cmd: >
          docker exec {{ postgres_container }}
          pg_dump -U postgres -d {{ database }} -Fc
          > {{ backup_file }}
      args:
        executable: /bin/bash

    - name: Check backup exists
      ansible.builtin.stat:
        path: "{{ backup_file }}"
      register: backup

    - name: Validate backup
      ansible.builtin.command:
        cmd: >
          docker run --rm
          -v {{ backup_dir }}:/backups
          postgres:18
          pg_restore --list /backups/{{ backup_file | basename }}
      when: backup.stat.exists

    - name: Show backup location
      ansible.builtin.debug:
        msg: "Backup created: {{ backup_file }}"
```

## Run Backup

From:

```text
/root/apps/parkings/ansible
```

run:

```bash
ansible-playbook backup_postgres.yml
```

Because `ansible.cfg` already defines the inventory, you do not need:

```bash
-i inventory/production.ini
```

## Result

Backups will be stored in:

```text
/opt/backups/postgres/
```

Example:

```text
parking_db_20260930T041500.dump
```

The backup uses PostgreSQL custom format (`-Fc`), which can later be restored using `pg_restore`.

## Verify Backups Manually

List backups:

```bash
ls -lh /opt/backups/postgres/
```

Inspect a backup:

```bash
docker run --rm \
  -v /opt/backups/postgres:/backups \
  postgres:18 \
  pg_restore --list /backups/parking_db_YYYYMMDDTHHMMSS.dump
```

## Important Production Rules

* Take a backup **before database migrations**.
* Do not delete old backups immediately after deployment.
* Keep backups on storage separate from the PostgreSQL data volume when possible.
* Periodically test restoring a backup to a temporary PostgreSQL database.
* For stronger disaster recovery, copy backups to **off-site storage**.
* Do not store database passwords directly inside the playbook.
* Do not use `alembic downgrade` as an automatic database rollback after a failed deployment.

## Deployment Flow

The backup playbook can later become part of the complete deployment:

```text
Ansible Deployment
       │
       ▼
PostgreSQL Backup
       │
       ▼
Validate Backup
       │
       ▼
Pull parking-api:1.2.0
       │
       ▼
alembic upgrade head
       │
       ▼
Deploy/Replace API Container
       │
       ▼
Health Check
       │
       ▼
Deployment Logs
```

This makes the database migration process traceable and gives you a recovery point before applying schema changes.
