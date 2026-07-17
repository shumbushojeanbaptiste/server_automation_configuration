# K3s Migration Guide — Rayon Plus

## Purpose

This document is a step-by-step guide for migrating the Rayon Plus application from Docker Compose to a K3s cluster across a VPS and an AWS EC2 worker.

## Goal

Deploy the app on Kubernetes with:
- K3s control plane on the VPS
- EC2 as a worker node
- Application pods in Kubernetes
- External access through Nginx → NodePort
- Databases remaining on the VPS behind WireGuard

---

# Step 1: Review the target architecture

Current deployment:

```text
Internet
  ↓
Nginx Load Balancer (VPS)
  ↓
Docker Containers (VPS + EC2)
  ↓
MySQL + MongoDB (VPS)
```

Target Kubernetes deployment:

```text
Internet
  ↓
Nginx Reverse Proxy
  ↓
NodePort Service (30500)
  ↓
Kubernetes Service
  ↓
Application Pods
  ↓
MySQL + MongoDB (VPS)
```

---

# Step 2: Prepare infrastructure

## VPS (Control Plane)

Specifications:
- Ubuntu 24.04
- 8 vCPU
- 31 GB RAM
- 500 GB SSD

Role on VPS:
- K3s server (control plane)
- Kubernetes worker
- MySQL and MongoDB host
- WireGuard VPN endpoint
- Nginx reverse proxy

## AWS EC2

Role:
- K3s worker node
- Application pod execution

---

# Step 3: Keep databases outside Kubernetes

Database hosts remain on the VPS.

```text
VPS
 ├─ MySQL  (3306)
 └─ MongoDB (27017)
```

Application environment variables should point at the VPS WireGuard IP.

Example:

```env
MYSQL_HOST=10.10.0.1
MONGO_URI=mongodb://10.10.0.1:27017/rayonplatform
```

Benefits:
- easier backups
- simpler maintenance
- databases survive Kubernetes restarts
- simpler disaster recovery

---

# Step 4: Install K3s on the VPS control plane

Run on the VPS:

```bash
curl -sfL https://get.k3s.io | sh -
```

Confirm installation:

```bash
kubectl cluster-info
kubectl get nodes
```

Expected result contains:

```text
Kubernetes control plane is running
```

---

# Step 5: Join the EC2 worker to the cluster

On the VPS, copy the node token:

```bash
sudo cat /var/lib/rancher/k3s/server/node-token
```

On the EC2 instance, install the K3s agent:

```bash
curl -sfL https://get.k3s.io | \
K3S_URL=https://10.10.0.1:6443 \
K3S_TOKEN=<TOKEN> \
sh -
```

On the VPS, verify the node is joined:

```bash
kubectl get nodes
```

Expected output:

```text
srv1158437          Ready
ip-172-31-45-26     Ready
```

---

# Step 6: Build and publish the application image

Use this Dockerfile for the backend:

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

Build the image:

```bash
docker build -t 0788644687/rayonplus:latest .
```

Push it to the registry:

```bash
docker push 0788644687/rayonplus:latest
```

---

# Step 7: Create the Kubernetes namespace

Create `namespace.yaml`:

```yaml
apiVersion: v1
kind: Namespace
metadata:
  name: rayon-plus
```

Apply it:

```bash
kubectl apply -f namespace.yaml
```

---

# Step 8: Create application secrets

Create `secret.yaml`:

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
  MYSQL_PASSWORD: "<PASSWORD>"
  MYSQL_DB: "rayon-platform"
  MONGO_URI: "mongodb://10.10.0.1:27017/rayonplatform"
  JWT_SECRET: "<JWT_SECRET>"
```

Apply it:

```bash
kubectl apply -f secret.yaml
```

---

# Step 9: Deploy the application to Kubernetes

Create `deployment.yaml`:

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

Deploy the app:

```bash
kubectl apply -f deployment.yaml
```

---

# Step 10: Expose the app with a NodePort service

Create `service.yaml`:

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

Apply the service:

```bash
kubectl apply -f service.yaml
```

Verify the service:

```bash
kubectl get svc -n rayon-plus
```

Expected service port mapping:

```text
30500:30500/TCP
```

---

# Step 11: Configure Nginx to proxy to NodePort

Use this Nginx location block:

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

Traffic flow after Nginx configuration:

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

# Step 12: Verify the deployment

Check pods:

```bash
kubectl get pods -n rayon-plus
kubectl get pods -n rayon-plus -o wide
```

Check service:

```bash
kubectl get svc -n rayon-plus
kubectl get endpoints -n rayon-plus
```

View logs:

```bash
kubectl logs -f deployment/rayon-plus -n rayon-plus
```

---

# Step 13: Troubleshooting common issues

## Issue: MongoDB connection refused

Error:

```text
ECONNREFUSED localhost:27017
```

Cause:
Application falls back to localhost when `process.env.MONGO_URI` is missing.

Fix:
Ensure the app reads the Kubernetes secret directly:

```javascript
const mongoURI = process.env.MONGO_URI;
```

## Issue: Nginx cannot reach the app

Cause:
`ClusterIP` services are internal-only.

Fix:
Use a `NodePort` service or install an Ingress controller.

## Verify load balancing

Create a simple `/ping` endpoint, then request it repeatedly.

Expected response includes a Pod IP:

```json
{
  "message": "pong from ... (Server: ::ffff:10.42.0.76)"
}
```

If responses change between calls, the service is load-balancing correctly.

---

# Step 14: Useful kubectl commands

View pods:

```bash
kubectl get pods -n rayon-plus
```

View pods with IPs:

```bash
kubectl get pods -n rayon-plus -o wide
```

View services:

```bash
kubectl get svc -n rayon-plus
```

View endpoints:

```bash
kubectl get endpoints -n rayon-plus
```

View logs:

```bash
kubectl logs -f deployment/rayon-plus -n rayon-plus
```

Restart deployment:

```bash
kubectl rollout restart deployment rayon-plus -n rayon-plus
```

Scale pods:

```bash
kubectl scale deployment rayon-plus --replicas=6 -n rayon-plus
```

Test self-healing:

```bash
kubectl delete pod <pod-name> -n rayon-plus
```
```

---

# Step 15: Future improvements

## Add health probes

Add `livenessProbe` and `readinessProbe` to `deployment.yaml`.

## Use Persistent Volumes

For file uploads or stateful storage, add:
- `PersistentVolume` (PV)
- `PersistentVolumeClaim` (PVC)

## Use ConfigMaps

Move non-sensitive application config into a `ConfigMap`.

## Add monitoring

Install Prometheus and Grafana to monitor:
- CPU
- memory
- request rates
- error rates

## Add CI/CD

Future pipeline:

```text
GitHub → GitHub Actions → Docker Build → Docker Push → kubectl apply
```

---

# Final architecture summary

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

This setup delivers:
- high availability with multiple pods
- self-healing through Kubernetes deployments
- service discovery and load balancing via services
- secure database connections through WireGuard
- centralized databases on the VPS
- a solid foundation for Ingress, monitoring, and CI/CD
