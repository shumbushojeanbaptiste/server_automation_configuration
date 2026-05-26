📘 MySQL / Percona Root Login Recovery (Safe Mode Fix Guide)

## 1) Problem Summary

- Cannot login as root: `mysql -u root -p` => `ERROR 1045 (28000)`
- MySQL service fails after authentication/systemd/config changes
- Use only for emergency recovery situations

## 2) Preconditions (when to use)

- Root access is lost
- MySQL config edits prevent startup
- Need urgent recovery (backup first, if possible)

> ⚠️ Do not use on production without change control and backups.

## 3) Step 1: Stop MySQL safely

1. `sudo systemctl stop mysql` (or `mariadb` if relevant)
2. Confirm stopped: `sudo systemctl status mysql`

## 4) Step 2: Start MySQL with grant tables disabled

1. `sudo mysqld_safe --skip-grant-tables --skip-networking &`
2. Wait 5–10 seconds
3. Verify process running: `ps -ef | grep mysqld`

## 5) Step 3: Login as root without password

1. `mysql -u root`
2. Check prompt is `mysql>` (success)

## 6) Step 4: Reload privilege tables

- `FLUSH PRIVILEGES;`

## 7) Step 5: Inspect user authenticity data

- `SELECT user, host, plugin, authentication_string FROM mysql.user;`
- Confirm `'root'@'localhost'` and plugin value

## 8) Step 6: Restore root credentials

### 6a) Recommended (safe modern approach)

```
ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY 'YourStrongPassword123!';
FLUSH PRIVILEGES;
```

### 6b) If `ALTER USER` fails (legacy fallback)

```
UPDATE mysql.user
  SET plugin='mysql_native_password',
      authentication_string=PASSWORD('YourStrongPassword123!')
  WHERE user='root' AND host='localhost';
FLUSH PRIVILEGES;
```

### 6c) Clean recreate root account (last resort)

```
DROP USER IF EXISTS 'root'@'localhost';
CREATE USER 'root'@'localhost' IDENTIFIED BY 'YourStrongPassword123!';
GRANT ALL PRIVILEGES ON *.* TO 'root'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;
```

## 9) Step 7: Exit safe mode client

- `exit;`

## 10) Step 8: Stop the temporary server and cleanup

1. `sudo systemctl stop mysql`
2. If still running: `sudo killall mysqld`

## 11) Step 9: Ensure runtime socket directory exists

```
sudo mkdir -p /var/run/mysqld
sudo chown mysql:mysql /var/run/mysqld
sudo chmod 755 /var/run/mysqld
```

## 12) Step 10: Start MySQL normally

- `sudo systemctl start mysql`
- Check status: `sudo systemctl status mysql`

## 13) Step 11: Test root login with new password

- `mysql -u root -p`
- Enter the password set in step 6

## 14) Step 12: Verify MySQL health

```
SELECT 1;
SHOW VARIABLES LIKE 'server_id';
SHOW VARIABLES LIKE 'log_bin';
SHOW STATUS LIKE 'uptime';
```

## 15) Emergency recovery checklist (if startup still fails)

1. `sudo systemctl daemon-reexec`
2. `sudo systemctl daemon-reload`
3. `sudo systemctl restart mysql`
4. `sudo journalctl -xeu mysql | tail -n 40`

## 16) Safety rules

- ❌ Do not leave `--skip-grant-tables` enabled longer than needed
- ❌ Do not drop core users without verified backup
- ✅ Always document each change and verify afterward
- ✅ Record final command and credentials in secure vault

## 17) Quick lookup commands

- `sudo journalctl -xeu mysql`
- `sudo mysqladmin version`
- `sudo netstat -tlnp | grep mysql`

---

This step-by-step version is ready for long-term reuse and safe emergency recovery.