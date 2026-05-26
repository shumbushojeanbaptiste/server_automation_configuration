# 📘 MySQL Replication & Orchestrator Setup Guide

## 🧠 1. Overview

This guide provides a step-by-step process to set up MySQL replication between a primary database (on VPS) and a replica database (on AWS EC2/RDS), along with Orchestrator for monitoring and automated failover.

### 🎯 Objectives
- Achieve high availability (HA) for MySQL databases
- Enable data replication using GTID (Global Transaction Identifiers)
- Automate failover processes
- Maintain scalability for read operations

### 📋 Prerequisites
- MySQL 8.x installed on both primary and replica servers
- Root access to MySQL on both servers
- Network connectivity between primary (VPS: 31.97.215.87) and replica (AWS EC2/RDS)
- Port 3306 open between servers
- Basic knowledge of Linux command line and MySQL

---

## 🏗️ 2. Architecture Overview

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

## ⚙️ Step 3: Configure Primary Database (VPS)

### 3.1 Edit MySQL Configuration
**File:** `/etc/mysql/mysql.conf.d/mysqld.cnf`

Add or modify the following lines:

```ini
server-id=1
log_bin=mysql-bin
binlog_format=ROW

# GTID Configuration (Required for reliable replication)
gtid_mode=ON
enforce_gtid_consistency=ON
log_replica_updates=ON
```

### 3.2 Restart MySQL Service
```bash
sudo systemctl restart mysql
```

### 3.3 Verify Configuration
```bash
mysql -u root -p -e "SHOW VARIABLES LIKE 'gtid_mode';"
```
Expected output: `gtid_mode = ON`

---

## 👤 Step 4: Create Replication User on Primary

Connect to MySQL as root and execute:

```sql
CREATE USER 'replica_user'@'%' IDENTIFIED BY 'StrongPassword123!';
GRANT REPLICATION SLAVE, REPLICATION CLIENT ON *.* TO 'replica_user'@'%';
FLUSH PRIVILEGES;
```

**Note:** Replace `'StrongPassword123!'` with a secure password.

### 4.1 Verify User Creation
```sql
SELECT User, Host FROM mysql.user WHERE User = 'replica_user';
```

---

## ☁️ Step 5: Configure Replica Database (AWS EC2/RDS)

### 5.1 Stop Existing Replication (if any)
```sql
STOP REPLICA;
RESET REPLICA ALL;
```

### 5.2 Configure Replication Source
```sql
CHANGE REPLICATION SOURCE TO
SOURCE_HOST='31.97.215.87',
SOURCE_USER='replica_user',
SOURCE_PASSWORD='StrongPassword123!',
SOURCE_AUTO_POSITION = 1;
```

**Note:** Ensure the password matches the one created in Step 4.

### 5.3 Start Replication
```sql
START REPLICA;
```

### 5.4 Verify Replication Status
```sql
SHOW REPLICA STATUS\G
```

Look for:
- `Replica_IO_Running: Yes`
- `Replica_SQL_Running: Yes`
- `Replica_IO_State: Waiting for source to send event`

### 5.5 Test Replication
On Primary:
```sql
CREATE DATABASE test_replication;
USE test_replication;
CREATE TABLE test_table (id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(50));
INSERT INTO test_table (name) VALUES ('test_data');
```

On Replica:
```sql
USE test_replication;
SELECT * FROM test_table;
```
Expected: Should see the inserted data.

---

## 🛠️ Step 6: Install Orchestrator

### 6.1 Download and Install
```bash
wget https://github.com/openark/orchestrator/releases/download/v3.2.6/orchestrator_3.2.6_amd64.deb
sudo dpkg -i orchestrator_3.2.6_amd64.deb
```

### 6.2 Install Dependencies (if needed)
```bash
sudo apt-get update
sudo apt-get install -f
```

---

## 🧾 Step 7: Configure Orchestrator

**File:** `/etc/orchestrator.conf.json`

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

## 🗄️ Step 8: Create Orchestrator Database

Connect to MySQL and execute:

```sql
CREATE DATABASE orchestrator;

CREATE USER 'orc_user'@'localhost' IDENTIFIED BY 'orc_pass';
GRANT ALL PRIVILEGES ON orchestrator.* TO 'orc_user'@'localhost';

FLUSH PRIVILEGES;
```

---

## ▶️ Step 9: Start Orchestrator Service

```bash
sudo systemctl start orchestrator
sudo systemctl enable orchestrator
```

### 9.1 Verify Service Status
```bash
sudo systemctl status orchestrator
```

---

## 🌐 Step 10: Access Orchestrator Web UI

Open a web browser and navigate to:
```
http://YOUR_SERVER_IP:3000/web/clusters
```

Replace `YOUR_SERVER_IP` with the actual IP address of the server running Orchestrator.

---

## 🔍 Step 11: Discover MySQL Cluster

```bash
/usr/local/orchestrator/orchestrator -c discover -i 31.97.215.87:3306
```

### 11.1 Verify Discovery
Refresh the Orchestrator web UI and check if the cluster appears.

---

## 🧪 Step 12: Test Complete Setup

### 12.1 Test Replication Lag
```sql
SHOW REPLICA STATUS\G
```
Check `Seconds_Behind_Source` (should be 0 or low).

### 12.2 Test Failover (Optional - Use with Caution)
Monitor the Orchestrator UI while temporarily stopping the primary MySQL service to see if Orchestrator detects the failure.

---

## ⚠️ Step 13: Best Practices

### ✅ Recommended Practices
- Always use GTID replication for reliability
- Monitor replication lag regularly
- Use strong, unique passwords
- Keep MySQL versions consistent across servers
- Regularly backup both primary and replica databases
- Test failover procedures in staging environment first

### ❌ Avoid These
- Using manual binlog positions in production
- Mixing different binlog filtering options blindly
- Ignoring network security (firewalls, security groups)
- Running Orchestrator on the same server as primary database

---

## 🧠 Why GTID is Important

| Without GTID         | With GTID     |
| -------------------- | ------------- |
| Manual file/position | Automatic     |
| Error-prone          | Reliable      |
| Hard failover        | Easy failover |

---

## 🚀 Future Enhancements

- Add ProxySQL for read/write splitting
- Implement multiple replicas for better load distribution
- Set up automatic failover scripts
- Integrate with monitoring tools (Prometheus, Grafana)
- Implement backup automation

---

## 🧠 Troubleshooting

| Problem             | Possible Cause                  | Solution             |
| ------------------- | ------------------------------- | -------------------- |
| Replica not syncing | Wrong credentials               | Verify user/password |
| Orchestrator empty  | No discovery run                | Execute discover command |
| SQL error 1064      | MySQL version mismatch          | Ensure GTID compatibility |
| Connection refused  | Firewall blocking port 3306     | Open port in security groups |
| Replication lag     | Network issues or high load     | Check network and optimize queries |

---

## 📌 Summary

This setup provides:
- ✅ High availability through automated failover
- ✅ Scalability via read replicas
- ✅ Fault tolerance with monitoring
- ✅ Production-ready MySQL architecture

---

## 👨‍💻 Notes

This guide is designed for:
- VPS primary database deployments
- AWS hybrid cloud architectures
- Modern MySQL 8+ environments
- Real-world production systems

**Last Updated:** March 25, 2026