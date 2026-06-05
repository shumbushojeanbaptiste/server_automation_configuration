[mysqld]
datadir = /var/lib/mysql
socket = /var/lib/mysql/mysql.sock
slow_query_log = 1
slow_query_log_file = /var/lib/mysql/srv1158437-slow.log
long_query_time = 2
log_queries_not_using_indexes = 1

[client]
socket = /var/lib/mysql/mysql.sock
bind-address = 127.0.0.1

