# Linux VPS Production Health Check & Monitoring Runbook

**Environment:** Ubuntu Linux / Docker / Kubernetes (K3s)  
**Server:** `srv1158437`  
**Purpose:** Monitor server health, investigate performance issues, identify resource bottlenecks and prevent production downtime.

---

## 1. Server Overview

Check the general server status.

```bash
hostname
uptime
date
nproc
lscpu
```

Important information:
- Hostname: Server identity.
- Uptime: How long the server has been running.
- Load average: Average system load over 1, 5 and 15 minutes.
- `nproc`: Number of available CPU processing units.
- `lscpu`: CPU architecture and specifications.

## 2. RAM (Memory) Monitoring

### Check RAM usage

```bash
free -h
```

Important columns:

| Metric | Meaning |
|---|---|
| Total | Total physical RAM |
| Used | Currently used RAM |
| Free | Completely unused RAM |
| Shared | Shared memory |
| Buff/cache | Filesystem cache and buffers |
| Available | Estimated RAM available for new applications |

**Important:** Linux uses unused memory for caching. Low `free` memory does not necessarily indicate a problem. Focus on `available`.

### Identify memory-consuming processes

```bash
ps aux --sort=-%mem | head -20
```

### Monitor RAM continuously

```bash
watch -n 2 free -h
```

Press `CTRL + C` to exit.

## 3. Swap Memory Monitoring

Swap is disk space used as additional virtual memory when Linux needs to move memory pages out of RAM.

### Check Swap usage

```bash
free -h
swapon --show
cat /proc/meminfo | grep -E 'SwapTotal|SwapFree'
```

### Check Linux swappiness

```bash
cat /proc/sys/vm/swappiness
```

Swappiness controls how aggressively Linux considers swapping.

### Identify processes using Swap

```bash
for f in /proc/[0-9]*/status; do
  awk '
    /^Name:/ {name=$2}
    /^Pid:/ {pid=$2}
    /^VmSwap:/ {
      if ($2 > 0)
        printf "%10s KB PID=%-8s %s\n", $2, pid, name
    }
  ' "$f" 2>/dev/null
done | sort -rn | head -20
```

### Investigate active swapping

```bash
vmstat 1 10
```

Important columns:

| Column | Meaning |
|---|---|
| swpd | Currently allocated swap (KiB) |
| si | Swap-in per second |
| so | Swap-out per second |
| free | Free physical memory |
| wa | CPU waiting for I/O |

Interpretation:
- `si = 0`, `so = 0`: No active swapping during the sample.
- Persistent non-zero `si` and `so`: Investigate memory pressure.
- High Swap allocation alone does not prove a problem.

**Note:** `vmstat` reports `swpd` in KiB on standard Linux systems. Always cross-check with `free -h` and `swapon --show`.

## 4. CPU Monitoring

### Check CPU specifications

```bash
nproc
lscpu
```

### Monitor CPU in real time

```bash
top
```

Useful keyboard shortcuts inside `top`:

| Key | Function |
|---|---|
| P | Sort by CPU |
| M | Sort by memory |
| 1 | Show individual CPU cores |
| q | Exit |

### Find top CPU-consuming processes

```bash
ps aux --sort=-%cpu | head -20
```

### Check CPU load

```bash
uptime
```

For an 8-core server, a load average around 1 is generally light. Compare load with the number of available CPU cores and inspect whether processes are waiting.

### Understand CPU utilization

| Metric | Meaning |
|---|---|
| us | User-space processes |
| sy | Kernel/system processes |
| id | Idle CPU |
| wa | Waiting for disk I/O |
| st | CPU steal time |

### Investigate CPU steal

```bash
vmstat 1 30
```

CPU steal (`st`) represents time when your virtual machine is ready to run but the hypervisor does not provide CPU execution time.

Persistent high steal can indicate physical-host contention.

Also check:

```bash
cat /proc/pressure/cpu
```

This provides additional information about CPU scheduling pressure.

## 5. Disk Storage Monitoring

### Check filesystem capacity

```bash
df -hT
```

### Check inode availability

```bash
df -ih
```

### Find large application directories

```bash
du -h --max-depth=2 /opt/apps | sort -h
```

### Check application data

```bash
du -sh /data/*
```

### Identify large files

```bash
find /data -type f -size +500M -exec ls -lh {} \;
```

Investigate large files before deleting anything.

## 6. Docker Monitoring

### List running containers

```bash
docker ps
```

### Check all containers

```bash
docker ps -a
```

### Monitor resource consumption

```bash
docker stats
```

