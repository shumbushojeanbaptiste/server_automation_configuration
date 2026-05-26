Perfect — that VPS is more than enough for a real ELK + monitoring lab.

You have:

🧠 CPU: 8 cores → great for Elasticsearch
🧠 RAM: 31 GB → excellent (no need heavy optimizations)
🧪 Ideal for: full observability stack (ELK + Prometheus + Grafana)

So we can upgrade your setup from “lab mode” → real production-style observability stack.

🧱 FINAL ARCHITECTURE (YOUR VPS)
Flask App
MySQL
System Logs
   │
Filebeat
   │
Elasticsearch
   │
Kibana  ← search + dashboards

Plus:

Prometheus + Grafana (metrics layer already done)

So now you will have:

Layer	Tool
Metrics	Prometheus + Grafana
Logs	ELK (Filebeat + Elasticsearch + Kibana)
DB Monitoring	MySQL Exporter
System Monitoring	Node Exporter
🚀 NOW WE DO PROPER ELK INSTALL (OPTIMIZED FOR YOUR VPS)

Since your VPS is strong, we do full proper setup (not minimal).

STEP 1 — INSTALL ELASTICSEARCH (PRODUCTION READY)
Install
sudo apt update
sudo apt install -y elasticsearch
Configure
sudo nano /etc/elasticsearch/elasticsearch.yml

Set:

cluster.name: observability-cluster
node.name: node-1

network.host: 0.0.0.0
http.port: 9200

discovery.type: single-node

xpack.security.enabled: false
MEMORY (IMPORTANT FIX)

With 31GB RAM, we allocate properly.

Edit JVM:

sudo nano /etc/elasticsearch/jvm.options

Set:

-Xms4g
-Xmx4g

👉 This is SAFE for your VPS.

(We do NOT use 512MB anymore — that was for small VPS)

START
sudo systemctl enable elasticsearch
sudo systemctl start elasticsearch
VERIFY
curl http://localhost:9200
STEP 2 — INSTALL KIBANA
sudo apt install -y kibana
Configure
sudo nano /etc/kibana/kibana.yml

Set:

server.host: "0.0.0.0"
server.port: 5601
elasticsearch.hosts: ["http://localhost:9200"]
START
sudo systemctl enable kibana
sudo systemctl start kibana
OPEN
http://10.10.0.1:5601

(via VPN)

STEP 3 — INSTALL FILEBEAT (LOG SHIPPER)
sudo apt install -y filebeat
ENABLE ELASTIC OUTPUT

Edit:

sudo nano /etc/filebeat/filebeat.yml

Set:

output.elasticsearch:
  hosts: ["localhost:9200"]
STEP 4 — ENABLE LOG COLLECTION (IMPORTANT PART)

Now we collect 3 critical logs:

🔵 1. Flask Logs
- type: filestream
  id: flask-app
  enabled: true
  paths:
    - /home/VPN-LAB/app.log
