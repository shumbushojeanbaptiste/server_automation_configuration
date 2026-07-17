# Kubernetes (K3s) Migration Documentation – Rayon Plus

## Overview

This document describes the migration of the **Rayon Plus** application from a Docker Compose deployment to a **K3s Kubernetes cluster** running across a VPS and an AWS EC2 instance.

### Initial Architecture (Docker Compose)

```text
Internet
   ↓
Nginx Load Balancer (VPS)
   ↓
Docker Container (VPS)
Docker Container (EC2)
   ↓
MySQL + MongoDB (VPS)
```

### Target Architecture (Kubernetes)

```text
Internet
   ↓
Nginx Reverse Proxy
   ↓
NodePort Service (30500)
   ↓
Kubernetes Service
   ↓
Multiple Pods
   ↓
MySQL + MongoDB (VPS)
```

---

# Infrastructure

## VPS (Control Plane)

Server Specifications:

```text
OS: Ubuntu 24.04
CPU: 8 vCPU
RAM: 31 GB
Storage: 500 GB SSD
```

Roles:

* K3s Server (Control Plane)
* Kubernetes Worker
* MySQL Server
* MongoDB Server
* WireGuard VPN
* Nginx Reverse Proxy

---

## AWS EC2

Roles:

* K3s Worker Node
* Application Pod Hosting

---

# Existing Database Architecture

Databases remain outside Kubernetes.

```text
VPS
 ├─ MySQL (3306)
 └─ MongoDB (27017)
```

Applications connect through WireGuard.

Example:

```env
MYSQL_HOST=10.10.0.1
MONGO_URI=mongodb://10.10.0.1:27017/rayonplatform
```

Benefits:

* Easier backup management
* Easier maintenance
* Databases survive Kubernetes upgrades
* Simpler disaster recovery

---

# Installing K3s

## VPS (Control Plane)

Install K3s:

```bash
curl -sfL https://get.k3s.io | sh -
```

Verify:

```bash
kubectl cluster-info
kubectl get nodes
```

Expected:

```text
Kubernetes control plane is running
```

---

# Connecting EC2 as Worker Node

Get node token:

```bash
sudo cat /var/lib/rancher/k3s/server/node-token
```

Example:

```text
K10xxxxxxxxxxxxxxxx
```

Install agent on EC2:

```bash
curl -sfL https://get.k3s.io | \
K3S_URL=https://10.10.0.1:6443 \
K3S_TOKEN=<TOKEN> \
sh -
```

Verify:

```bash
kubectl get nodes
```

Expected:

```text
srv1158437          Ready
ip-172-31-45-26     Ready
```

---

# Container Image

Dockerfile:

```dockerfile
FROM node:20

WORKDIR /app

RUN apt-get update && apt-get install -y \
build-essential python3 make g++

COPY package*.json ./

RUN npm ci --unsafe-perm

COPY backend ./backend

EXPOSE 5001

CMD ["npm", "start"]
```

Build:

```bash
docker build -t 0788644687/rayonplus:latest .
```

Push:

```bash
docker push 0788644687/rayonplus:latest
```

---

# Kubernetes Namespace

namespace.yaml

```yaml
apiVersion: v1
kind: Namespace
metadata:
  name: rayon-plus
```

Apply:

```bash
kubectl apply -f namespace.yaml
```

---

# Secrets

secret.yaml

```yaml
apiVersion: v1
kind: Secret
metadata:
  name: rayon-secret
  namespace: rayon-plus

type: Opaque

stringData:
  MYSQL_HOST: "10.10.0.1"
  MYSQL_USER: "rayon"
  MYSQL_PASSWORD: "********"
  MYSQL_DB: "rayon-platform"
  MONGO_URI: "mongodb://10.10.0.1:27017/rayonplatform"
  JWT_SECRET: "********"
```

Apply:

```bash
kubectl apply -f secret.yaml
```

---

# Deployment

deployment.yaml

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: rayon-plus
  namespace: rayon-plus

spec:
  replicas: 4

  selector:
    matchLabels:
      app: rayon-plus

  template:
    metadata:
      labels:
        app: rayon-plus

    spec:
      containers:
      - name: rayon-plus

        image: 0788644687/rayonplus:latest

        ports:
        - containerPort: 5001

        envFrom:
        - secretRef:
            name: rayon-secret
```

Apply:

```bash
kubectl apply -f deployment.yaml
```

---

# Service

## First Attempt (ClusterIP)

```yaml
type: ClusterIP
```

Problem:

```text
Not accessible from Nginx
```

Reason:

ClusterIP is internal-only.

---

## Final Solution (NodePort)

service.yaml

```yaml
apiVersion: v1
kind: Service
metadata:
  name: rayon-service
  namespace: rayon-plus

