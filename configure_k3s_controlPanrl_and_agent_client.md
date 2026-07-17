# K3s Control Plane + Agent Setup Guide

## Purpose

This is a future-proof setup guide for configuring a K3s control plane on a VPS and adding K3s agent worker nodes from other servers such as AWS EC2.

## What this guide covers

- verifying the control plane
- creating and using the K3s join token
- checking network connectivity
- installing the agent on worker nodes
- validating node registration
- removing worker nodes safely
- understanding Nginx flow after Kubernetes adoption

---

# Architecture Overview

```text
Internet
  ↓
Nginx (VPS)
  ↓
Kubernetes Service
  ↓
┌──────────┴──────────┐
│                     │
Control Plane      Worker Node
(VPS)              (EC2)
│                     │
└──────────┬──────────┘
           │
           ↓
     WireGuard VPN
           ↓
     MySQL + MongoDB
```

---

# Step 1: Verify the K3s Control Plane on the VPS

1. Connect to the VPS.
2. Confirm the K3s server is running:

```bash
sudo systemctl status k3s
```

3. Confirm Kubernetes is healthy:

```bash
kubectl get nodes
```

Expected output:

```text
NAME         STATUS   ROLES
srv1158437   Ready    server
```

If the node is not Ready, inspect `k3s` logs:

```bash
sudo journalctl -u k3s -n 50
```

---

# Step 2: Get the K3s join token from the control plane

On the VPS control plane, read the join token:

```bash
sudo cat /var/lib/rancher/k3s/server/node-token
```

Save the token securely.

Example:

```text
K10c71dxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

---

# Step 3: Validate network connectivity from the worker node

On the worker machine (EC2 or another VPS):

1. Confirm the control-plane API server is reachable over WireGuard.

```bash
ping 10.10.0.1
```

2. Confirm the Kubernetes API port is open:

```bash
nc -zv 10.10.0.1 6443
```

Expected result:

```text
Connection succeeded
```

If it fails, verify:

- WireGuard is up on both sides
- firewall allows TCP 6443
- the control plane IP is correct

---

# Step 4: Install the K3s agent on a worker node

Install only the agent on the worker node.

On the worker node, run:

```bash
curl -sfL https://get.k3s.io | \
K3S_URL=https://10.10.0.1:6443 \
K3S_TOKEN="<JOIN_TOKEN>" \
sh -
```

Replace:

- `10.10.0.1` with the control plane WireGuard IP
- `<JOIN_TOKEN>` with the token from Step 2

Do not run `curl -sfL https://get.k3s.io | sh -` without `K3S_URL` and `K3S_TOKEN` on the worker.

---

# Step 5: Confirm the agent service is running

On the worker node, check the agent service:

```bash
sudo systemctl status k3s-agent
```

Expected state:

```text
active (running)
```

If it is not running, inspect logs:

```bash
sudo journalctl -u k3s-agent -n 50
```

---

# Step 6: Verify the node appears in the cluster

Back on the control plane (VPS), confirm the worker node joined:

```bash
kubectl get nodes -o wide
```

Expected output example:

```text
NAME               STATUS   ROLES    INTERNAL-IP   VERSION
srv1158437         Ready    server   10.10.0.1     v1.x.x+k3s
ip-172-31-45-26    Ready    agent    10.10.0.3     v1.x.x+k3s
```

Your cluster now has:

- 1 control plane node
- 1 worker (agent) node

---

# Step 7: Confirm pods can run on the worker node

Deploy or inspect workloads and verify node assignment:

```bash
kubectl get pods -A -o wide
```

Example output:

```text
NAMESPACE     NAME                READY   STATUS    NODE               IP
rayon-plus    rayon-plus-abc123   1/1     Running   srv1158437        10.42.0.10
rayon-plus    rayon-plus-def456   1/1     Running   ip-172-31-45-26   10.42.0.11
```

If workloads are not scheduled to the worker, verify the worker has no taints and enough capacity.

---

# Step 8: Add additional worker nodes

Repeat Steps 3–6 for each new agent node.

Example for a new worker with IP `10.10.0.4`:

```bash
curl -sfL https://get.k3s.io | \
K3S_URL=https://10.10.0.1:6443 \
K3S_TOKEN="<JOIN_TOKEN>" \
sh -
```

Then verify again from the control plane:

```bash
kubectl get nodes
```

Expected nodes:

```text
srv1158437
ip-172-31-45-26
vps-node-2
```

---

# Step 9: Remove a worker node safely

1. Drain the node so workloads move away safely:

```bash
kubectl drain ip-172-31-45-26 --ignore-daemonsets --delete-emptydir-data
```

