Yes. Now we have enough evidence to handle this safely.

The important point is: **the `BEGIN TRANSACTION` itself is not the problem**. The problem is that some transactions are staying open for **hours** because the MySQL connection is released while the transaction is still active, or some code path returns without `COMMIT`/`ROLLBACK`.

Your evidence proves this:

* Thread **1059** has an InnoDB transaction open for ~9.4 hours.
* Thread **1060** has one open for ~3.7 hours.
* Both are `Sleep`.
* A sleeping connection can still hold InnoDB row locks.
* Your old deadlock specifically involved `tbl_users`.
* phpMyAdmin was waiting on a metadata lock for `admin_actions`.
* Your application uses a pool, so a connection with an unfinished transaction can remain in the pool and later be reused.

## 1. First: don't deploy yet

I recommend one small safety step before deployment.

Run:

```sql
SELECT
    trx_id,
    trx_state,
    trx_started,
    TIMESTAMPDIFF(SECOND, trx_started, NOW()) AS age_seconds,
    trx_mysql_thread_id,
    trx_tables_locked,
    trx_rows_locked,
    trx_rows_modified,
    trx_query
FROM information_schema.innodb_trx
ORDER BY trx_started;
```

You currently have:

```text
1059  ~33835 seconds
1060  ~13390 seconds
```

Those are **not normal application transactions**.

Since you already killed the phpMyAdmin `970` process successfully, that's good.

### Now kill the two stale application sessions

Because these are your application's own `rayon` connections and they have been holding transactions for hours:

```sql
KILL 1059;
KILL 1060;
```

Then verify:

```sql
SELECT
    trx_id,
    trx_state,
    trx_started,
    TIMESTAMPDIFF(SECOND, trx_started, NOW()) AS age_seconds,
    trx_mysql_thread_id
FROM information_schema.innodb_trx;
```

Ideally:

```text
Empty set
```

or only very short-lived transactions.

Then:

```sql
SHOW FULL PROCESSLIST;
```

You should see your normal application `Sleep` connections, but **no multi-hour transactions**.

---

# 2. Very important: your `db.js` is mostly fine

Your pool configuration is not what created this problem.

This is reasonable:

```js
const pool = mysql.createPool({
  host: MYSQL_HOST,
  user: MYSQL_USER,
  password: MYSQL_PASSWORD,
  database: MYSQL_DB,

  connectionLimit: MYSQL_CONNECTION_LIMIT,
  queueLimit: MYSQL_QUEUE_LIMIT,
  waitForConnections: true,

  enableKeepAlive: true,
  keepAliveInitialDelay: 10000,

  connectTimeout: MYSQL_CONNECT_TIMEOUT,

  charset: "utf8mb4",
  timezone: "Z",
});
```

The dangerous part is not:

```js
connectionLimit: 20
```

The dangerous part is application code doing:

```text
getConnection()
       ↓
BEGIN
       ↓
SELECT ... FOR UPDATE
       ↓
UPDATE
       ↓
something goes wrong / return / exception
       ↓
connection.release()
       ↓
transaction remains open
```

That's what we need to eliminate.

---

# 3. I found an important issue in your `subscriptionPayment`

You showed this:

```js
connection = await db.promise().getConnection();

await connection.beginTransaction();
```

Then later you have:

```js
if (planResult.length === 0) {
  return error(
    res,
    "Invalid or inactive plan",
    404
  );
}
```

This is a problem.

You started a transaction:

```js
BEGIN
```

and then returned without:

```js
ROLLBACK
```

You do the same type of thing in several places.

For example:

```js
if (walletResult.length === 0) {
  return error(
    res,
    "Wallet not found",
    404
  );
}
```

And:

```js
if (planPrice !== paymentAmount) {
  return error(
    res,
    "Invalid amount for this plan",
    400
  );
}
```

Those happen **after `BEGIN TRANSACTION`**.

So you can get:

```text
BEGIN
  ↓
SELECT
  ↓
condition fails
  ↓
return response
  ↓
finally
  ↓
connection.release()
```

But there was never:

```sql
ROLLBACK
```

That is exactly the kind of code that can create the long-lived sleeping transactions you are seeing.

---

# 4. Fix `subscriptionPayment` first

The safest pattern is:

```js
let connection;

try {
    connection = await db.promise().getConnection();

    await connection.beginTransaction();

    // database work

    await connection.commit();

} catch (err) {

    if (connection) {
        try {
            await connection.rollback();
        } catch (_) {}
    }

    // error response

} finally {

    if (connection) {
        connection.release();
    }
}
```