One-time snapshot:

```bash
docker stats --no-stream
```

### Identify container memory consumption

Check:
- CPU %
- MEM USAGE
- MEM %
- NET I/O
- BLOCK I/O
- PIDS

### Check container logs

```bash
docker logs --tail 100 CONTAINER_NAME
```

Follow live logs:

```bash
docker logs -f CONTAINER_NAME
```

### Check Docker disk consumption

```bash
docker system df
```

Do not run Docker prune commands on production without verifying which images, containers and volumes are safe to remove.

## 7. Kubernetes / K3s Monitoring

### Check cluster nodes

```bash
kubectl get nodes -o wide
```

### Check all pods

```bash
kubectl get pods -A -o wide
```

### Check deployments

```bash
kubectl get deployments -A
```

### Monitor node resources

```bash
kubectl top nodes
```

### Monitor pod CPU consumption

```bash
kubectl top pods -A --sort-by=cpu
```

### Monitor pod memory consumption

```bash
kubectl top pods -A --sort-by=memory
```

### Investigate failing pods

```bash
kubectl describe pod POD_NAME -n NAMESPACE
```

### Check Kubernetes events

```bash
kubectl get events -A --sort-by='.lastTimestamp'
```

If metrics are unavailable, investigate Metrics Server before concluding that the node is unhealthy.

## 8. Network and Connectivity

### Check network interfaces

```bash
ip addr
```

### Check listening ports

```bash
ss -tulpn
```

### Check established connections

```bash
ss -s
```

### Check DNS resolution

```bash
resolvectl status
```

### Test network connectivity

```bash
ping -c 4 8.8.8.8
```

## 9. Combined Health Check

Use this command collection for a quick server assessment.

```bash
echo "========== SERVER =========="
hostname
uptime
nproc

echo "========== MEMORY =========="
free -h

echo "========== SWAP =========="
swapon --show
cat /proc/sys/vm/swappiness

echo "========== CPU =========="
vmstat 1 5
ps aux --sort=-%cpu | head -10

echo "========== MEMORY PROCESSES =========="
ps aux --sort=-%mem | head -10

echo "========== DISK =========="
df -hT
df -ih

echo "========== DOCKER =========="
docker stats --no-stream

echo "========== KUBERNETES =========="
kubectl get nodes -o wide
kubectl get pods -A
kubectl top nodes

echo "========== DOCKER STORAGE =========="
docker system df
```

## 10. Production Monitoring Checklist

Perform these checks regularly.

### Daily
- [ ] Check server uptime.
- [ ] Check RAM availability.
- [ ] Check CPU utilization.
- [ ] Check Swap activity.
- [ ] Check disk capacity.
- [ ] Check Docker container status.
- [ ] Check Kubernetes pod status.
- [ ] Review critical application logs.

### Weekly
- [ ] Investigate large directories.
- [ ] Review Docker disk usage.
- [ ] Check database storage.
- [ ] Check backup execution and backup integrity.
- [ ] Review CPU steal and system pressure.
- [ ] Review application resource consumption.
- [ ] Check failed and restarted containers.

### Monthly
- [ ] Review server resource trends.
- [ ] Review backup and disaster recovery procedures.
- [ ] Review security updates.
- [ ] Review storage growth.
- [ ] Review application resource limits.
- [ ] Assess whether infrastructure scaling is required.

## 11. Recommended Monitoring Architecture

For long-term production monitoring, use:

- **Prometheus:** Collect infrastructure and application metrics.
- **Grafana:** Visualize CPU, RAM, disk, network and application metrics.
- **Node Exporter:** Expose Linux server metrics.
- **cAdvisor:** Monitor container resource consumption.
- **Alertmanager:** Send alerts when resource thresholds are exceeded.
- **Loki or ELK:** Centralize and investigate logs.

Suggested monitoring architecture:

Server → Node Exporter / cAdvisor → Prometheus → Grafana → Alertmanager.

## 12. Important Production Safety Rules

1. Never clear Swap just because its usage is high.
2. Never restart production services without understanding the impact.
3. Never delete database, HLS, backup or application files without verification.
4. Investigate CPU steal before assuming your applications are consuming too much CPU.
5. Monitor available RAM rather than relying only on free RAM.
6. Check both Docker and Kubernetes because they may run separate workloads.
7. Keep backups before making infrastructure changes.
8. Monitor resource trends rather than relying on one-time measurements.

---

**Final objective:** Maintain a stable, observable and scalable production VPS while preventing resource exhaustion, unexpected downtime and data loss.