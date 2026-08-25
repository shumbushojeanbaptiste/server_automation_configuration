Absolutely. Since you want this as a **real production practice**, let's build it as a repeatable runbook rather than just giving you commands.

Your current environment already has:

```text
Ubuntu 24.04 VPS
K3s
rayon-plus namespace
rayon-plus Node.js application
Elasticsearch 8.19.18
Kibana 8.19.18
Filebeat 8.19.18
Prometheus + Grafana
```

And your desired architecture is:

```text
┌─────────────────────────────────────────────────────────┐
│                    K3s VPS                              │
│                                                         │
│  Rayon-Plus                                             │
│      │                                                  │
│      │ stdout / stderr                                  │
│      ▼                                                  │
│  /var/log/pods/                                         │
│  /var/log/containers/                                   │
│      │                                                  │
│      │ Filebeat                                         │
│      ▼                                                  │
│  Elasticsearch :9200                                    │
│      │                                                  │
│      ▼                                                  │
│  Kibana :5601                                           │
│      │                                                  │
│      ▼                                                  │
│  Browser / Dashboard                                    │
└─────────────────────────────────────────────────────────┘

Prometheus ─────────► Grafana
     │
     └──── metrics

MySQL audit_log ─────► Business audit history
```

Below is the **complete runbook** I recommend you keep for future applications.

---

# Phase 0 — Understand what you are connecting

Before installing anything, always identify these three things.

### Elasticsearch

```bash
curl http://localhost:9200
```

Expected:

```json
{
  "name": "node-1",
  "cluster_name": "my-cluster",
  "version": {
    "number": "8.19.18"
  }
}
```

Elasticsearch is the **log storage/search engine**.

---

### Kibana

```bash
curl -I http://localhost:5601
```

Expected something like:

```text
HTTP/1.1 302 Found
location: /spaces/enter
```

Kibana is the **UI**.

---

### Filebeat

```bash
filebeat version
```

Expected:

```text
filebeat version 8.19.18
```

Filebeat is the **collector/shipper**.

---

# Phase 1 — Find where Kubernetes stores application logs

This is one of the most important things to understand.

Run:

```bash
ls -lah /var/log/containers/
```

You already discovered:

```text
rayon-plus-84c64d4ff6-z29x7_rayon-plus_rayon-plus-....log
```

Now inspect the symlink:

```bash
ls -lah /var/log/containers/ | grep rayon-plus
```

You will see something like:

```text
... -> /var/log/pods/rayon-plus_rayon-plus-.../rayon-plus/0.log
```

Therefore:

```text
Kubernetes
   │
   ├── /var/log/containers/*.log
   │
   └── /var/log/pods/.../0.log
```

The `/var/log/containers` files are normally symlinks.

---

# Phase 2 — Verify the actual Rayon logs

Run:

```bash
kubectl logs -n rayon-plus deployment/rayon-plus --tail=20
```

Then:

```bash
tail -20 /var/log/containers/rayon-plus-*.log
```

You should see the same type of information:

```text
[STARTUP] MongoDB ready.
[SERVER_STARTED] {...}
[MYSQL_READY] {...}
[REDIS_READY] {...}
```

This proves:

```text
Node.js
   ↓
stdout
   ↓
Kubernetes
   ↓
/var/log/containers
```

---

# Phase 3 — Configure Filebeat

Your current Filebeat configuration is already very close to what we want.

For a clean future configuration, use:

```yaml
filebeat.inputs:

  - type: filestream
    id: rayon-plus-kubernetes-logs
    enabled: true

    paths:
      - /var/log/containers/rayon-plus-*_rayon-plus_rayon-plus-*.log

    prospector.scanner.symlinks: true
    follow_symlinks: true

    parsers:
      - container:
          format: auto

    fields:
      service_name: rayon-plus
      environment: production
      application: rayon-plus
      log_source: kubernetes

    fields_under_root: true


output.elasticsearch:
  hosts: ["http://localhost:9200"]
  preset: balanced


setup.kibana:
  host: "http://localhost:5601"


processors:
  - add_host_metadata: ~
```