🔴 2. MySQL Slow Logs
- type: filestream
  id: mysql-slow
  enabled: true
  paths:
    - /var/lib/mysql/*slow*.log
🟡 3. System Logs
- type: filestream
  id: system
  enabled: true
  paths:
    - /var/log/syslog
STEP 5 — START FILEBEAT
sudo systemctl enable filebeat
sudo systemctl restart filebeat
STEP 6 — VERIFY DATA IN ELASTICSEARCH
curl http://localhost:9200/_cat/indices?v

You should see:

filebeat-*
STEP 7 — OPEN KIBANA (IMPORTANT STEP)

Go:

Discover → Create Data View

Set:

filebeat-*
STEP 8 — WHAT YOU WILL SEE (REAL POWER)

Now Kibana can show:

🔥 Flask Logs
IP=10.10.0.2 /pay called
🔥 MySQL Slow Queries
SELECT * FROM orders WHERE ...
Execution time: 5 sec
🔥 System Activity
ssh login root
service restart mysql
🚀 FINAL RESULT (YOU BUILT ENTERPRISE STACK)

You now have:

📊 Metrics Layer
Prometheus
Grafana
📈 Logs Layer
ELK Stack
🧠 Database Monitoring
MySQL Exporter
Slow query logs
🖥 Server Monitoring
Node Exporter
🧠 WHAT YOU LEARNED (VERY IMPORTANT)

You now understand real DevOps architecture:

Problem	Tool
CPU/RAM issue	Node Exporter
API latency	Prometheus metrics
DB slow	MySQL exporter + slow logs
Debug request	ELK logs
Visualization	Grafana
Search logs	Kibana
👉 NEXT LEVEL (IF YOU WANT)

I can help you upgrade this into:

🔥 1. Alerting system (email/Telegram)
🔥 2. API Gateway (Nginx + VPN auth)
🔥 3. Distributed tracing (OpenTelemetry)
🔥 4. Real bank-grade architecture diagram
🔥 5. Auto dashboards for each service

Just tell me: “next level” 👍

Good — this is actually a normal and important change in Elasticsearch 8+.

You are not broken. Your ELK security is ON by default.

❗ WHY YOU SEE THIS ERROR
security_exception: missing authentication credentials

Because:

Elasticsearch 8.x enables security by default

So now it requires:

username
password
or API key
🧠 SIMPLE EXPLANATION

Before (older versions):

curl http://localhost:9200 → OK

Now (new versions):

curl → BLOCKED unless authenticated
🚀 STEP 1 — GET ELASTIC DEFAULT PASSWORD

Run:

sudo /usr/share/elasticsearch/bin/elasticsearch-reset-password -u elastic

It will output something like:

New password for user elastic: Xyz123....

👉 SAVE THIS PASSWORD

🚀 STEP 2 — TEST LOGIN WITH CURL

Now use:

curl -u elastic:YOUR_PASSWORD http://localhost:9200

Example:

curl -u elastic:MyStrongPass123 http://localhost:9200

If OK → you get cluster JSON response.

🚀 STEP 3 — TEST INDICES
curl -u elastic:YOUR_PASSWORD http://localhost:9200/_cat/indices?v
⚠️ IMPORTANT SECURITY NOTE

You now have:

Elasticsearch secured = production mode

Good for you because:

logs are sensitive (IPs, queries, access data)
must not be public
🚀 STEP 4 — (OPTIONAL BUT RECOMMENDED) DISABLE SECURITY FOR LAB

If this is ONLY your VPN lab and you want simplicity:

Edit:

sudo nano /etc/elasticsearch/elasticsearch.yml

Add:

xpack.security.enabled: false

Then restart:

sudo systemctl restart elasticsearch

Now:

curl http://localhost:9200

works without password.

🧠 WHICH OPTION SHOULD YOU USE?
Mode	Recommendation
Learning / Lab	disable security (simpler)
Production / Bank-style	keep security ON
🚀 WHAT I RECOMMEND FOR YOU

Since you are building:

VPN system
banking integration simulation
monitoring stack

👉 KEEP SECURITY ON (best learning value)

🔥 NEXT STEP (IMPORTANT)

After this works, I will guide you:

👉 Kibana login setup
👉 Filebeat authentication config
👉 Secure log pipeline (bank-grade style)
👉 Dashboard for:
API access by IP
slow queries timeline
system anomalies

Just say:

next kibana login

and we continue step-by-step 👍

good, now logged in to kibana,
help to view those logs

Perfect — now you’re inside Kibana 🎯
This is where your ELK stack becomes powerful.

Let’s go step by step to see your Flask logs, MySQL slow logs, and system logs.

🧠 FIRST IMPORTANT CONCEPT

Kibana does NOT show logs automatically.

You must create a:

Data View (Index Pattern)
🚀 STEP 1 — CREATE DATA VIEW

Go to:

☰ Menu → Stack Management → Data Views

Click:

Create data view
Fill:
Name: filebeat-logs
Index pattern: filebeat-*

Click:

Save
🚀 STEP 2 — OPEN LOG VIEW (DISCOVER)

Go:

☰ Menu → Discover

At top-left select:

filebeat-logs

Now you should see logs flowing.

🚀 STEP 3 — IF YOU SEE EMPTY SCREEN (VERY COMMON)

Do this:

1. Change time range (IMPORTANT)

Top-right:

Last 15 minutes → change to Last 24 hours

OR:

Last 7 days
2. Refresh logs

Click:

🔄 Refresh
🚀 STEP 4 — FILTER YOUR LOG TYPES

Now you can search:

🔵 Flask logs

In search bar:

flask

OR:

app.log

OR IP-based:

10.10.0.2
🔴 MySQL slow queries

Search:

SLEEP

OR:

SELECT

OR:

slow
🟡 System logs

Search:

ssh

OR:

error
🚀 STEP 5 — MAKE LOGS MORE CLEAR (IMPORTANT)

Click any log entry → you will see fields like:

message
log.file.path
host.name
agent.name
@timestamp

This helps you understand:

who did what, when, and from where
🚀 STEP 6 — CREATE FILTERS (VERY USEFUL)

You can click:

Add filter

Example:

Filter Flask logs only:
field: log.file.path
value: app.log
🚀 STEP 7 — REAL WORLD USE CASES

Now you can investigate like a bank system:

🔥 Example 1 — Who accessed API

Search:

/flask endpoint

You see:

IP address
request time
endpoint
🔥 Example 2 — Slow DB query

Search:

SLEEP

You see:

SQL query
execution time
source
🔥 Example 3 — Suspicious login

Search:

ssh

You see:

login attempts
IP
time
🚀 STEP 8 — OPTIONAL (MAKE IT BEAUTIFUL)

Go:

Visualize Library → Lens

Create charts like:

📊 Flask requests over time
📊 MySQL slow queries trend
📊 system errors
🧠 WHAT YOU BUILT NOW

You now have FULL observability:

Layer	Tool
Metrics	Grafana
Logs	Kibana
DB	MySQL exporter
System	Node exporter
API	Flask logs
🚀 NEXT LEVEL (RECOMMENDED)

If you want real enterprise upgrade, next I can help you:

🔥 1. Dashboard like bank monitoring screen
🔥 2. Alert when:
API slow
DB slow
login failure
🔥 3. Correlate logs + metrics (very powerful)
🔥 4. Secure Kibana behind VPN only (production style)

Just say:

next level kibana dashboard
good, now all work,
give me clear step guide to achieve it for future

all processes, 
Complete Beginner-to-Observability Lab Guide

You successfully built a real monitoring and logging environment on your VPS.
This guide summarizes the full workflow clearly so you can reproduce it in future projects.

🏗️ FINAL ARCHITECTURE
Laptop
   │
WireGuard VPN
   │
VPS (Hostinger)
   │
├── Flask App
├── MySQL
├── Prometheus
├── Grafana
├── Node Exporter
├── MySQL Exporter
├── Elasticsearch
├── Kibana
└── Filebeat
🔐 PART 1 — VPN SETUP (WireGuard)

Purpose:

secure internal access
private monitoring network
simulate bank integration
Install WireGuard
sudo apt update
sudo apt install wireguard -y
Generate Keys
Server
wg genkey | tee server_private.key | wg pubkey > server_public.key
Laptop/client
wg genkey | tee client_private.key | wg pubkey > client_public.key
Server Config

File:

/etc/wireguard/wg0.conf

Example:

[Interface]
Address = 10.10.0.1/24
ListenPort = 51820
PrivateKey = SERVER_PRIVATE_KEY

[Peer]
PublicKey = CLIENT_PUBLIC_KEY
AllowedIPs = 10.10.0.2/32
Start VPN
sudo systemctl enable wg-quick@wg0
sudo systemctl start wg-quick@wg0
Laptop Config
[Interface]
Address = 10.10.0.2/24
PrivateKey = CLIENT_PRIVATE_KEY

[Peer]
PublicKey = SERVER_PUBLIC_KEY
Endpoint = VPS_PUBLIC_IP:51820
AllowedIPs = 10.10.0.0/24
PersistentKeepalive = 25
Test
ping 10.10.0.1
🌐 PART 2 — FLASK APP

Purpose:

sample backend
metrics source
log source
Install Python Environment
sudo apt install python3-venv -y
Create Virtual Environment
python3 -m venv venv
source venv/bin/activate
Install Packages
pip install flask prometheus_client
Sample Flask App
from flask import Flask
from prometheus_client import Counter, generate_latest
from flask import Response

app = Flask(__name__)

REQUESTS = Counter('app_requests_total', 'Total requests')

@app.route('/')
def home():
    REQUESTS.inc()
    return "Hello VPN Monitoring Lab"

@app.route('/metrics')
def metrics():
    return Response(generate_latest(), mimetype='text/plain')

app.run(host='10.10.0.1', port=5500)
Run App
python app.py
📊 PART 3 — PROMETHEUS

Purpose:

collect metrics
Download Prometheus
wget https://github.com/prometheus/prometheus/releases/download/...tar.gz

Extract.

Config

File:

prometheus.yml

Example:

global:
  scrape_interval: 5s

scrape_configs:
  - job_name: "flask-app"
    static_configs:
      - targets: ["10.10.0.1:5500"]

  - job_name: "node-exporter"
    static_configs:
      - targets: ["localhost:9100"]

  - job_name: "mysql"
    static_configs:
      - targets: ["localhost:9104"]
Start
./prometheus --config.file=prometheus.yml --web.listen-address="0.0.0.0:9090"
📈 PART 4 — GRAFANA

Purpose:

visualize metrics
Install
sudo apt install grafana -y
Change Port

File:

/etc/grafana/grafana.ini

Set:

http_port = 3001
Start
sudo systemctl enable grafana-server
sudo systemctl start grafana-server
Open
http://10.10.0.1:3001
Add Data Source
Prometheus
URL:
http://localhost:9090
🖥️ PART 5 — NODE EXPORTER

Purpose:

monitor VPS/server
Download
wget https://github.com/prometheus/node_exporter/releases/download/...tar.gz
Run
./node_exporter
Metrics

Provides:

CPU
RAM
disk
network
uptime
Grafana Dashboard

Import:

1860
🗄️ PART 6 — MYSQL EXPORTER

Purpose:

monitor database
Create Monitoring User
CREATE USER 'exporter'@'localhost' IDENTIFIED BY 'password';
GRANT PROCESS, REPLICATION CLIENT, SELECT ON *.* TO 'exporter'@'localhost';
FLUSH PRIVILEGES;
Configure

.my.cnf

[client]
user=exporter
password=password
Run Exporter
./mysqld_exporter --config.my-cnf=.my.cnf
Metrics

Provides:

connections
QPS
slow query counts
InnoDB metrics
🐢 PART 7 — MYSQL SLOW QUERY LOG

Purpose:

capture slow SQL
Enable
SET GLOBAL slow_query_log = ON;
SET GLOBAL long_query_time = 2;
SET GLOBAL log_queries_not_using_indexes = ON;
Persistent Config

File:

/etc/mysql/mysql.conf.d/mysqld.cnf

Add:

slow_query_log = 1
long_query_time = 2
log_queries_not_using_indexes = 1
Test
SELECT SLEEP(5);
View Logs
tail -f /var/lib/mysql/*slow*.log
🔍 PART 8 — ELK STACK

Purpose:

centralized logs
Elasticsearch
Install
sudo apt install elasticsearch -y
Config
network.host: 0.0.0.0
discovery.type: single-node
Start
sudo systemctl enable elasticsearch
sudo systemctl start elasticsearch
Kibana
Install
sudo apt install kibana -y
Config
server.host: "0.0.0.0"
server.port: 5601
Start
sudo systemctl enable kibana
sudo systemctl start kibana
Open
http://10.10.0.1:5601
📦 PART 9 — FILEBEAT

Purpose:

send logs to Elasticsearch
Install
sudo apt install filebeat -y
Configure Inputs

File:

/etc/filebeat/filebeat.yml

Add:

filebeat.inputs:

- type: filestream
  id: flask-app
  enabled: true
  paths:
    - /home/VPN-LAB/app.log

- type: filestream
  id: mysql-slow
  enabled: true
  paths:
    - /var/lib/mysql/*slow*.log

- type: filestream
  id: system
  enabled: true
  paths:
    - /var/log/syslog
Elasticsearch Output
output.elasticsearch:
  hosts: ["localhost:9200"]
Start
sudo systemctl enable filebeat
sudo systemctl restart filebeat
🔎 PART 10 — VIEW LOGS IN KIBANA
Create Data View
filebeat-*
Discover

View:

Flask logs
MySQL slow queries
system logs
access IPs
🔥 FINAL REAL-WORLD SKILLS YOU LEARNED
Skill	Result
VPN	secure internal network
Prometheus	metrics collection
Grafana	dashboards
Node Exporter	server monitoring
MySQL Exporter	DB monitoring
Slow Query Logs	SQL debugging
ELK	centralized logging
Kibana	log investigation
🧠 REAL DEVOPS TROUBLESHOOTING FLOW

When app becomes slow:

Check	Tool
CPU/RAM	Node Exporter
API latency	Prometheus
DB slow	MySQL Exporter
Exact SQL	Slow query log
Request logs	Kibana
IP tracking	ELK