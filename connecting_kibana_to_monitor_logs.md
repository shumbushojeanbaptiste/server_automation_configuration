Title: Connect Kibana to Elasticsearch — concise setup & troubleshooting

Purpose
This document explains the minimal, correct steps to configure Kibana so it can talk to Elasticsearch on the same VPS and how to troubleshoot "not listening on port 5601" and authentication errors.

Prerequisites
- Elasticsearch is running on the VPS and reachable at http://localhost:9200.
- You know the elastic user password (or can create an API key / enrollment token).

Quick checklist
- Elasticsearch health: `curl -u elastic:<password> http://localhost:9200`
- Kibana config file: `/etc/kibana/kibana.yml`
- Kibana service: `systemctl status kibana`
- Listening port: `ss -tulnp | grep 5601`

Step 1 — Verify Elasticsearch is up and auth method
1. Test Elasticsearch HTTP API:
```bash
curl -u elastic:<password> http://localhost:9200
```
2. If you get JSON with cluster info → use direct credentials in Kibana. If you get a `security_exception` and cannot authenticate, reset or obtain the correct `elastic` password.

Step 2 — Choose configuration method (pick one)
- Enrollment token (preferred when enabled):
  - On the Elasticsearch host: `/usr/share/elasticsearch/bin/elasticsearch-create-enrollment-token -s kibana`
  - Then run Kibana setup with the token: `/usr/share/kibana/bin/kibana-setup --enrollment-token <token>`
- Direct username/password (when enrollment disabled): add credentials to `kibana.yml` as below.

Step 3 — Minimal `kibana.yml` for same-host setup
Open `/etc/kibana/kibana.yml` and ensure it contains (replace placeholders):
```yaml
server.port: 5601
server.host: "0.0.0.0"        # or 127.0.0.1 for local-only
elasticsearch.hosts: ["http://localhost:9200"]
elasticsearch.username: "elastic"
elasticsearch.password: "<ELASTIC_PASSWORD>"
```

Step 4 — Restart Kibana and verify
```bash
sudo systemctl daemon-reload
sudo systemctl restart kibana
sudo systemctl status kibana --no-pager
sudo ss -tulnp | grep 5601
```
Expected: `ss` shows a process listening on port `5601` (Kibana).

Step 5 — If Kibana is not listening or shows errors
- Check journal logs (live):
  ```bash
  sudo journalctl -u kibana -f --no-pager
  ```
- Check Kibana log file:
  ```bash
  sudo tail -n 200 /var/log/kibana/kibana.log
  ```
- Common root causes and fixes:
  - `security_exception` / missing credentials: set `elasticsearch.username` + `elasticsearch.password` in `kibana.yml` (or use enrollment token).
  - Enrollment token disabled: use direct credentials or enable enrollment on Elasticsearch.
  - YAML syntax error: ensure correct indentation and no stray characters; inspect with `sudo cat /etc/kibana/kibana.yml`.
  - Port already used: `sudo lsof -i :5601` and stop the conflicting service.
  - Kibana process failing early: run Kibana in foreground for full output:
    ```bash
    sudo -u kibana /usr/share/kibana/bin/kibana --config /etc/kibana/kibana.yml
    ```

Step 6 — Firewall and access
- If `ss` shows Kibana listening on `127.0.0.1:5601` but you need external access, change `server.host` to `0.0.0.0` or your VPS IP and open port `5601` in the firewall:
```bash
sudo ufw allow 5601/tcp
sudo ufw status
```
- For production, restrict source IPs (example: only your IP):
```bash
sudo ufw allow from <your_ip>/32 to any port 5601 proto tcp
```

Step 7 — Final verification from your workstation
- In a browser open: `http://<vps-ip>:5601`
- Login using the `elastic` user and password provided in `kibana.yml` (if prompted).

Quick troubleshooting commands
```bash
# Elasticsearch health
curl -u elastic:<password> http://localhost:9200

# Verify Kibana config syntax (verbose run)
sudo -u kibana /usr/share/kibana/bin/kibana --config /etc/kibana/kibana.yml --verify-config

# Live Kibana logs
sudo journalctl -u kibana -f --no-pager

# Check listening ports
sudo ss -tulnp | grep 5601
```

Security note
- Do not place `elasticsearch.password` in world-readable files on shared systems. In production use the Kibana keystore or environment-based secrets, enable TLS and secure authentication.

Next steps (I can do for you)
- Generate a ready `kibana.yml` populated with values you confirm (I won't store the password), or
- Create a small script to install/configure Kibana + Filebeat and start services.