But there is another important rule:

## Never `return` from inside a transaction without rollback.

For example, this is BAD:

```js
if (planResult.length === 0) {
    return error(res, "Invalid plan", 404);
}
```

Change to:

```js
if (planResult.length === 0) {
    await connection.rollback();

    return error(
        res,
        "Invalid or inactive plan",
        404
    );
}
```

Same for every validation after `BEGIN`.

---

# 5. Even better: validate before `BEGIN`

For `subscriptionPayment`, some validation does not need a transaction.

For example:

```js
const { plan_id, payment_method, amount } = req.body;
const user_id = req.user.user_id;

if (!user_id || !plan_id || !amount) {
    return error(res, "Missing required fields", 400);
}

const paymentAmount = Number(amount);

if (!Number.isFinite(paymentAmount) || paymentAmount <= 0) {
    return error(res, "Invalid amount", 400);
}
```

That's already outside the transaction.

Then:

```js
connection = await db.promise().getConnection();

await connection.beginTransaction();
```

After this point, **every possible exit must commit or rollback**.

---

# 6. Your payment worker is much better

The new `processPendingDeposit()` you showed is actually structured much better.

You have:

```js
const connection = await db.promise().getConnection();

try {
    await connection.beginTransaction();

    ...

    await connection.commit();

} catch (err) {

    try {
        await connection.rollback();
    } catch (_) {}

    throw err;

} finally {
    connection.release();
}
```

That is the pattern I want you to use throughout the payment controllers.

However, there is still something important:

```js
SELECT *
FROM tbl_pending_deposit
WHERE pend_id = ?
FOR UPDATE
```

and:

```js
SELECT w_id, user_id, balance
FROM wallets
WHERE user_id = ?
FOR UPDATE
```

are **supposed to create row locks**.

That's not bad.

The lock exists only while the transaction is active.

Normally:

```text
BEGIN
 ↓
FOR UPDATE
 ↓
UPDATE
 ↓
INSERT
 ↓
COMMIT
 ↓
locks disappear
```

The problem is:

```text
BEGIN
 ↓
FOR UPDATE
 ↓
UPDATE
 ↓
connection sleeps
 ↓
hours later...
```

That's what we're fixing.

---

# 7. There is another serious issue in `buyVideo`

You currently do:

```js
const [walletResult] = await connection.query(
  "SELECT balance FROM wallets WHERE user_id = ?",
  [user_id]
);
```

This is inside a transaction but **doesn't use `FOR UPDATE`**.

Then:

```js
const balance = parseFloat(walletResult[0].balance);
```

Then:

```js
const newBalance = balance - amount;
```

Then:

```js
UPDATE wallets
SET balance = ?
```

This creates a race condition.

Imagine two requests arrive simultaneously:

```text
Request A: balance = 100
Request B: balance = 100

A buys 80
B buys 80

A calculates 20
B calculates 20

A UPDATE → 20
B UPDATE → 20
```

You could have allowed two purchases totaling 160 from a wallet containing only 100.

### Change it to:

```js
const [walletResult] = await connection.query(
  `
  SELECT w_id, balance
  FROM wallets
  WHERE user_id = ?
  LIMIT 1
  FOR UPDATE
  `,
  [user_id]
);
```

Then the wallet row is properly serialized.

---

# 8. Same issue in `withdrawFunds`

You currently have:

```js
SELECT * FROM wallets WHERE user_id = ?
```

inside a transaction.

It should be:

```js
SELECT w_id, balance
FROM wallets
WHERE user_id = ?
LIMIT 1
FOR UPDATE
```

This is important because withdrawal modifies the wallet.

---

# 9. `handlePaymentCallback` also needs improvement

You currently first do:

```js
SELECT * FROM tbl_pending_deposit WHERE ref_code = ?
```

without locking.

Then later you start a transaction.

This can allow two callback requests to race.

Better design:

```text
BEGIN
 ↓
SELECT pending deposit FOR UPDATE
 ↓
check status
 ↓
SELECT wallet FOR UPDATE
 ↓
update wallet
 ↓
insert transaction
 ↓
update pending deposit
 ↓
COMMIT
```

That makes the payment operation atomic.

Your newer `processPendingDeposit()` already follows this much better.

---

# 10. Why `tbl_videos` appeared locked

Your metadata query showed:

