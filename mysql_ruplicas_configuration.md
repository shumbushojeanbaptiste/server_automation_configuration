# 📘 MySQL Replication Setup Guide (VPS Primary → EC2 Replica)

## 🎯 Objective

Establish real-time MySQL replication from a VPS (primary/master) to an EC2 instance (replica/slave) for backup, failover, or read scaling.

---

## Prerequisites

- **MySQL installed** on both VPS and EC2 (version 8.x recommended)
- **Root access** to both servers
- **Network connectivity**: EC2 can reach VPS on port 3306
- **Firewall open**: Allow 3306 from EC2 IP on VPS
- **Database exists**: Target DB (e.g., `store`) created on both servers
- **Credentials ready**: Strong password for replication user

**N.B: MySQL login commands**
- Current server: `mysql -u root -p`
- Remote server: `mysql -h 35.180.140.186 -u admin -p`

---

## 1) Configure Primary Server (VPS)

### 1.1 Edit MySQL Configuration

```bash
sudo nano /etc/mysql/mysql.conf.d/mysqld.cnf
```

Add these lines under `[mysqld]` section:

```ini
server-id = 1
log_bin = mysql-bin
binlog_format = ROW
binlog_do_db = store
```

### 1.2 Restart MySQL Service

```bash
sudo systemctl restart mysql
```

### 1.3 Verify Configuration

```bash
sudo systemctl status mysql
mysql -u root -p -e "SHOW VARIABLES LIKE 'server_id';"
```

Expected: `server_id = 1`

---

## 2) Create Replication User on Primary

### 2.1 Login to MySQL

```bash
mysql -u root -p
```

### 2.2 Create User with Replication Privileges

```sql
CREATE USER 'replica_user'@'%' IDENTIFIED BY 'StrongPass123!';
GRANT REPLICATION SLAVE ON *.* TO 'replica_user'@'%';
FLUSH PRIVILEGES;
```

### 2.3 Verify User Creation

```sql
SELECT user, host FROM mysql.user WHERE user='replica_user';
```

Expected: One row with `replica_user` and `%`

---

## 3) Get Master Status from Primary

### 3.1 Run Status Query

```sql
SHOW MASTER STATUS;
```

### 3.2 Record Values

- **File**: e.g., `mysql-bin.000008`
- **Position**: e.g., `1665`

> **Important**: Note these values exactly for Step 5.

---

## 4) Configure Replica Server (EC2)

### 4.1 Edit MySQL Configuration

```bash
sudo nano /etc/mysql/mysql.conf.d/mysqld.cnf
```

Add these lines under `[mysqld]` section:

```ini
server-id = 2
relay-log = relay-bin
log_bin = mysql-bin
binlog_format = ROW
read_only = 1
```

### 4.2 Restart MySQL Service

```bash
sudo systemctl restart mysql
```

### 4.3 Verify Configuration

```bash
sudo systemctl status mysql
mysql -u root -p -e "SHOW VARIABLES LIKE 'server_id';"
```

Expected: `server_id = 2`

---

## 5) Connect Replica to Primary

### 5.1 Login to Replica MySQL

```bash
mysql -u root -p
```

### 5.2 Configure Replication Source

Replace with your actual values:

```sql
CHANGE REPLICATION SOURCE TO
SOURCE_HOST='31.97.215.87',
SOURCE_USER='replica_user',
SOURCE_PASSWORD='StrongPass123!',
SOURCE_LOG_FILE='mysql-bin.000008',
SOURCE_LOG_POS=1665;
```

### 5.3 Verify Configuration

```sql
SHOW REPLICA STATUS\G
```

Check `Source_Host`, `Source_User`, `Source_Log_File`, `Source_Log_Pos`.

---

## 6) Start Replication

### 6.1 Start Replica

```sql
START REPLICA;
```

### 6.2 Check Status Immediately

```sql
SHOW REPLICA STATUS\G
```

Look for:
- `Replica_IO_Running: Yes`
- `Replica_SQL_Running: Yes`

---

## 7) Verify Replication is Working

### 7.1 Wait 30-60 seconds

### 7.2 Check Status Again

```sql
SHOW REPLICA STATUS\G
```

### 7.3 Test Data Sync

On Primary (VPS):

```sql
USE store;
CREATE TABLE test_replication (id INT PRIMARY KEY, data VARCHAR(50));
INSERT INTO test_replication VALUES (1, 'replication test');
```

On Replica (EC2):

```sql
USE store;
SELECT * FROM test_replication;
```

Expected: See the inserted row.

---

## 🚨 Troubleshooting Common Issues

### Issue: Authentication Error (`caching_sha2_password`)

**Error**: `caching_sha2_password requires secure connection`

**Fix** (on Primary):

```sql
ALTER USER 'replica_user'@'%' IDENTIFIED WITH mysql_native_password BY 'StrongPass123!';
FLUSH PRIVILEGES;
```

Then restart replication on Replica:

```sql
STOP REPLICA;
START REPLICA;
```

### Issue: Duplicate Server IDs

**Error**: `equal MySQL server ids`

**Fix**: Ensure unique IDs:
- Primary: `server-id = 1`
- Replica: `server-id = 2`

Restart both services after config change.

### Issue: Metadata Lock or Position Error

**Error**: `ERROR 1872` or position issues

**Fix** (on Replica):

```sql
STOP REPLICA;
RESET REPLICA ALL;
```

Then reconfigure from Step 5.

### Issue: Network/Firewall

**Check**:
- Port 3306 open on VPS firewall
- EC2 security group allows outbound to VPS:3306
- Test connectivity: `telnet 31.97.215.87 3306`

---

## 🧠 Key Notes

- **Server IDs**: Must be unique across all MySQL instances
- **Read-Only**: Replica is read-only by default (`read_only = 1`)
- **Data Safety**: Never write directly to replica
- **Monitoring**: Regularly check `SHOW REPLICA STATUS\G`
- **Backup**: Use replica for backups to avoid primary load

---

## 🎉 Success Indicators

- `Replica_IO_Running: Yes`
- `Replica_SQL_Running: Yes`
- `Seconds_Behind_Source: 0` (or low number)
- Data changes on primary appear on replica within seconds

---

## 🚀 Next Steps (Optional)

- **Add more replicas**: Repeat Steps 4-7 with unique server-ids
- **Failover setup**: Configure Orchestrator or MHA
- **Load balancing**: Add ProxySQL for read/write splitting
- **Monitoring**: Set up alerts for replication lag/errors

---

This guide is now structured for easy reuse in future replication setups.