spec:
  selector:
    app: rayon-plus

  type: NodePort

  ports:
  - port: 80
    targetPort: 5001
    nodePort: 30500
```

Apply:

```bash
kubectl apply -f service.yaml
```

Verify:

```bash
kubectl get svc -n rayon-plus
```

Expected:

```text
30500:30500/TCP
```

---

# Nginx Configuration

Final configuration:

```nginx
location / {

    proxy_pass http://127.0.0.1:30500;

    proxy_http_version 1.1;

    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;

    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";

    proxy_connect_timeout 60s;
    proxy_send_timeout 60s;
    proxy_read_timeout 60s;

    proxy_buffering off;
}
```

Flow:

```text
Internet
 ↓
Nginx
 ↓
NodePort 30500
 ↓
Service
 ↓
Pods
```

---

# MongoDB Issue Encountered

Error:

```text
ECONNREFUSED localhost:27017
```

Cause:

Application fallback:

```javascript
process.env.MONGO_URI ||
"mongodb://localhost:27017/rayonplatform"
```

Kubernetes Secrets existed but code still attempted localhost.

Fix:

Use Kubernetes Secret values.

Correct configuration:

```javascript
const mongoURI = process.env.MONGO_URI;
```

---

# Verifying Pod Load Balancing

API Endpoint:

```javascript
router.get("/ping", ...)
```

Response:

```json
{
  "message": "pong from ... (Server: ::ffff:10.42.0.76)"
}
```

Observation:

```text
10.42.x.x
```

represents Pod IP.

Different requests may return:

```text
10.42.0.76
10.42.0.77
10.42.1.3
```

proving load balancing.

---

# Useful Commands

## View Pods

```bash
kubectl get pods -n rayon-plus
```

---

## View Pod IPs

```bash
kubectl get pods -n rayon-plus -o wide
```

---

## View Services

```bash
kubectl get svc -n rayon-plus
```

---

## View Endpoints

```bash
kubectl get endpoints -n rayon-plus
```

---

## Logs

```bash
kubectl logs -f deployment/rayon-plus -n rayon-plus
```

---

## Restart Deployment

```bash
kubectl rollout restart deployment rayon-plus -n rayon-plus
```

---

## Scale Pods

```bash
kubectl scale deployment rayon-plus \
--replicas=6 \
-n rayon-plus
```

---

## Delete Pod (Self-Healing Test)

```bash
kubectl delete pod <pod-name> -n rayon-plus
```

Kubernetes automatically recreates it.

---

# Concepts Learned

## Pod

Smallest runnable unit.

Contains:

```text
Application Container
```

---

## Deployment

Manages:

* Pod creation
* Scaling
* Updates
* Recovery

---

## Service

Provides:

```text
Stable IP
Load Balancing
Service Discovery
```

---

## ClusterIP

Internal communication only.

Used for:

* Databases
* Internal APIs
* Microservices

---

## NodePort

Exposes application externally.

Example:

```text
Server_IP:30500
```

---

## Ingress

HTTP routing layer.

Future architecture:

```text
Internet
 ↓
Ingress Controller
 ↓
Service
 ↓
Pods
```

---

# Future Improvements

## Health Checks

Add:

```yaml
livenessProbe:
readinessProbe:
```

---

## Persistent Volumes

For uploads and persistent data.

Learn:

* PersistentVolume (PV)
* PersistentVolumeClaim (PVC)

---

## ConfigMaps

Store:

* Application settings
* Non-sensitive configuration

---

## Monitoring

Install:

* Prometheus
* Grafana

Monitor:

* CPU
* Memory
* Requests
* Errors

---

## CI/CD

Implement:

```text
GitHub
 ↓
GitHub Actions
 ↓
Docker Build
 ↓
Docker Push
 ↓
kubectl Apply
```

---

# Final Architecture

```text
                     Internet
                         │
                         ▼
                rayon.maafricapay.com
                         │
                         ▼
                     Nginx
                         │
                         ▼
                  NodePort 30500
                         │
                         ▼
                Kubernetes Service
                         │
         ┌───────────────┼───────────────┐
         ▼               ▼               ▼
      Pod 1           Pod 2           Pod 3
     (VPS)           (EC2)           (VPS)
         │               │               │
         └───────────────┼───────────────┘
                         │
                         ▼
                WireGuard VPN
                         │
                         ▼
                 MySQL + MongoDB
                    (10.10.0.1)
```

This setup provides:

* High availability through multiple pods
* Self-healing through Kubernetes Deployments
* Service discovery and load balancing through Services
* Secure database communication through WireGuard
* Centralized database management on the VPS
* A solid foundation for future Ingress, monitoring, and CI/CD adoption.
