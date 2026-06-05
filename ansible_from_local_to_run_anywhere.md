# Ansible: Deploy from Local to AWS EC2 Step by Step

This guide explains how to verify your AWS EC2 deployment, confirm the Docker app is running, and set up Nginx to route a domain name. It is written for beginners and uses your existing Ansible structure.

---

## 1. Understand your current files

Your repository contains:

- `inventory.ini` - target hosts and SSH settings
- `docker.yml` - installs Docker and Docker Compose
- `deploy-rayon.yml` - deploys the app on AWS
- `files/docker-compose.yml` - Docker Compose service definition
- `templates/env.j2` - environment file template
- `update.yml` - installs small utilities on the server

---

## 2. Verify Ansible can connect to AWS

Run this from your local machine in the Ansible folder:

```bash
ansible aws -i inventory.ini -m ping
```

Expected result:

```text
aws1 | SUCCESS => {
    "changed": false,
    "ping": "pong"
}
```

If you get success, Ansible can reach the EC2 host.

If you get failures, check:

- the private key path in `inventory.ini`
- the SSH user (`ubuntu` for AWS Ubuntu images)
- network access from your laptop to EC2

---

## 3. Check your inventory file

Your current `inventory.ini` is:

```ini
[vps]
vps1 ansible_host=31.97.215.87

[aws]
aws1 ansible_host=35.180.131.154

[vps:vars]
ansible_user=root

[aws:vars]
ansible_user=ubuntu
ansible_ssh_private_key_file=M00dle.pem
```

Important:

- `aws1` is the host name Ansible will use
- `ansible_host` is the EC2 public IP
- `ansible_user` should match the EC2 OS user
- `ansible_ssh_private_key_file` should point to the correct PEM key file

---

## 4. Confirm Docker is installed on AWS

Run:

```bash
ansible aws -i inventory.ini -a "docker --version"
```

Expected output includes `Docker version`.

If Docker is not installed, run your playbook:

```bash
ansible-playbook -i inventory.ini docker.yml
```

Then run the Docker version check again.

---

## 5. Deploy the application with Ansible

Run:

```bash
ansible-playbook -i inventory.ini deploy-rayon.yml
```

What this playbook does:

- creates `/opt/rayon_plus`
- creates `/opt/rayon_plus/uploads`
- copies `files/docker-compose.yml`
- generates `/opt/rayon_plus/.env`
- pulls the Docker image
- starts the container with `docker compose up -d`

---

## 6. Verify the Docker container is running

Run:

```bash
ansible aws -i inventory.ini -a "docker ps"
```

You should see a container named `rayon_plus`.

If you do not see it, check all containers:

```bash
ansible aws -i inventory.ini -a "docker ps -a"
```

---

## 7. Confirm the app is listening on port 5001

Your compose file uses `network_mode: host`, so the app listens on the EC2 machine directly.

Run:

```bash
ansible aws -i inventory.ini -a "sudo ss -tulpn | grep 5001"
```

Expected output contains `*:5001`.

---

## 8. Test the app from the AWS host itself

Run:

```bash
ansible aws -i inventory.ini -a "curl -I http://localhost:5001"
```

Or:

```bash
ansible aws -i inventory.ini -a "curl http://localhost:5001"
```

If the app responds, your container and app service are working.

---

## 9. Open AWS security group ports

AWS blocks ports unless you allow them.

In the AWS Console:

1. Go to EC2 > Instances
2. Select your instance
3. Click the Security Group link
4. Open the `Inbound rules` tab
5. Add rules for:
   - HTTP (80)
   - HTTPS (443)
   - Custom TCP 5001

For testing, use `0.0.0.0/0` as source.

Then test from your laptop:

```bash
curl http://35.180.131.154:5001
```

If it responds, external access works.

---

## 10. Install Nginx on AWS using Ansible

Create a new playbook called `nginx.yml` with this content:

```yaml
- hosts: aws
  become: yes

  tasks:
    - name: Install Nginx
      apt:
        name: nginx
        state: present
        update_cache: yes

    - name: Ensure Nginx is started
      service:
        name: nginx
        state: started
        enabled: yes
```

Run it with:

```bash
ansible-playbook -i inventory.ini nginx.yml
```

---

## 11. Configure Nginx to proxy the app

Create `templates/nginx.conf.j2` with:

```nginx
server {
  listen 80;
  server_name example.com; # replace with your domain or public IP

  location / {
    proxy_pass http://127.0.0.1:5001;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
  }
}
```

