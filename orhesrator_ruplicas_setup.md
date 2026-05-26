# 📘 MySQL Replication & Orchestrator Setup Documentation

## 🧠 1. Overview

This document describes how to configure:

* MySQL Primary (VPS)
* MySQL Replica (AWS EC2 / RDS)
* Orchestrator for monitoring and failover

### 🎯 Goal

* Ensure high availability (HA)
* Enable replication
* Automate failover
* Maintain scalability

---

## 🏗️ 2. Architecture

```
        ┌───────────────┐
        │   Application │
        └───────┬───────┘
                │
        ┌───────▼────────┐
        │   Primary DB   │  (VPS: 31.97.215.87)
        │   MySQL 8.x    │
        └───────┬────────┘
                │ Replication (GTID)
        ┌───────▼────────┐
        │   Replica DB   │  (AWS EC2 / RDS)
        │   MySQL 8.x    │
        └────────────────┘

        ┌────────────────────────────┐
        │       Orchestrator         │
        │   Monitoring + Failover    │
        └────────────────────────────┘
```

---

## ⚙️ 3. Primary (VPS) Configuration

### 📍 File: `/etc/mysql/mysql.conf.d/mysqld.cnf`

```ini
server-id=1
log_bin=mysql-bin
binlog_format=ROW

# GTID (REQUIRED)
gtid_mode=ON
enforce_gtid_consistency=ON
log_replica_updates=ON
```

### 🔄 Restart MySQL

```bash
sudo systemctl restart mysql
```

---

## 👤 4. Create Replication User

```sql
CREATE USER 'replica_user'@'%' IDENTIFIED BY 'StrongPassword123!';
GRANT REPLICATION SLAVE, REPLICATION CLIENT ON *.* TO 'replica_user'@'%';
FLUSH PRIVILEGES;
```

---

## ☁️ 5. Replica (AWS EC2 / RDS) Setup

### 🔹 Stop any existing replication

```sql
STOP REPLICA;
RESET REPLICA ALL;
```

---

### 🔹 Configure replication (GTID MODE)

```sql
CHANGE REPLICATION SOURCE TO
SOURCE_HOST='31.97.215.87',
SOURCE_USER='replica_user',
SOURCE_PASSWORD='StrongPassword123!',
SOURCE_AUTO_POSITION = 1;
```

---

### 🔹 Start replication

```sql
START REPLICA;
```

---

### 🔍 Verify

```sql
SHOW REPLICA STATUS\G
```

Expected:

```
Replica_IO_Running: Yes
Replica_SQL_Running: Yes
```

---

## 🧠 6. Why GTID is Important

| Without GTID         | With GTID     |
| -------------------- | ------------- |
| Manual file/position | Automatic     |
| Error-prone          | Reliable      |
| Hard failover        | Easy failover |

---

## 🛠️ 7. Orchestrator Installation

```bash
wget https://github.com/openark/orchestrator/releases/download/v3.2.6/orchestrator_3.2.6_amd64.deb
sudo dpkg -i orchestrator_3.2.6_amd64.deb
```

---

## 🧾 8. Orchestrator Configuration

📍 File: `/etc/orchestrator.conf.json`

```json
{
  "Debug": true,
  "ListenAddress": "0.0.0.0:3000",

  "MySQLTopologyUser": "replica_user",
  "MySQLTopologyPassword": "StrongPassword123!",

  "MySQLOrchestratorHost": "127.0.0.1",
  "MySQLOrchestratorPort": 3306,
  "MySQLOrchestratorDatabase": "orchestrator",
  "MySQLOrchestratorUser": "orc_user",
  "MySQLOrchestratorPassword": "orc_pass",

  "BackendDB": "mysql",

  "DiscoverByShowReplicaHosts": true,
  "InstancePollSeconds": 5
}
```

---

## 🗄️ 9. Create Orchestrator Database

```sql
CREATE DATABASE orchestrator;

CREATE USER 'orc_user'@'localhost' IDENTIFIED BY 'orc_pass';
GRANT ALL PRIVILEGES ON orchestrator.* TO 'orc_user'@'localhost';

FLUSH PRIVILEGES;
```

---

## ▶️ 10. Start Orchestrator

```bash
sudo systemctl start orchestrator
sudo systemctl enable orchestrator
```

---

## 🌐 11. Access UI

```
http://YOUR_SERVER_IP:3000/web/clusters
```

---

## 🔍 12. Discover Cluster

```bash
/usr/local/orchestrator/orchestrator -c discover -i 31.97.215.87:3306
```

---

## 🔁 13. Failover Concept

When Primary fails:

1. Orchestrator detects failure
2. Promotes Replica → New Primary
3. Updates topology
4. Applications reconnect

---

## ⚠️ 14. Best Practices

### ✅ DO

* Use GTID replication
* Use replica-side filtering
* Monitor replication lag
* Use strong passwords

### ❌ DON’T

* Use manual binlog positions in production
* Mix binlog_do_db and replicate_do_db blindly
* Ignore firewall/security groups

---

## 🧪 15. Testing Replication

On Primary:

```sql
INSERT INTO test_table (name) VALUES ('hello');
```

On Replica:

```sql
SELECT * FROM test_table;
```

---

## 🚀 16. Future Improvements

* Add ProxySQL (read/write split)
* Add multiple replicas
* Enable automatic failover scripts
* Integrate monitoring (Prometheus, Grafana)

---

## 🧠 17. Troubleshooting

| Problem             | Cause                  | Solution             |
| ------------------- | ---------------------- | -------------------- |
| Replica not syncing | wrong credentials      | fix user/password    |
| Orchestrator empty  | no discovery           | run discover command |
| SQL error 1064      | MySQL version mismatch | use GTID             |
| Connection refused  | firewall blocked       | open port 3306       |

---

## 📌 Conclusion

This setup provides:

✔ High availability
✔ Scalability
✔ Fault tolerance
✔ Production-ready architecture

---

## 👨‍💻 Author Notes

Designed for:

* VPS primary deployments
* AWS hybrid architecture
* Modern MySQL 8+ environments
* Real-world production systems

---