```text
tbl_videos
SHARED_READ
TRANSACTION
GRANTED
1152

tbl_videos
SHARED_WRITE
TRANSACTION
GRANTED
1152

tbl_videos
SHARED_WRITE
TRANSACTION
GRANTED
1153

tbl_videos
SHARED_READ
TRANSACTION
GRANTED
1153

tbl_videos
SHARED_READ
TRANSACTION
GRANTED
1063
```

This is **not the same thing as "tbl_videos is permanently locked."**

Those are normal metadata locks associated with active transactions.

The important word is:

```text
TRANSACTION
```

They should disappear when the transaction commits or rolls back.

Your problem is the **transaction lifetime**.

---

# 11. Why phpMyAdmin editing failed

This explains your experience perfectly.

You opened phpMyAdmin:

```text
tbl_videos
```

and tried to edit a row.

phpMyAdmin generated an `UPDATE`.

But another transaction was holding a lock that conflicted with the operation.

So phpMyAdmin waited.

Eventually:

```text
Lock wait timeout
```

or another timeout/error occurred.

Therefore:

> **phpMyAdmin was not necessarily broken, and the table was not necessarily corrupted.**

Your application had long-running transactions.

---

# 12. What about `admin_actions`?

This was separate but related.

You had:

```text
970
olee_admin
Waiting for table metadata lock
LOCK TABLES admin_actions READ
```

for:

```text
57501 seconds
```

That's roughly **15.9 hours**.

You killed it successfully.

Good.

And now:

```sql
SELECT ...
FROM performance_schema.metadata_locks
WHERE OBJECT_SCHEMA = 'rayon-platform'
AND OBJECT_NAME = 'admin_actions';
```

returned:

```text
empty
```

Excellent.

So the `admin_actions` metadata lock problem is currently gone.

---

# 13. About the existing two transactions

These:

```text
1059
1060
```

are the most important things right now.

You have:

```text
1059
started: 07:30
age: 33835 sec

1060
started: 13:11
age: 13390 sec
```

They are:

```text
COMMAND = Sleep
INFO = NULL
```

but:

```text
innodb_trx = RUNNING
```

That means:

> The MySQL connection is sleeping at the protocol level, but its transaction is still open.

That's the dangerous combination.

Kill them before deployment:

```sql
KILL 1059;
KILL 1060;
```

Then verify:

```sql
SELECT
    trx_id,
    trx_started,
    TIMESTAMPDIFF(SECOND, trx_started, NOW()) AS age_seconds,
    trx_mysql_thread_id
FROM information_schema.innodb_trx;
```

---

# 14. How to check whether deleted data can be recovered

This depends on **how it was deleted**.

If someone executed:

```sql
DELETE FROM tbl_videos
WHERE video_id = 123;
```

and committed it, MySQL does not automatically provide a normal "Recycle Bin."

But you may have recovery options:

### A. Binary logs

Check:

```sql
SHOW VARIABLES LIKE 'log_bin';
```

Then:

```sql
SHOW BINARY LOGS;
```

If binary logging is enabled, deleted/changed rows may be reconstructable from binlog events, depending on your `binlog_format`, retention, and backups.

Check:

```sql
SHOW VARIABLES LIKE 'binlog_format';
SHOW VARIABLES LIKE 'binlog_expire_logs_seconds';
```

**Don't change anything yet.** Just send me the results.

### B. Backups

If you have MySQL backups, we can restore the backup into a **separate temporary database** and recover only the missing rows.

Do **not** restore directly over production.

### C. Application audit logs

If your application records:

```text
who
what
when
old value
new value
```

then recovery is much easier.

But if no audit log and no binlog/backup exists, committed deleted data may not be recoverable safely.

---

# 15. Don't use `SHOW ENGINE INNODB STATUS` as your only monitoring method

Your previous deadlock output was useful.

But for production, I'd keep these queries available.

### Current transactions

```sql
SELECT
    trx_id,
    trx_state,
    trx_started,
    TIMESTAMPDIFF(SECOND, trx_started, NOW()) AS age_seconds,
    trx_mysql_thread_id,
    trx_rows_locked,
    trx_rows_modified,
    trx_query
FROM information_schema.innodb_trx
ORDER BY trx_started;
```

### Application connections

```sql
SELECT
    ID,
    USER,
    HOST,
    DB,
    COMMAND,
    TIME,
    STATE,
    INFO
FROM information_schema.PROCESSLIST
WHERE DB = 'rayon-platform'
ORDER BY TIME DESC;
```

### Metadata locks

Your query is good:

