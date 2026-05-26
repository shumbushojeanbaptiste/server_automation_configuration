# k6 Load Testing Guide (VPS / Local)

k6 is an open-source, developer-centric load testing tool that runs scripted tests in JavaScript and exports metrics.

## 1. Why k6 for Software Development

- scriptable in JavaScript (easy for developers)
- CLI-first and automatable in CI/CD pipelines
- lightweight, runs on Linux, macOS, Windows
- high-performance, supports many concurrent virtual users
- rich metrics (HTTP timings, status codes, trends, thresholds)

## 2. Installation (Official Source)

### 2.1 Linux (Ubuntu/Debian) from k6 Official Repo

1. `sudo mkdir -p /etc/apt/keyrings`
2. `curl -fsSL https://dl.k6.io/key.gpg | sudo tee /etc/apt/keyrings/k6.gpg > /dev/null`
3. `echo "deb [signed-by=/etc/apt/keyrings/k6.gpg] https://dl.k6.io/deb stable main" | sudo tee /etc/apt/sources.list.d/k6.list`
4. `sudo apt update`
5. `sudo apt install -y k6`
6. Verify: `k6 version`

### 2.2 Linux (RHEL/CentOS/Fedora)

1. `sudo rpm --import https://dl.k6.io/key.gpg`
2. Create `/etc/yum.repos.d/k6.repo` with:
   - `[k6]`
   - `name=k6 repo`
   - `baseurl=https://dl.k6.io/rpm/stable`
   - `enabled=1`
   - `gpgcheck=1`
   - `gpgkey=https://dl.k6.io/key.gpg`
3. `sudo yum install -y k6` (or `dnf install -y k6`)

### 2.3 macOS (Homebrew)

- `brew install k6`

### 2.4 Windows (msi Installer)

- Download: https://dl.k6.io/windows/k6-latest-amd64.msi
- Run installer
- Open `cmd` or PowerShell: `k6 version`

### 2.5 Docker (optional for VPS / CI)

- `docker pull grafana/k6`
- `docker run --rm -i grafana/k6 run - <script.js`

## 3. First k6 Script (local or VPS)

1. Create file `test.js`

```js
import http from 'k6/http';
import { sleep } from 'k6';

export const options = {
  vus: 10,
  duration: '30s',
};

export default function () {
  const res = http.get('http://31.97.215.87:5000/api/accounts/all');
  if (res.status !== 200) {
    console.error('bad status: ' + res.status);
  }
  sleep(1);
}
```

2. Run locally or on VPS:

`k6 run test.js`

3. Output will show VUs, iterations, HTTP status counts, and checks.

## 4. Target Environments (Example)

- Docker Compose API: `http://31.97.215.87:5000/api/accounts/all`
- Kubernetes API: `http://31.97.215.87:30500/api/accounts/all`

To test second target, update URL in script and re-run.

## 5. Advanced Test: Ramp-up and Thresholds

```js
export const options = {
  stages: [
    { duration: '10s', target: 10 },
    { duration: '20s', target: 50 },
    { duration: '20s', target: 100 },
    { duration: '10s', target: 0 },
  ],
  thresholds: {
    'http_req_duration': ['p(95)<500'],
    'http_req_failed': ['rate<0.01'],
  },
};
```

## 6. Useful k6 Flags

- `k6 run --vus 50 --duration 60s script.js`
- `k6 run --out json=results.json script.js`
- `k6 run --out influxdb=http://host:8086/dbname script.js`
- `k6 run --summary-export=summary.json script.js`

## 7. k6 in CI/CD

- add command to pipeline: `k6 run --out json=results.json script.js`
- parse metrics via `k6 report` or custom scripts
- fail build if threshold violated (`--fail-early` and checks)

## 8. Benefits in Software Development (Clear)

- early detection of performance regressions
- prevents production outages under load
- quantifies service-level objectives (SLOs)
- improves API scalability decisions
- reduces debugging time from unknown “load” errors

## 9. Testing Workflow Tips

1. start with a smoke test (`vus: 1`, `duration: 10s`)
2. add one endpoint at a time
3. compare before/after code releases
4. use real-like user paths (login, workflow, etc.)
5. keep test scripts in source control
6. run on similar environment to production (VPS/cluster)

## 10. Complementary Tools

- Apache JMeter (GUI, rich protocol support)
- Gatling (Scala DSL, high throughput)
- Locust (Python-based, distributed)
- Artillery (Node.js-friendly)
- Vegeta (CLI, simple and fast)
- wrk / hey (lightweight HTTP benchmarks)

## 11. k6 best practices

- measure response time and error rate together
- avoid too high VU in single machine; scale horizontally with more load generators
- watch CPU/memory on target service
- use `k6 cloud` for managed parallel load (optional)
- remove hard-coded test target addresses using env vars:

```js
const TARGET = __ENV.TARGET_URL || 'http://localhost:8080/api/accounts/all';
http.get(TARGET);
```

---

This file now has a reproducible workflow for future use on VPS or local installs, with official install sources, advantages, and broader tool context.