Then create a playbook `deploy-nginx.yml`:

```yaml
- hosts: aws
  become: yes

  tasks:
    - name: Copy Nginx config
      template:
        src: templates/nginx.conf.j2
        dest: /etc/nginx/sites-available/rayon_plus.conf

    - name: Enable Nginx site
      file:
        src: /etc/nginx/sites-available/rayon_plus.conf
        dest: /etc/nginx/sites-enabled/rayon_plus.conf
        state: link

    - name: Remove default Nginx site
      file:
        path: /etc/nginx/sites-enabled/default
        state: absent

    - name: Test Nginx configuration
      command: nginx -t

    - name: Reload Nginx
      service:
        name: nginx
        state: reloaded
```

Run it with:

```bash
ansible-playbook -i inventory.ini deploy-nginx.yml
```

---

## 12. Point your domain name to the EC2 IP

In your domain DNS provider:

- Add an `A` record for your domain
- Point it to `35.180.131.154`
- Wait for DNS propagation

Then verify with:

```bash
curl http://example.com
```

Replace `example.com` with your actual domain.

---

## 13. Summary checklist

1. Verify `ansible aws -i inventory.ini -m ping`
2. Verify Docker with `docker --version`
3. Run `ansible-playbook -i inventory.ini deploy-rayon.yml`
4. Confirm `docker ps` shows `rayon_plus`
5. Confirm `ss -tulpn | grep 5001`
6. Confirm `curl http://localhost:5001`
7. Open AWS security group ports 80/443/5001
8. Install Nginx with `nginx.yml`
9. Configure Nginx and reload with `deploy-nginx.yml`
10. Point domain DNS to EC2 IP

---

## 14. Notes for future use

- Keep your PEM key secure
- Use the correct Ansible user for each host group
- If the app changes, update `files/docker-compose.yml` and `templates/env.j2`
- Use `ansible-playbook` to reapply changes, not manual SSH edits


Create:

nginx.yml
---
- hosts: aws
  become: yes

  tasks:

    - name: Install nginx
      apt:
        name: nginx
        state: present
        update_cache: yes

    - name: Start nginx
      service:
        name: nginx
        state: started
        enabled: yes

Run:

ansible-playbook -i inventory.ini nginx.yml
Step 8: Verify Nginx

Open:

http://35.180.131.154

You should see:

Welcome to nginx!
Step 9: Configure Reverse Proxy

Create template:

templates/rayon.conf.j2
server {
    listen 80;

    server_name rayon.yourdomain.com;

    location / {
        proxy_pass http://127.0.0.1:5001;

        proxy_http_version 1.1;

        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    }
}
Step 10: Create Playbook
nginx-proxy.yml
---
- hosts: aws
  become: yes

  tasks:

    - name: Deploy nginx config
      template:
        src: templates/rayon.conf.j2
        dest: /etc/nginx/sites-available/rayon.conf

    - name: Enable site
      file:
        src: /etc/nginx/sites-available/rayon.conf
        dest: /etc/nginx/sites-enabled/rayon.conf
        state: link

    - name: Remove default site
      file:
        path: /etc/nginx/sites-enabled/default
        state: absent

    - name: Restart nginx
      service:
        name: nginx
        state: restarted

Run:

ansible-playbook -i inventory.ini nginx-proxy.yml
Step 11: Point Domain to EC2

In your DNS provider create:

Type	Name	Value
A	rayon	35.180.131.154

Example:

rayon.example.com

↓

35.180.131.154

Wait a few minutes.

Test:

ping rayon.example.com
Step 12: Free HTTPS with Let's Encrypt

Install Certbot:

- name: Install certbot
  apt:
    name:
      - certbot
      - python3-certbot-nginx
    state: present

Then:

sudo certbot --nginx -d rayon.example.com

or automate later with Ansible.

Recommended Learning Path

Practice these Ansible topics one by one:

Inventory
Ad-hoc commands (ansible aws -a)
Playbooks
Variables
Templates (.j2)
Handlers
Docker deployment
Nginx reverse proxy
SSL (Let's Encrypt)
Roles
Multi-server deployment
CI/CD with Ansible + GitHub Actions

For your current lab, I would first run:

ansible aws -i inventory.ini -a "docker ps"
ansible aws -i inventory.ini -a "curl http://localhost:5001"
ansible aws -i inventory.ini -a "sudo ss -tulpn | grep 5001"

and share the outputs. Then we can continue to the Nginx reverse-proxy configuration step.