```sql
SELECT
    OBJECT_SCHEMA,
    OBJECT_NAME,
    LOCK_TYPE,
    LOCK_DURATION,
    LOCK_STATUS,
    OWNER_THREAD_ID
FROM performance_schema.metadata_locks
WHERE OBJECT_SCHEMA = 'rayon-platform'
ORDER BY OWNER_THREAD_ID;
```

---

# 16. Add transaction safety to your code

For your project, I recommend we eventually create a helper such as:

```js
async function withTransaction(callback) {
    const connection = await db.promise().getConnection();

    try {
        await connection.beginTransaction();

        const result = await callback(connection);

        await connection.commit();

        return result;

    } catch (error) {

        try {
            await connection.rollback();
        } catch (_) {}

        throw error;

    } finally {
        connection.release();
    }
}
```

Then business code becomes much safer:

```js
const result = await withTransaction(async (connection) => {

    const [wallet] = await connection.query(
        `
        SELECT w_id, balance
        FROM wallets
        WHERE user_id = ?
        FOR UPDATE
        `,
        [user_id]
    );

    // update wallet

    // insert transaction

    return result;
});
```

Now it's much harder for developers to accidentally leave a transaction open.

**But don't introduce this everywhere immediately.** Since you're preparing a deployment, let's fix the existing dangerous payment paths first.

---

# 17. One more important improvement to your `db.js`

Your current pool is okay, but I'd eventually add a transaction-age monitoring mechanism.

For example, Prometheus should tell you if transactions are staying open unusually long.

You already have Grafana/Prometheus, so this fits your architecture very well.

We can add metrics such as:

```text
rayon_mysql_transactions_active
rayon_mysql_transaction_oldest_seconds
rayon_mysql_pool_connections_in_use
rayon_mysql_pool_queue_total
```

Then Grafana can alert:

```text
MySQL transaction > 60 seconds
```

or:

```text
MySQL transaction > 5 minutes
```

For normal API requests, transactions should generally be **very short**.

---

# 18. Your immediate deployment checklist

Before deploying your new `subscriptionPayment`:

### Step 1 — Kill stale transactions

```sql
KILL 1059;
KILL 1060;
```

### Step 2 — Verify

```sql
SELECT
    trx_id,
    trx_started,
    TIMESTAMPDIFF(SECOND, trx_started, NOW()) AS age_seconds,
    trx_mysql_thread_id
FROM information_schema.innodb_trx;
```

### Step 3 — Verify metadata locks

```sql
SELECT
    OBJECT_SCHEMA,
    OBJECT_NAME,
    LOCK_TYPE,
    LOCK_DURATION,
    LOCK_STATUS,
    OWNER_THREAD_ID
FROM performance_schema.metadata_locks
WHERE OBJECT_SCHEMA = 'rayon-platform';
```

### Step 4 — Verify processlist

```sql
SHOW FULL PROCESSLIST;
```

### Step 5 — Deploy the corrected `subscriptionPayment`

### Step 6 — Restart your Node.js application

Because we want all old pooled connections discarded:

```bash
pm2 restart <your-app>
```

or whatever deployment mechanism you're using.

### Step 7 — Immediately check

```sql
SELECT
    trx_id,
    trx_state,
    trx_started,
    TIMESTAMPDIFF(SECOND, trx_started, NOW()) AS age_seconds,
    trx_mysql_thread_id
FROM information_schema.innodb_trx
ORDER BY trx_started;
```

You should see **no transaction that remains open for minutes**.

---

# 19. Documentation for your team

Save something like this as:

```text
docs/mysql-transaction-lock-incident.md
```

````md
# MySQL Transaction / Lock Incident

## Date

2026-08-20

## Database

rayon-platform

## Symptoms

- phpMyAdmin UPDATE operations became very slow
- Editing rows appeared to hang
- Some requests eventually failed
- MySQL showed metadata lock waits
- InnoDB showed transactions running for several hours
- Application connections appeared as `Sleep` while transactions remained `RUNNING`

## Root Cause

Application transactions were not always explicitly committed or rolled back before returning from controller functions.

Example:

BEGIN TRANSACTION

SELECT ...

if condition fails:
    return response

connection.release()

The connection was returned to the pool while the transaction remained open.

This allowed the connection to remain in `Sleep` state while InnoDB locks remained active.

## Evidence

Example:

Thread 1059:
- InnoDB transaction age: several hours
- PROCESSLIST: Sleep

Thread 1060:
- InnoDB transaction age: several hours
- PROCESSLIST: Sleep

## Important Principle