Save:

```bash
nano /etc/filebeat/filebeat.yml
```

---

# Phase 4 — Always validate before starting

This is an important production habit.

Run:

```bash
sudo filebeat test config -c /etc/filebeat/filebeat.yml
```

Expected:

```text
Config OK
```

Then:

```bash
sudo filebeat test output -c /etc/filebeat/filebeat.yml
```

Expected:

```text
elasticsearch: http://localhost:9200...
connection...
talk to server... OK
version: 8.19.18
```

Only after those succeed:

```bash
sudo systemctl enable filebeat
sudo systemctl restart filebeat
```

Then:

```bash
sudo systemctl status filebeat --no-pager -l
```

Expected:

```text
Active: active (running)
```

---

# Phase 5 — Verify Filebeat is actually reading Rayon

This is important.

Run:

```bash
sudo journalctl -u filebeat -f
```

Then generate a new application log.

For example, restart one pod:

```bash
kubectl rollout restart deployment/rayon-plus -n rayon-plus
```

Watch:

```bash
kubectl logs -n rayon-plus deployment/rayon-plus -f
```

You should see:

```text
[STARTUP]
[MYSQL_READY]
[REDIS_READY]
...
```

Filebeat should read those records.

---

# Phase 6 — Verify Elasticsearch

Now check indices:

```bash
curl -s 'http://localhost:9200/_cat/indices?v'
```

You should see something similar to:

```text
.ds-filebeat-8.19.18-2026.08.24-000001
```

Now check documents:

```bash
curl -s 'http://localhost:9200/_search?pretty&size=1'
```

You should eventually see:

```json
{
  "_source": {
    "application": "rayon-plus",
    "environment": "production",
    "service_name": "rayon-plus",
    "message": "..."
  }
}
```

---

# Phase 7 — Create the Kibana Data View

Open Kibana.

Go to:

```text
Stack Management
       ↓
Data Views
       ↓
Create data view
```

Use:

```text
filebeat-*
```

For timestamp field select:

```text
@timestamp
```

Create it.

Now Kibana knows:

> "When I search `filebeat-*`, show me Filebeat log data."

---

# Phase 8 — Open Discover

Go to:

```text
Analytics
   ↓
Discover
```

Select:

```text
filebeat-*
```

Set time range:

```text
Last 24 hours
```

Now you should see your Rayon logs.

Filter:

```text
application : "rayon-plus"
```

You should see only Rayon-Plus events.

You can also search:

```text
service_name : "rayon-plus"
```

or:

```text
log_source : "kubernetes"
```

---

# Phase 9 — Your first real observation test

Now let's prove the entire pipeline.

Create a temporary endpoint in your Node application:

```javascript
app.get('/test-observability', (req, res) => {
    console.log(JSON.stringify({
        event: 'OBSERVABILITY_TEST',
        service: 'rayon-plus',
        environment: 'production',
        message: 'Observability pipeline test'
    }));

    res.json({
        success: true
    });
});
```

Deploy it.

Then call:

```bash
curl http://YOUR_API/test-observability
```

The complete journey is:

```text
HTTP request
      ↓
Node.js
      ↓
console.log()
      ↓
stdout
      ↓
Kubernetes
      ↓
/var/log/containers
      ↓
Filebeat
      ↓
Elasticsearch
      ↓
Kibana
      ↓
Discover
```

If you can find:

```text
OBSERVABILITY_TEST
```

in Kibana, **you have successfully built the pipeline.**

---

# Phase 10 — Improve your production log format

Once the pipeline works, don't immediately change everything.

First establish a standard.

For Node.js:

```javascript
console.log(JSON.stringify({
    event: 'LOGIN_SUCCESS',
    userId: user.id,
    service: 'rayon-plus',
    environment: 'production',
    message: 'User login successful'
}));
```

For errors:

```javascript
console.error(JSON.stringify({
    event: 'DATABASE_ERROR',
    service: 'rayon-plus',
    environment: 'production',
    message: error.message
}));
```

