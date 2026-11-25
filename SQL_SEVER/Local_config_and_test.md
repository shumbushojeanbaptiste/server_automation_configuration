# SQL Server Local Configuration

A short reference for the local SQL Server learning environment on
Windows.

## 1. Installed components

-   **Microsoft SQL Server Developer Edition** --- local database
    engine; free for development, testing, and learning only (not
    production).
-   **SQL Server Management Studio (SSMS)** --- administration, query
    execution, Activity Monitor, execution plans, and Extended Events.
-   **DBeaver Community Edition** --- optional SQL editor and database
    browser.
-   **Windows Performance Monitor (`perfmon`)** --- operating-system and
    SQL Server performance counters.

## 2. Connect to the local SQL Server

### SSMS

1.  Open SQL Server Management Studio.
2.  Select **Database Engine**.
3.  Server name: `localhost` (for a default instance).
4.  Authentication: **Windows Authentication**.
5.  Select **Connect**.

### DBeaver

Create a connection using the **SQL Server** driver:

  -----------------------------------------------------------------------
  Setting                             Value
  ----------------------------------- -----------------------------------
  Host                                `localhost`

  Port                                `1433` (if TCP/IP is enabled and
                                      configured on this port)

  Database                            `master`

  Authentication                      Windows Authentication or a
                                      configured SQL Server login
  -----------------------------------------------------------------------

If using TCP/IP, enable it in SQL Server Configuration Manager,
configure the TCP port under **IPAll**, and restart the SQL Server
service. Use the actual port configured on the machine. For Windows
Authentication, configure the appropriate authentication option/driver
in DBeaver.

## 3. Create the practice database

In SSMS, choose **New Query** and run:

``` sql
CREATE DATABASE PerformanceLab;
GO

USE PerformanceLab;
GO

CREATE TABLE Customers (
    CustomerID INT IDENTITY(1,1) PRIMARY KEY,
    FullName NVARCHAR(150) NOT NULL,
    Email NVARCHAR(150) UNIQUE,
    City NVARCHAR(100),
    CreatedAt DATETIME2 DEFAULT SYSDATETIME()
);
GO

CREATE TABLE Products (
    ProductID INT IDENTITY(1,1) PRIMARY KEY,
    ProductName NVARCHAR(150) NOT NULL,
    Price DECIMAL(18,2) NOT NULL,
    Stock INT NOT NULL DEFAULT 0
);
GO

CREATE TABLE Orders (
    OrderID INT IDENTITY(1,1) PRIMARY KEY,
    CustomerID INT NOT NULL,
    OrderDate DATETIME2 DEFAULT SYSDATETIME(),
    Status NVARCHAR(30) DEFAULT 'PENDING',
    CONSTRAINT FK_Orders_Customers
        FOREIGN KEY (CustomerID) REFERENCES Customers(CustomerID)
);
GO

CREATE TABLE OrderItems (
    OrderItemID INT IDENTITY(1,1) PRIMARY KEY,
    OrderID INT NOT NULL,
    ProductID INT NOT NULL,
    Quantity INT NOT NULL,
    UnitPrice DECIMAL(18,2) NOT NULL,
    CONSTRAINT FK_OrderItems_Orders
        FOREIGN KEY (OrderID) REFERENCES Orders(OrderID),
    CONSTRAINT FK_OrderItems_Products
        FOREIGN KEY (ProductID) REFERENCES Products(ProductID)
);
GO
```

## 4. Insert sample data

``` sql
USE PerformanceLab;
GO

INSERT INTO Customers (FullName, Email, City)
VALUES
('Jean Smith', 'jean@example.com', 'Kigali'),
('John Doe', 'john@example.com', 'Huye'),
('Alice Brown', 'alice@example.com', 'Musanze'),
('Peter Green', 'peter@example.com', 'Kigali'),
('Mary James', 'mary@example.com', 'Rubavu');

INSERT INTO Products (ProductName, Price, Stock)
VALUES
('Laptop', 850000, 20),
('Monitor', 250000, 35),
('Keyboard', 35000, 100),
('Mouse', 15000, 150),
('SSD 1TB', 120000, 50);

INSERT INTO Orders (CustomerID, Status)
VALUES
(1, 'COMPLETED'),
(2, 'PENDING'),
(3, 'COMPLETED'),
(1, 'PENDING'),
(4, 'COMPLETED');

INSERT INTO OrderItems (OrderID, ProductID, Quantity, UnitPrice)
VALUES
(1, 1, 1, 850000),
(1, 3, 2, 35000),
(2, 2, 1, 250000),
(3, 5, 2, 120000),
(4, 4, 3, 15000),
(5, 1, 2, 850000);
GO
```

## 5. Practice CRUD operations

Run these statements individually and inspect the results.

### Read

``` sql
USE PerformanceLab;
GO

SELECT * FROM Customers;
SELECT * FROM Products;

SELECT c.FullName, o.OrderID, o.Status, o.OrderDate
FROM Customers AS c
INNER JOIN Orders AS o ON o.CustomerID = c.CustomerID;

SELECT c.FullName,
       SUM(oi.Quantity * oi.UnitPrice) AS TotalAmount
FROM Customers AS c
JOIN Orders AS o ON o.CustomerID = c.CustomerID
JOIN OrderItems AS oi ON oi.OrderID = o.OrderID
GROUP BY c.FullName;
```

### Update

``` sql
UPDATE Products
SET Price = 900000
WHERE ProductID = 1;

UPDATE Orders
SET Status = 'COMPLETED'
WHERE OrderID = 2;
```

### Delete

The child row must be deleted before its parent order because of the
foreign-key relationship.

