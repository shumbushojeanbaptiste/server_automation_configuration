| MySQL                      | PostgreSQL                         |
| -------------------------- | ---------------------------------- |
| `SHOW DATABASES;`          | `\l`                               |
| `USE parking_db;`          | `\c parking_db`                    |
| `SHOW TABLES;`             | `\dt`                              |
| `DESCRIBE users;`          | `\d users`                         |
| `SHOW COLUMNS FROM users;` | `\d users`                         |
| `SHOW DATABASES;`          | `SELECT datname FROM pg_database;` |
| `SELECT DATABASE();`       | `SELECT current_database();`       |