Eventually your logs should look like:

```json
{
  "event": "LOGIN_SUCCESS",
  "userId": 123,
  "service": "rayon-plus",
  "environment": "production",
  "message": "User login successful"
}
```

Then Kibana can search individual fields instead of searching through giant text messages.

---

# Phase 11 — The same architecture for FastAPI

Suppose tomorrow you deploy:

```text
payment-api
```

using FastAPI.

Its pod writes:

```text
stdout/stderr
```

Kubernetes creates:

```text
/var/log/containers/payment-api-....log
```

Then add another Filebeat input:

```yaml
- type: filestream
  id: payment-api-kubernetes-logs
  enabled: true

  paths:
    - /var/log/containers/payment-api-*.log

  prospector.scanner.symlinks: true
  follow_symlinks: true

  parsers:
    - container:
        format: auto

  fields:
    service_name: payment-api
    environment: production
    application: payment-api
    log_source: kubernetes

  fields_under_root: true
```

Same Elasticsearch.

Same Kibana.

Same architecture.

---

# Phase 12 — Go application

Same thing.

```text
Go
 ↓
stdout
 ↓
Kubernetes
 ↓
/var/log/containers
 ↓
Filebeat
 ↓
Elasticsearch
 ↓
Kibana
```

You don't need a special Elasticsearch installation for every application.

You have **one centralized logging platform**.

For example:

```text
                         Filebeat
                            │
       ┌────────────────────┼─────────────────────┐
       │                    │                     │
       ▼                    ▼                     ▼
  rayon-plus           payment-api            video-api
    Node.js              FastAPI                  Go
       │                    │                     │
       └────────────────────┼─────────────────────┘
                            ▼
                     Elasticsearch
                            │
                            ▼
                          Kibana
```

---

# Phase 13 — Don't forget retention

This is essential before you consider the system production-ready.

You currently have:

```text
.ds-filebeat-8.19.18-2026.08.24-000001
```

Elasticsearch will not magically keep your disk under control forever.

We should eventually configure:

```text
30-day retention
```

so:

```text
Day 1       logs
Day 2       logs
...
Day 30      logs
Day 31      old logs automatically removed
```

**Do not manually delete `.ds-filebeat-*` indices in production.**

We should configure retention properly using Elasticsearch's lifecycle/data-stream mechanisms.

---

# Phase 14 — Production architecture you are aiming for

Ultimately your VPS should look conceptually like this:

```text
                         ┌───────────────┐
                         │   Rayon-Plus  │
                         │    Node.js    │
                         └───────┬───────┘
                                 │
                           stdout/stderr
                                 │
                                 ▼
                         Kubernetes logs
                                 │
                                 ▼
                            Filebeat
                                 │
                                 ▼
                    ┌──────────────────────┐
                    │    Elasticsearch     │
                    │      :9200           │
                    └──────────┬───────────┘
                               │
                               ▼
                         ┌───────────┐
                         │  Kibana   │
                         │   :5601   │
                         └───────────┘


Application metrics ──► Prometheus ──► Grafana


Business audit events ──► MySQL audit_log
```

That separation is what I would keep.

---

## Your production checklist

At the end, you should be able to answer **YES** to all of these:

```text
[✓] Application writes logs to stdout/stderr
[✓] Kubernetes captures stdout/stderr
[✓] Logs exist under /var/log/containers
[✓] Filebeat can read the Kubernetes log files
[✓] Filebeat can connect to Elasticsearch
[✓] Elasticsearch receives documents
[✓] Kibana can search the documents
[✓] @timestamp works
[✓] application/service fields exist
[✓] Authentication events have structured fields
[✓] Errors can be filtered
[✓] Historical logs can be searched
[ ] Retention is configured
[ ] Disk usage monitoring is configured
[ ] Kibana authentication/security is properly hardened
[ ] Alerts are configured
```

**Your current setup has already passed most of the first 10 items.** The next professional step is **not reinstalling anything**; it is to harden what you already built: **structured Rayon-Plus logs → retention → Kibana dashboard → alerts → security**.