``` sql
DELETE FROM OrderItems
WHERE OrderItemID = 6;

DELETE FROM Orders
WHERE OrderID = 5;
```

### Insert

``` sql
INSERT INTO Customers (FullName, Email, City)
VALUES ('David Wilson', 'david@example.com', 'Kigali');
```

**Safety:** Always check the target rows with a `SELECT` before running
`UPDATE` or `DELETE`. Use a `WHERE` clause unless you intentionally want
to affect every row.

## 6. Monitor SQL Server

### Activity Monitor

In SSMS, right-click the connected server and select **Activity
Monitor**. Review:

-   Processes
-   Resource Waits
-   Data File I/O
-   Recent Expensive Queries

### Extended Events

In SSMS, expand **Management → Extended Events → Sessions**. Inspect
`system_health` and choose **Watch Live Data**. For new tracing work,
prefer Extended Events over SQL Server Profiler/SQL Trace, which are
deprecated for the relational database engine.

### SQL Server memory and CPU information

``` sql
SELECT
    cpu_count,
    scheduler_count,
    physical_memory_kb / 1024 AS PhysicalMemoryMB,
    committed_kb / 1024 AS CommittedMemoryMB
FROM sys.dm_os_sys_info;

SELECT
    physical_memory_in_use_kb / 1024 AS SQLMemoryMB,
    virtual_address_space_committed_kb / 1024 AS VirtualMemoryCommittedMB
FROM sys.dm_os_process_memory;
```

These queries show SQL Server process and host information; they are not
a substitute for a time-based CPU monitoring chart.

### Database file I/O statistics

``` sql
SELECT
    DB_NAME(vfs.database_id) AS DatabaseName,
    mf.name AS LogicalFileName,
    mf.type_desc AS FileType,
    vfs.num_of_reads AS Reads,
    vfs.num_of_writes AS Writes,
    vfs.io_stall_read_ms AS ReadStallMs,
    vfs.io_stall_write_ms AS WriteStallMs,
    CASE WHEN vfs.num_of_reads > 0
         THEN vfs.io_stall_read_ms / vfs.num_of_reads ELSE 0 END
         AS AvgReadLatencyMs,
    CASE WHEN vfs.num_of_writes > 0
         THEN vfs.io_stall_write_ms / vfs.num_of_writes ELSE 0 END
         AS AvgWriteLatencyMs
FROM sys.dm_io_virtual_file_stats(NULL, NULL) AS vfs
JOIN sys.master_files AS mf
  ON mf.database_id = vfs.database_id
 AND mf.file_id = vfs.file_id
ORDER BY ReadStallMs DESC;
```

These are cumulative counters since SQL Server started or the statistics
were reset. Take repeated samples and compare changes; do not interpret
lifetime totals as current activity.

### Find queries with high cumulative CPU

``` sql
SELECT TOP (20)
    qs.execution_count,
    qs.total_worker_time / 1000 AS TotalCPUTimeMs,
    qs.total_elapsed_time / 1000 AS TotalElapsedTimeMs,
    qs.total_logical_reads AS LogicalReads,
    qs.total_physical_reads AS PhysicalReads,
    st.text AS BatchText
FROM sys.dm_exec_query_stats AS qs
CROSS APPLY sys.dm_exec_sql_text(qs.sql_handle) AS st
ORDER BY qs.total_worker_time DESC;
```

These values describe cached query-plan statistics and accumulate over
executions. They are not necessarily a live ranking of currently running
queries.

### Windows Performance Monitor

1.  Press `Win + R`.
2.  Enter `perfmon`.
3.  Open **Performance Monitor** and add relevant counters, where
    available:

  Object                     Counter
  -------------------------- ---------------------------------------------
  Processor                  `% Processor Time`
  Memory                     `Available MBytes`
  PhysicalDisk               `Disk Reads/sec`, `Disk Writes/sec`
  PhysicalDisk               `Avg. Disk sec/Read`, `Avg. Disk sec/Write`
  SQLServer:SQL Statistics   `Batch Requests/sec`
  SQLServer:Buffer Manager   `Page life expectancy`

Counter names and SQL Server object names can vary by Windows language,
SQL Server instance, and installation. For a named instance, SQL Server
counters may include an instance-specific suffix.

## 7. Suggested next learning topics

1.  Joins, CTEs, subqueries, views, and stored procedures.
2.  Transactions, isolation levels, `COMMIT`, and `ROLLBACK`.
3.  Clustered and nonclustered indexes.
4.  Actual execution plans and query tuning.
5.  Extended Events and troubleshooting.
6.  CPU, memory, disk I/O, and wait statistics.
7.  Blocking, deadlocks, and concurrency.
8.  Backup, restore, and point-in-time recovery.
9.  Logins, users, roles, and permissions.
10. High availability and disaster recovery.

## 8. Useful official documentation

-   [SQL Server
    downloads](https://www.microsoft.com/en-us/sql-server/sql-server-downloads)
-   [Install SQL Server Management
    Studio](https://learn.microsoft.com/en-us/ssms/install/install)
-   [Monitor SQL Server
    components](https://learn.microsoft.com/en-us/sql/relational-databases/performance/monitor-sql-server-components)
-   [SQL Server Profiler
    documentation](https://learn.microsoft.com/en-us/sql/tools/sql-server-profiler/sql-server-profiler)
-   [SQL Server on Linux
    documentation](https://learn.microsoft.com/en-us/sql/linux/)

------------------------------------------------------------------------

**Lab database:** `PerformanceLab`\
**Default local server name:** `localhost`\
**Primary administration tool:** SSMS\
**Optional SQL client:** DBeaver