`connection.release()` does NOT replace:

COMMIT
or
ROLLBACK

Every transaction must end with:

COMMIT

or

ROLLBACK

before releasing the connection.

## Correct Pattern

```js
const connection = await db.promise().getConnection();

try {
    await connection.beginTransaction();

    // database operations

    await connection.commit();

} catch (error) {

    try {
        await connection.rollback();
    } catch (_) {}

    throw error;

} finally {

    connection.release();
}
````

## Rules

1. Never return from a transaction without COMMIT or ROLLBACK.
2. Always rollback before returning an error response.
3. Keep transactions short.
4. Do not call external APIs while holding database locks.
5. Use `FOR UPDATE` only when row locking is required.
6. Lock rows in a consistent order.
7. Do not keep transactions open while sending emails.
8. Do not keep transactions open while waiting for external payment providers.
9. Monitor `information_schema.innodb_trx`.
10. Monitor metadata locks through `performance_schema.metadata_locks`.

## Investigation Queries

### Active transactions

```sql
SELECT
    trx_id,
    trx_state,
    trx_started,
    TIMESTAMPDIFF(SECOND, trx_started, NOW()) AS age_seconds,
    trx_mysql_thread_id,
    trx_rows_locked,
    trx_rows_modified,
    trx_query
FROM information_schema.innodb_trx
ORDER BY trx_started;
```

### Process list

```sql
SELECT
    ID,
    USER,
    HOST,
    DB,
    COMMAND,
    TIME,
    STATE,
    INFO
FROM information_schema.PROCESSLIST
WHERE DB = 'rayon-platform'
ORDER BY TIME DESC;
```

### Metadata locks

```sql
SELECT
    OBJECT_SCHEMA,
    OBJECT_NAME,
    LOCK_TYPE,
    LOCK_DURATION,
    LOCK_STATUS,
    OWNER_THREAD_ID
FROM performance_schema.metadata_locks
WHERE OBJECT_SCHEMA = 'rayon-platform';
```

## Emergency Procedure

If a transaction is confirmed stale:

1. Identify the MySQL thread ID.
2. Confirm it belongs to the application.
3. Confirm it has been open for an abnormal period.
4. Kill the connection.

Example:

```sql
KILL 1059;
```

Then verify:

```sql
SELECT *
FROM information_schema.innodb_trx;
```

## Important

Do not randomly kill active production transactions.

First identify:

* thread ID
* application user
* host
* transaction age
* locked rows/tables
* current application operation

## Data Recovery

If data is accidentally deleted:

1. Check binary logging.
2. Check backups.
3. Do not restore directly over production.
4. Restore into a temporary database.
5. Recover only the required rows.
6. Check whether application audit logs exist.

Useful commands:

```sql
SHOW VARIABLES LIKE 'log_bin';

SHOW BINARY LOGS;

SHOW VARIABLES LIKE 'binlog_format';

SHOW VARIABLES LIKE 'binlog_expire_logs_seconds';
```

## Prevention

The application should eventually use a transaction helper:

```js
async function withTransaction(callback) {
    const connection = await db.promise().getConnection();

    try {
        await connection.beginTransaction();

        const result = await callback(connection);

        await connection.commit();

        return result;

    } catch (error) {

        try {
            await connection.rollback();
        } catch (_) {}

        throw error;

    } finally {
        connection.release();
    }
}
```

## Monitoring

Prometheus/Grafana should monitor:

* active MySQL connections
* pool queue
* transaction count
* oldest transaction age
* lock waits
* deadlocks

Recommended alert:

MySQL transaction older than 60 seconds.

Critical alert:

MySQL transaction older than 5 minutes.

````

---

## Most important conclusion

Your **MySQL pool configuration is not the main cause**.

The evidence points to **application-level transaction lifecycle problems**.

And we already found one concrete example in `subscriptionPayment`:

```js
await connection.beginTransaction();
````

followed by several possible:

```js
return error(...)
```

without rollback.

Your new `processPendingDeposit()` is much safer and follows the correct pattern.

**Before deploying, clean the two existing stale transactions (`1059` and `1060`), then deploy the fixed subscription code.** After deployment, we should audit the remaining controllers for the same pattern—especially `depositFunds`, `withdrawFunds`, `handlePaymentCallback`, `buyVideo`, and any other function using `beginTransaction()`.

The next step I recommend is **not changing the database settings**. We should audit every `beginTransaction()` in your backend and make sure **100% of them have guaranteed COMMIT/ROLLBACK paths**.