2. Remove the node from Kubernetes:

```bash
kubectl delete node ip-172-31-45-26
```

3. Uninstall the agent on the worker node:

```bash
sudo /usr/local/bin/k3s-agent-uninstall.sh
```

---

# Step 10: Understand Nginx and Kubernetes traffic flow

## Before Kubernetes

Nginx load-balanced traffic directly to containers:

```nginx
upstream rayon_backend {
    server 127.0.0.1:5001;
    server 10.10.0.3:5001;
}
```

## After Kubernetes

Do not balance traffic to individual nodes manually.

Use Nginx to proxy to the Kubernetes NodePort or an Ingress controller:

```nginx
location / {
    proxy_pass http://127.0.0.1:30500;
}
```

Flow after Kubernetes:

```text
Nginx
  ↓
NodePort
  ↓
Service
  ↓
Pods
```

Kubernetes manages pod placement and load balancing.

---

# Step 11: Useful commands for future reference

Cluster status:

```bash
kubectl cluster-info
```

Node list:

```bash
kubectl get nodes -o wide
```

All pods:

```bash
kubectl get pods -A -o wide
```

All services:

```bash
kubectl get svc -A
```

Application logs:

```bash
kubectl logs -f deployment/rayon-plus -n rayon-plus
```
```

Restart deployment:

```bash
kubectl rollout restart deployment/rayon-plus -n rayon-plus
```
```

Scale deployment:

```bash
kubectl scale deployment/rayon-plus --replicas=6 -n rayon-plus
```

---

# Step 12: Control plane high availability (advanced)

Current setup is single-node control plane on the VPS.

Risk:

```text
If the VPS fails, the cluster becomes unavailable.
```

For production HA, use multiple server nodes with an external datastore:
- MySQL
- PostgreSQL
- etcd

This is advanced and not required for small clusters.

---

# Summary

You now have a repeatable process for:
- verifying the K3s control plane
- creating and using the join token
- checking worker connectivity
- installing the agent on worker nodes
- validating node registration
- removing workers safely
- keeping Nginx traffic routing simple

Keep this document with your DevOps knowledge base for future K3s cluster expansions.

location / {
    proxy_pass http://127.0.0.1:30500;
}
```

Flow:

```text
Nginx
  ↓
NodePort
  ↓
Service
  ↓
Pods
```

Kubernetes handles balancing.

---

# Adding More Worker Nodes

Example: New VPS

WireGuard IP:

```text
10.10.0.4
```

Install:

```bash
curl -sfL https://get.k3s.io | \
K3S_URL=https://10.10.0.1:6443 \
K3S_TOKEN="TOKEN" \
sh -
```

Verify:

```bash
kubectl get nodes
```

Expected:

```text
srv1158437
ip-172-31-45-26
vps-node-2
```

---

# Removing a Worker Node

Drain:

```bash
kubectl drain ip-172-31-45-26 \
--ignore-daemonsets \
--delete-emptydir-data
```

Delete:

```bash
kubectl delete node ip-172-31-45-26
```

On worker:

```bash
sudo /usr/local/bin/k3s-agent-uninstall.sh
```

---

# Control Plane High Availability (Advanced)

Current:

```text
VPS
 └─ Control Plane
```

Risk:

```text
If VPS dies
Cluster unavailable
```

Production HA:

```text
Control Plane 1
Control Plane 2
Control Plane 3
```

K3s supports this using an external datastore such as:

* MySQL
* PostgreSQL
* etcd

For learning and small production deployments, your current single control plane is acceptable.

---

# Useful Commands

### Cluster

```bash
kubectl cluster-info
```

### Nodes

```bash
kubectl get nodes -o wide
```

### Pods

```bash
kubectl get pods -A -o wide
```

### Services

```bash
kubectl get svc -A
```

### Logs

```bash
kubectl logs -f deployment/rayon-plus -n rayon-plus
```

### Restart Deployment

```bash
kubectl rollout restart deployment/rayon-plus -n rayon-plus
```

### Scale

```bash
kubectl scale deployment/rayon-plus \
--replicas=6 \
-n rayon-plus
```

---

# Real-World DevOps Takeaway

You have now practiced:

```text
Docker Compose
        ↓
Single-node Kubernetes
        ↓
Multi-node Kubernetes
        ↓
Cross-server scheduling
        ↓
WireGuard private networking
        ↓
Service discovery
        ↓
Application load balancing
```

These are the same foundational concepts used on managed Kubernetes platforms such as Amazon EKS, Google Kubernetes Engine, and Azure Kubernetes Service.
