Below is a **clean professional documentation** you can keep as a reference for your future projects or team onboarding.

---

# 📘 MySQL Backup Automation with Ansible (VPS → Local Sync)

## 📌 Overview

This system automates:

```text
1. Backup MySQL database on VPS
2. Save backup on VPS
3. Automatically download backup to local machine
4. Ensure secure credential handling
```

It is designed for:

* DevOps practice
* Disaster recovery preparation
* Team collaboration environments

---

# 🏗 Architecture

```text
Local Machine (Ansible Control Node)
        │
        │ SSH (Ansible)
        ▼
VPS Server (MySQL)
        │
        ├── mysqldump runs
        ├── backup stored in /opt/backups
        └── fetch module returns file
        ▼
Local Machine
        └── ./backups/*.sql
```

---

# 📁 Project Structure

```text
ansible-lab/
│
├── inventory.ini
├── backup-and-logs.yml
├── backups/              # downloaded backups (local)
```

---

# ⚙️ Requirements

## On Local Machine

* Ansible installed
* SSH access to VPS
* Linux/macOS recommended

## On VPS

* MySQL installed
* User with DB access
* SSH enabled

---

# 🔐 Database Credentials Handling

## Why we use `/root/.my.cnf`

Instead of passing password on the command line:

```bash
mysqldump -u user -p password db   ❌ insecure
```

We use a credential file on the VPS:

```ini
/root/.my.cnf
```

```ini
[client]
user=<mysql_user>
password=<mysql_password>
```

### Benefits

* No password exposure in process list
* Safer automation
* Cleaner scripts
* Compatible with CI/CD tools
* Avoids committing secrets to source control

> Tip: For future use, keep `mysql_user` and `mysql_password` as placeholders in documentation and use Ansible Vault or an encrypted inventory file for real secret values.

---

# 📜 Ansible Playbook

## backup-and-logs.yml

```yaml
- hosts: vps
  become: yes

  vars:
    backup_dir: /opt/backups
    mysql_user: <mysql_user>
    mysql_password: "<mysql_password>"
    database_name: <database_name>

  # Note: Replace placeholders with your actual values before running.
  # For production use, store secrets in Ansible Vault or a secure secrets store.

  tasks:

    - name: Create backup directory
      file:
        path: "{{ backup_dir }}"
        state: directory
        mode: '0755'

    - name: Create MySQL credentials file
      copy:
        content: |
          [client]
          user={{ mysql_user }}
          password={{ mysql_password }}
        dest: /root/.my.cnf
        mode: '0600'

    - name: Create timestamp
      set_fact:
        timestamp: "{{ lookup('pipe', 'date +%F_%H-%M-%S') }}"

    - name: Backup MySQL database
      shell: |
        mysqldump {{ database_name }} > {{ backup_dir }}/{{ database_name }}_{{ timestamp }}.sql

    - name: Find latest backup
      shell: ls -t {{ backup_dir }}/{{ database_name }}_*.sql | head -1
      register: latest_backup

    - name: Download backup to local machine
      fetch:
        src: "{{ latest_backup.stdout }}"
        dest: "./backups/"
        flat: yes
```

---

# 🚀 Execution Command

Run backup:

```bash
ansible-playbook -i inventory.ini backup-and-logs.yml
```

---

# 📥 Output Result

## On VPS

```text
/opt/backups/
└── test_migration_2026-06-06_20-30-10.sql
```

## On Local Machine

```text
./backups/
└── test_migration_2026-06-06_20-30-10.sql
```

---

# 🔄 Workflow Summary

```text
Step 1 → Ansible connects to VPS
Step 2 → Creates backup directory
Step 3 → Writes MySQL credentials file
Step 4 → Executes mysqldump
Step 5 → Saves backup on VPS
Step 6 → Fetches file to local machine
```

---

# 🧠 Key Concepts Learned

## 1. Backup Location Strategy

| Location | Purpose                |
| -------- | ---------------------- |
| VPS      | Temporary storage      |
| Local    | Safe copy for recovery |

---

## 2. Fetch vs Copy

| Module | Purpose        |
| ------ | -------------- |
| copy   | Local → Remote |
| fetch  | Remote → Local |

---

## 3. Credentials Security

* Avoid exposing passwords in CLI
* Use `.my.cnf` for automation
* Essential for CI/CD pipelines

---

## 4. Timestamping

Ensures:

```text
No file overwrite
Version history of backups
Easy rollback selection
```

---

# ⚠️ Important Limitations

This system:

### ✔ Can do:

* Full database backup
* Restore full state
* Basic disaster recovery

### ❌ Cannot do:

* Recover only deleted rows
* Point-in-time recovery
* Transaction-level rollback

For that you need:

```text
Binary Logs (binlog) + PITR system
```

---

# 🔐 Security Recommendations

* Do NOT commit passwords in Git
* Use Ansible Vault for production
* Restrict SSH access
* Rotate MySQL credentials periodically

---

# 📈 Next Level Improvements

You can extend this system with:

### 🔥 Level 2

* automated cron backup via Ansible

### 🔥 Level 3

* compression (gzip)

### 🔥 Level 4

* upload to cloud (S3 / Google Drive)

### 🔥 Level 5

* restore automation playbook

### 🔥 Level 6 (Senior Level)

* binlog + PITR recovery system

---

# 🏁 Final Summary

This system provides:

```text
✔ Automated MySQL backup
✔ Remote execution via Ansible
✔ Secure credential handling
✔ Automatic download to local machine
✔ Timestamped versioning
```

---


