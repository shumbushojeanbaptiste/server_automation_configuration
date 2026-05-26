# Monitoring Setup — Prometheus, Grafana, ELK (Step-by-step Beginner Guide)

This guide documents how to complete your monitoring stack on a VPS: instrument a Flask app, collect metrics with Prometheus, visualize with Grafana, and ship logs (slow query, app logs, syslog) to Elasticsearch + Kibana via Filebeat. It also includes a lighter Loki alternative.

Prerequisites
- VPS with root/sudo access (Ubuntu/Debian recommended)
- Your Flask app is running in production on the VPS (you already have this)
- MySQL running and you can access its root or a user with PROCESS/REPLICATION CLIENT privileges
- Firewall: allow internal access between services. Avoid exposing Elasticsearch/Kibana to the public.
- Check RAM: `free -h` (2GB is tight, 4GB+ recommended)

Overview
Flask app (app.log)
MySQL (slow log)
Linux syslog
  └── Filebeat → Elasticsearch → Kibana
Prometheus ← exporters (node, mysqld, app metrics)
Grafana → Prometheus (time-series) and Kibana/Loki (logs)

Quick verification commands (run on VPS)
```bash
free -h
sudo ss -ltnp | egrep '9090|3000|9200|5601|9100|9306'
curl -sS http://localhost:9090/-/ready
curl -sS http://localhost:9200
```

Part A — Prepare application logs and MySQL slow log

1. Verify Flask app logs
- Confirm your Flask app writes a file (example: `app.log` beside `app.py`).
- Test:
```bash
tail -n 50 /path/to/your/app.log
logger "test log entry from $(whoami)"
tail -n 5 /path/to/your/app.log
```

2. Enable MySQL slow query log (temporary + persistent)
- Temporary (immediate):
```sql
-- connect with mysql client as root
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL slow_query_log_file = '/var/log/mysql/mysql-slow.log';
SET GLOBAL long_query_time = 1; -- seconds
```
- Permanent (edit MySQL config): edit `/etc/mysql/mysql.conf.d/mysqld.cnf` (or `/etc/mysql/my.cnf`) and add under `[mysqld]`:
```
slow_query_log = 1
slow_query_log_file = /var/log/mysql/mysql-slow.log
long_query_time = 1
log_output = FILE
```
- Reload MySQL:
```bash
sudo systemctl restart mysql
sudo tail -f /var/log/mysql/mysql-slow.log
```

Part B — Prometheus + exporters (metrics)

1. Install node_exporter (system metrics)
```bash
# download latest linux amd64 binary (adjust version)
wget https://github.com/prometheus/node_exporter/releases/download/v1.6.1/node_exporter-1.6.1.linux-amd64.tar.gz
tar xzf node_exporter-*.tar.gz
sudo cp node_exporter-*/node_exporter /usr/local/bin/
# create systemd service
cat <<'EOF' | sudo tee /etc/systemd/system/node_exporter.service
[Unit]
Description=Node Exporter
After=network.target

[Service]
User=nobody
ExecStart=/usr/local/bin/node_exporter

[Install]
WantedBy=default.target
EOF
sudo systemctl daemon-reload
sudo systemctl enable --now node_exporter
```

2. Install mysqld_exporter (MySQL metrics)
- Create a MySQL user for exporter:
```sql
CREATE USER 'exporter'@'localhost' IDENTIFIED BY 'your_password_here';
GRANT PROCESS, REPLICATION CLIENT, SELECT ON *.* TO 'exporter'@'localhost';
FLUSH PRIVILEGES;
```
- Download exporter and configure systemd (set `DATA_SOURCE_NAME` env to `exporter:password@(localhost:3306)/`)

3. Prometheus server
- Install Prometheus, configure `prometheus.yml` to scrape `node_exporter`, `mysqld_exporter`, and your Flask app metrics (if you expose `/metrics`). Example snippet:
```yaml
scrape_configs:
  - job_name: 'node'
    static_configs:
      - targets: ['localhost:9100']
  - job_name: 'mysqld'
    static_configs:
      - targets: ['localhost:9104']
  - job_name: 'flask'
    static_configs:
      - targets: ['localhost:5500']
```
- Start Prometheus and verify targets at `http://<vps>:9090/targets`.

Part C — Grafana (visualization)

1. Install Grafana (apt on Debian/Ubuntu)
```bash
sudo apt install -y apt-transport-https software-properties-common wget
wget -q -O - https://packages.grafana.com/gpg.key | sudo apt-key add -
echo "deb https://packages.grafana.com/oss/deb stable main" | sudo tee /etc/apt/sources.list.d/grafana.list
sudo apt update
sudo apt install grafana -y
sudo systemctl enable --now grafana-server
```
2. Access Grafana at `http://<vps>:3000` (default admin:admin).
3. Add Prometheus data source (URL `http://localhost:9090`).
4. Import dashboards: there are community dashboards for Node Exporter and MySQL Exporter (use dashboard IDs from Grafana.com).

Part D — ELK (Filebeat → Elasticsearch → Kibana)

Important: Elasticsearch is memory hungry. If your VPS has <4GB RAM, consider using Grafana Loki (lighter) or a hosted Elasticsearch service.

1. Check RAM:
```bash
free -h
```

2. Install Java (if required by Elasticsearch version):
```bash
sudo apt update
sudo apt install -y openjdk-17-jdk
java -version
```

3. Add Elastic APT repo and install Elasticsearch & Kibana
```bash
wget -qO - https://artifacts.elastic.co/GPG-KEY-elasticsearch | sudo apt-key add -
echo "deb https://artifacts.elastic.co/packages/8.x/apt stable main" | sudo tee /etc/apt/sources.list.d/elastic-8.x.list
sudo apt update
sudo apt install -y elasticsearch kibana
```

4. Configure Elasticsearch for a single node
- Edit `/etc/elasticsearch/elasticsearch.yml` and set:
```
network.host: 127.0.0.1    # or 0.0.0.0 for internal access (avoid public)
http.port: 9200
discovery.type: single-node
xpack.security.enabled: false  # for lab only; secure in production
```
- Lower JVM memory (very important for small VPS)
  - Edit `/etc/elasticsearch/jvm.options` or `/etc/elasticsearch/jvm.options.d/` and change `-Xms` and `-Xmx` to `512m`.

5. Start Elasticsearch and verify
```bash
sudo systemctl daemon-reload
sudo systemctl enable --now elasticsearch
curl -sS http://localhost:9200 | jq '.'
```

6. Configure Kibana
- Edit `/etc/kibana/kibana.yml`:
```
server.host: "0.0.0.0"
server.port: 5601
elasticsearch.hosts: ["http://localhost:9200"]
```
- Start Kibana:
```bash
sudo systemctl enable --now kibana
```
- Access `http://<vps>:5601` and create an index pattern for Filebeat indices.

7. Install Filebeat and configure to ship logs
```bash
sudo apt install filebeat
```
- Minimal `filebeat.yml` snippet (adjust paths):
```yaml
filebeat.inputs:
- type: log
  enabled: true
  paths:
    - /path/to/your/app.log
    - /var/log/mysql/mysql-slow.log
    - /var/log/syslog

output.elasticsearch:
  hosts: ["localhost:9200"]

setup.kibana:
  host: "localhost:5601"
```
- Enable and start Filebeat:
```bash
sudo filebeat modules enable system
sudo systemctl enable --now filebeat
```
- Verify indices:
```bash
curl -sS 'http://localhost:9200/_cat/indices?v'
```

8. Create visualizations in Kibana
- In Kibana, go to "Discover" and select the Filebeat index pattern (e.g., `filebeat-*`).
- Search for `mysql-slow.log` or your app log messages.

Part E — Simpler logs option: Grafana Loki + Promtail (lighter alternative)
- Loki is much lighter than Elasticsearch and integrates directly with Grafana.
- Install Loki and Promtail (binaries or via Docker). Configure `promtail` to tail `app.log` and `mysql-slow.log`, and set output to Loki. In Grafana add Loki as a data source and you can view logs alongside metrics.

Promtail example `promtail.yml` input (very simple):
```yaml
server:
  http_listen_port: 9080
positions:
  filename: /tmp/positions.yaml
clients:
  - url: http://localhost:3100/loki/api/v1/push
scrape_configs:
- job_name: system
  static_configs:
  - targets: [localhost]
    labels:
      job: varlogs
      __path__: /path/to/your/app.log
- job_name: mysql_slow
  static_configs:
  - targets: [localhost]
    labels:
      job: mysql_slow
      __path__: /var/log/mysql/mysql-slow.log
```

Part F — Verification & troubleshooting
- Check service status:
```bash
sudo systemctl status elasticsearch kibana filebeat prometheus grafana-server node_exporter mysqld_exporter
```
- Test Prometheus target scrape:
```bash
curl -sS http://localhost:9090/targets | jq '.'
```
- Test Elasticsearch connectivity:
```bash
curl -sS http://localhost:9200
```
- Check Filebeat logs for shipping errors:
```bash
sudo journalctl -u filebeat -f
```

Security notes
- Do not expose Elasticsearch or Kibana publicly without authentication.
- Use firewall rules to restrict access: e.g., block 9200/5601 from public, allow only your IP or localhost.
- In production, enable xpack security and use TLS.

Next steps (recommended)
- Configure Grafana alerting (via Alertmanager or Grafana notifications).
- Add OpenTelemetry tracing for request-level traces.
- Add retention policies for Elasticsearch to avoid disk exhaustion.

Files & sample snippets I created in this workspace
- `app.py` — your Flask app now logs to `app.log` next to the script.
- `monitoring_setup_step_by_step.md` — this guide (created).

If you want, I can:
- Generate systemd unit files for `mysqld_exporter` and `prometheus` (I can create them here),
- Create a `filebeat.yml` ready-to-deploy with your exact paths,
- Or generate a compact Loki+Promtail setup instead of Elasticsearch.

What would you like me to do next? (generate configs / create units / produce Filebeat or Promtail YAML)
