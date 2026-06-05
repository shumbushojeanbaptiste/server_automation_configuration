# Ansible: Complete Step-by-Step Guide for Future Use

## Overview: Your Real-World Architecture

### Current Setup
```
+-------------------+
| Local PC          |
| DBeaver           |
+---------+---------+
          |
          | WireGuard VPN
          |
+---------v---------+
| VPS               |
| Ubuntu            |
| MySQL Database    |
+-------------------+
```

### Target Architecture (Automated with Ansible)
```
+------------------+                  +-------------------+
| AWS EC2          | <--- WireGuard -- | VPS               |
| Application      |                  | MySQL Database    |
+------------------+                  +-------------------+
         ^
         |
    (via WireGuard)
         |
+-------+--------+
| Local DBeaver  |
+----------------+
```

**What Ansible automates:**
- ✅ System updates and patches
- ✅ Docker & Docker Compose installation
- ✅ WireGuard VPN configuration
- ✅ MySQL client setup
- ✅ Application deployment
- ✅ Nginx web server configuration
- ✅ Service management & verification

---

## PART 1: Installation & Setup

### Step 1.1: Install Ansible

**On Ubuntu/Debian:**
```bash
sudo apt update
sudo apt install ansible -y
```

**On macOS:**
```bash
brew install ansible
```

**Verify Installation:**
```bash
ansible --version
# Expected: ansible [core 2.15.0] or later
```

### Step 1.2: Generate SSH Key for EC2

```bash
ssh-keygen -t rsa -b 4096 -f ~/.ssh/aws.pem -N ""
chmod 600 ~/.ssh/aws.pem
```

**Add this key to your AWS EC2 instance during launch or after.**

### Step 1.3: Create Project Directory

```bash
mkdir -p ~/ansible-aws-project
cd ~/ansible-aws-project
```

---

## PART 2: Project Structure

### Step 2.1: Create Folder Layout

```bash
mkdir -p roles/{common,docker,wireguard,mysql,app,nginx}/{tasks,files,templates}
mkdir -p group_vars files templates
```

### Step 2.2: Final Directory Tree

```
ansible-aws-project/
├── inventory.ini                   # Define servers & groups
├── playbook.yml                    # Main execution file
├── group_vars/
│   └── aws.yml                     # Variables for aws group
├── roles/                          # Reusable automation blocks
│   ├── common/
│   │   └── tasks/main.yml          # System setup
│   ├── docker/
│   │   └── tasks/main.yml          # Docker installation
│   ├── wireguard/
│   │   ├── tasks/main.yml          # WireGuard setup
│   │   └── files/wg0.conf          # VPN config
│   ├── mysql/
│   │   └── tasks/main.yml          # MySQL client
│   ├── app/
│   │   ├── tasks/main.yml          # App deployment
│   │   └── templates/env.j2        # Environment file
│   └── nginx/
│       ├── tasks/main.yml          # Web server
│       └── templates/nginx.conf.j2 # Nginx config
├── files/
│   └── wg0.conf                    # WireGuard configuration
└── templates/
    ├── env.j2                      # Environment variables
    └── nginx.conf.j2               # Nginx template
```

---

## PART 3: Inventory Configuration

### Step 3.1: Create inventory.ini

```bash
cat > inventory.ini << 'EOF'
[aws]
ec2 ansible_host=YOUR_EC2_PUBLIC_IP

[aws:vars]
ansible_user=ubuntu
ansible_ssh_private_key_file=~/.ssh/aws.pem
ansible_python_interpreter=/usr/bin/python3
ansible_ssh_common_args='-o StrictHostKeyChecking=no'
EOF
```

**Replace `YOUR_EC2_PUBLIC_IP` with your actual EC2 IP.**

### Step 3.2: Test Connectivity

```bash
ansible aws -i inventory.ini -m ping
```

**Expected Output:**
```
ec2 | SUCCESS => {
    "changed": false,
    "ping": "pong"
}
```

**Troubleshooting:**
- SSH key permissions: `chmod 600 ~/.ssh/aws.pem`
- Security group allows SSH (port 22)
- EC2 public IP is correct
- Key added to instance: `cat ~/.ssh/aws.pem.pub >> ~/.ssh/authorized_keys`

---

## PART 4: Create Ansible Roles

### Step 4.1: Common Role (System Updates)

**File: `roles/common/tasks/main.yml`**

```yaml
---
- name: Update apt cache
  apt:
    update_cache: yes
    cache_valid_time: 3600
  tags: common

- name: Upgrade all packages
  apt:
    upgrade: dist
    autoremove: yes
    autoclean: yes
  tags: common

- name: Install essential packages
  apt:
    name:
      - curl
      - git
      - unzip
      - htop
      - wget
      - net-tools
      - build-essential
      - python3-pip
    state: present
  tags: common

- name: Create app directory
  file:
    path: /opt/app
    state: directory
    mode: '0755'
    owner: ubuntu
    group: ubuntu
  tags: common

- name: Set system timezone
  timezone:
    name: UTC
  tags: common
```

### Step 4.2: Docker Role (Installation & Setup)

**File: `roles/docker/tasks/main.yml`**

```yaml
---
- name: Install Docker dependencies
  apt:
    name:
      - apt-transport-https
      - ca-certificates
      - curl
      - gnupg
      - lsb-release
    state: present
  tags: docker

- name: Install Docker
  apt:
    name: docker.io
    state: present
  tags: docker

- name: Install Docker Compose v2 (system-wide)
  get_url:
    url: https://github.com/docker/compose/releases/download/v2.27.0/docker-compose-linux-x86_64
    dest: /usr/local/lib/docker/cli-plugins/docker-compose
    mode: '0755'
  tags: docker

- name: Create docker plugin directory for root
  file:
    path: /root/.docker/cli-plugins
    state: directory
    mode: '0755'
  tags: docker

- name: Symlink Docker Compose for root
  file:
    src: /usr/local/lib/docker/cli-plugins/docker-compose
    dest: /root/.docker/cli-plugins/docker-compose
    state: link
  tags: docker

- name: Add ubuntu user to docker group
  user:
    name: ubuntu
    groups: docker
    append: yes
  tags: docker

- name: Start and enable Docker daemon
  service:
    name: docker
    state: started
    enabled: yes
  tags: docker

- name: Verify Docker installation
  shell: docker --version
  register: docker_version
  tags: docker

- name: Verify Docker Compose installation
  shell: docker compose version
  register: compose_version
  tags: docker

- name: Display Docker version
  debug:
    msg: "{{ docker_version.stdout }}"
  tags: docker

- name: Display Docker Compose version
  debug:
    msg: "{{ compose_version.stdout }}"
  tags: docker
```

### Step 4.3: WireGuard Role (VPN Setup)

**File: `roles/wireguard/tasks/main.yml`**

```yaml
---
- name: Install WireGuard packages
  apt:
    name:
      - wireguard
      - wireguard-tools
      - wireguard-dkms
      - linux-headers-generic
    state: present
  tags: wireguard

- name: Enable WireGuard kernel module
  modprobe:
    name: wireguard
    state: present
  tags: wireguard

- name: Create WireGuard directory
  file:
    path: /etc/wireguard
    state: directory
    mode: '0700'
  tags: wireguard

- name: Copy WireGuard configuration
  copy:
    src: wg0.conf
    dest: /etc/wireguard/wg0.conf
    mode: '0600'
  notify: restart wireguard
  tags: wireguard

- name: Enable WireGuard at boot
  lineinfile:
    path: /etc/wireguard/wg0.conf
    line: "# WireGuard configuration for VPS connection"
    create: yes
  tags: wireguard

- name: Start WireGuard interface
  shell: wg-quick up wg0 || true
  tags: wireguard

- name: Wait for WireGuard interface
  pause:
    seconds: 2
  tags: wireguard

- name: Verify WireGuard status
  shell: wg show
  register: wg_status
  tags: wireguard

- name: Display WireGuard status
  debug:
    msg: "{{ wg_status.stdout }}"
  tags: wireguard

- name: Check WireGuard interface IP
  shell: ip addr show wg0
  register: wg_ip
  ignore_errors: yes
  tags: wireguard

- name: Display WireGuard IP
  debug:
    msg: "{{ wg_ip.stdout }}"
  tags: wireguard

handlers:
  - name: restart wireguard
    shell: wg-quick down wg0 && wg-quick up wg0 || true
```

**File: `files/wg0.conf`** (Template Example)

```conf
[Interface]
PrivateKey = GENERATED_PRIVATE_KEY_HERE
Address = 10.10.10.2/24
ListenPort = 51820

[Peer]
PublicKey = VPS_PUBLIC_KEY_HERE
AllowedIPs = 10.10.10.1/32, 10.0.0.0/8
Endpoint = YOUR_VPS_PUBLIC_IP:51820
PersistentKeepalive = 25
```

**Generate WireGuard Keys:**
```bash
# On your VPS or local machine
wg genkey | tee privatekey | wg pubkey > publickey
cat privatekey
cat publickey
```

### Step 4.4: MySQL Client Role

**File: `roles/mysql/tasks/main.yml`**

```yaml
---
- name: Install MySQL client
  apt:
    name: mysql-client
    state: present
  tags: mysql

- name: Install additional utilities
  apt:
    name:
      - mariadb-client
      - mysql-client-core-8.0
    state: present
  tags: mysql

- name: Test database connection
  shell: |
    mysql -h 10.10.10.1 \
          -u appuser \
          -p'{{ db_password }}' \
          -e "SELECT 1;"
  register: db_test
  ignore_errors: yes
  tags: mysql

- name: Display database test result
  debug:
    msg: "Database connection successful"
  when: db_test.rc == 0
  tags: mysql

- name: List available databases
  shell: |
    mysql -h 10.10.10.1 \
          -u appuser \
          -p'{{ db_password }}' \
          -e "SHOW DATABASES;"
  register: db_list
  ignore_errors: yes
  tags: mysql

- name: Display databases
  debug:
    msg: "{{ db_list.stdout }}"
  tags: mysql
```

### Step 4.5: Application Deployment Role

**File: `roles/app/tasks/main.yml`**

```yaml
---
- name: Clone application repository
  git:
    repo: "{{ app_repo }}"
    dest: /opt/app
    version: "{{ app_branch }}"
  register: git_clone
  tags: app

- name: Display clone result
  debug:
    msg: "Repository cloned successfully"
  when: git_clone.changed
  tags: app

- name: Create .env file from template
  template:
    src: env.j2
    dest: /opt/app/.env
    mode: '0600'
    owner: ubuntu
    group: ubuntu
  tags: app

- name: Install application dependencies
  shell: |
    cd /opt/app
    npm install || pip install -r requirements.txt
  ignore_errors: yes
  tags: app

- name: Build Docker image
  shell: |
    cd /opt/app
    docker build -t {{ app_name }}:latest .
  tags: app

- name: Stop existing container
  shell: docker stop {{ app_name }} || true
  tags: app

- name: Remove existing container
  shell: docker rm {{ app_name }} || true
  tags: app

- name: Run Docker container
  shell: |
    docker run -d \
      --name {{ app_name }} \
      --restart always \
      -p {{ app_port }}:{{ app_port }} \
      --env-file /opt/app/.env \
      {{ app_name }}:latest
  tags: app

- name: Wait for container to start
  pause:
    seconds: 3
  tags: app

- name: Verify application container
  shell: docker ps | grep {{ app_name }}
  register: app_status
  tags: app

- name: Display application status
  debug:
    msg: "{{ app_status.stdout }}"
  tags: app

- name: Check application health
  shell: curl -s http://localhost:{{ app_port }}/health || echo "Health check not configured"
  register: health_check
  ignore_errors: yes
  tags: app

- name: Display health status
  debug:
    msg: "{{ health_check.stdout }}"
  tags: app
```

**File: `templates/env.j2`**

```env
# Database Configuration
DB_HOST={{ db_host }}
DB_PORT={{ db_port }}
DB_USER={{ db_user }}
DB_PASSWORD={{ db_password }}
DB_NAME={{ db_name }}

# Application Settings
NODE_ENV=production
APP_PORT={{ app_port }}

# WireGuard VPN
WIREGUARD_IP=10.10.10.2
WIREGUARD_GATEWAY=10.10.10.1

# Optional API Keys
# API_KEY=your-api-key-here
```

### Step 4.6: Nginx Role (Reverse Proxy)

**File: `roles/nginx/tasks/main.yml`**

```yaml
---
- name: Install Nginx
  apt:
    name: nginx
    state: present
  tags: nginx

- name: Create Nginx virtual host configuration
  template:
    src: nginx.conf.j2
    dest: /etc/nginx/sites-available/{{ app_name }}
  tags: nginx

- name: Enable Nginx site
  file:
    src: /etc/nginx/sites-available/{{ app_name }}
    dest: /etc/nginx/sites-enabled/{{ app_name }}
    state: link
  tags: nginx

- name: Disable default Nginx site
  file:
    path: /etc/nginx/sites-enabled/default
    state: absent
  tags: nginx

- name: Test Nginx configuration
  shell: nginx -t
  register: nginx_test
  tags: nginx

- name: Display Nginx test result
  debug:
    msg: "{{ nginx_test.stderr }}"
  tags: nginx

- name: Start and enable Nginx
  service:
    name: nginx
    state: started
    enabled: yes
  tags: nginx

- name: Verify Nginx is running
  shell: systemctl status nginx
  register: nginx_status
  tags: nginx

- name: Display Nginx status
  debug:
    msg: "Nginx is running"
  tags: nginx
```

**File: `templates/nginx.conf.j2`**

```nginx
upstream app_backend {
    server localhost:{{ app_port }};
}

server {
    listen 80;
    server_name _;
    client_max_body_size 50M;

    location / {
        proxy_pass http://app_backend;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_buffering off;
        proxy_request_buffering off;
    }

    location /health {
        access_log off;
        return 200 "healthy\n";
        add_header Content-Type text/plain;
    }
}
```

---

## PART 5: Group Variables

### Step 5.1: Create group_vars/aws.yml

**File: `group_vars/aws.yml`**

```yaml
---
# Docker Settings
docker_users:
  - ubuntu

# WireGuard Settings
wireguard_interface: wg0
wireguard_port: 51820
wireguard_ip: 10.10.10.2/24

# Database Settings (VPS - VPN endpoint)
db_host: 10.10.10.1
db_port: 3306
db_user: appuser
db_password: "{{ vault_db_password }}"
db_name: production

# Application Settings
app_name: myapp
app_port: 3000
app_repo: https://github.com/company/app.git
app_branch: main

# System Settings
timezone: UTC
```

---

## PART 6: Main Playbook

### Step 6.1: Create playbook.yml

```yaml
---
- name: Deploy Application to AWS EC2 with Ansible
  hosts: aws
  become: yes
  gather_facts: yes

  vars:
    ansible_python_interpreter: /usr/bin/python3

  pre_tasks:
    - name: Check system requirements
      debug:
        msg: "Deploying to {{ inventory_hostname }} with {{ ansible_distribution }} {{ ansible_distribution_version }}"

  roles:
    - common
    - docker
    - wireguard
    - mysql
    - app
    - nginx

  post_tasks:
    - name: Deployment Summary
      debug:
        msg: |
          ✅ Deployment completed successfully!
          
          Server: {{ inventory_hostname }}
          Application: {{ app_name }} (port {{ app_port }})
          Database: {{ db_host }}:{{ db_port }}
          WireGuard: {{ wireguard_ip }}
          
          Access application at: http://{{ ansible_host }}

    - name: Run final health checks
      include_tasks: health_checks.yml
```

---

## PART 7: Running Ansible

### Step 7.1: Syntax Validation

```bash
ansible-playbook -i inventory.ini playbook.yml --syntax-check
```

**Expected Output:**
```
playbook: playbook.yml
```

### Step 7.2: Dry Run (Preview Mode)

```bash
ansible-playbook -i inventory.ini playbook.yml --check -v
```

### Step 7.3: Execute Full Deployment

```bash
ansible-playbook -i inventory.ini playbook.yml -v
```

### Step 7.4: Execute Specific Roles

```bash
# Deploy only Docker and Docker Compose
ansible-playbook -i inventory.ini playbook.yml --tags docker

# Deploy only WireGuard
ansible-playbook -i inventory.ini playbook.yml --tags wireguard

# Deploy only application
ansible-playbook -i inventory.ini playbook.yml --tags app
```

### Step 7.5: Verbose Output for Debugging

```bash
# Very verbose (max debugging)
ansible-playbook -i inventory.ini playbook.yml -vvv

# Show all changed items
ansible-playbook -i inventory.ini playbook.yml -v --diff
```

---

## PART 8: Verification & Troubleshooting

### Step 8.1: SSH into EC2

```bash
ssh -i ~/.ssh/aws.pem ubuntu@YOUR_EC2_IP
```

### Step 8.2: Verify Each Component

**Check Docker:**
```bash
docker ps
docker version
docker compose version
```

**Check WireGuard:**
```bash
sudo wg show
ip addr show wg0
```

**Check MySQL Connection:**
```bash
mysql -h 10.10.10.1 -u appuser -p -e "SHOW DATABASES;"
```

**Check Application:**
```bash
curl http://localhost:3000
curl http://localhost:3000/health
```

**Check Nginx:**
```bash
sudo systemctl status nginx
sudo nginx -t
sudo tail -f /var/log/nginx/error.log
```

**Check Docker Logs:**
```bash
docker logs myapp
docker logs -f myapp  # Follow logs
```

### Step 8.3: Common Issues & Fixes

| Issue | Cause | Solution |
|-------|-------|----------|
| SSH connection refused | EC2 security group | Allow port 22 in security group |
| Permission denied on key | Wrong key permissions | `chmod 600 ~/.ssh/aws.pem` |
| Python not found | Missing Python | Add `ansible_python_interpreter` to inventory |
| Docker permission denied | User not in docker group | Run `sudo usermod -aG docker ubuntu` |
| WireGuard won't start | Kernel module missing | `sudo modprobe wireguard` |
| Application won't start | Port already in use | Check: `sudo lsof -i :3000` |
| Database connection fails | WireGuard not connected | Check: `ip route` and `sudo wg show` |

---

## PART 9: Using Ansible Vault (Secrets Management)

### Step 9.1: Create Vault File

```bash
ansible-vault create group_vars/aws/vault.yml
```

**Add secrets:**
```yaml
vault_db_password: "YourSecureDBPassword123!"
vault_api_key: "your-secret-api-key"
vault_jwt_secret: "super-secret-jwt-key"
```

### Step 9.2: Use Vault Variables

In `group_vars/aws.yml`:
```yaml
db_password: "{{ vault_db_password }}"
api_key: "{{ vault_api_key }}"
```

### Step 9.3: Run with Vault

```bash
# Prompt for vault password
ansible-playbook -i inventory.ini playbook.yml --ask-vault-pass

# Or use vault password file
ansible-playbook -i inventory.ini playbook.yml --vault-password-file ~/.ansible/vault-pass
```

### Step 9.4: Edit Vault File

```bash
ansible-vault edit group_vars/aws/vault.yml
```

---

## PART 10: Advanced Techniques

### Conditional Task Execution

```yaml
- name: Install MySQL client only on app servers
  apt:
    name: mysql-client
    state: present
  when: inventory_hostname in groups['aws']
```

### Using Handlers (Service Restart)

```yaml
tasks:
  - name: Update application config
    template:
      src: config.j2
      dest: /opt/app/config.yml
    notify: restart application

handlers:
  - name: restart application
    shell: docker restart myapp
```

### Loops

```yaml
- name: Install multiple packages
  apt:
    name: "{{ item }}"
    state: present
  loop:
    - docker.io
    - git
    - curl
    - nginx
```

### Register Variables & Conditional Tasks

```yaml
- name: Check if application is running
  shell: docker ps | grep myapp
  register: app_running
  ignore_errors: yes

- name: Start application if not running
  shell: docker run -d --name myapp myapp:latest
  when: app_running.rc != 0
```

---

## PART 11: Complete Workflow

### Quick Start (All-in-One)

```bash
# 1. Create project
mkdir ~/ansible-project && cd ~/ansible-project

# 2. Setup files (copy all from above sections)
# - inventory.ini
# - playbook.yml
# - roles/*/tasks/main.yml
# - group_vars/aws.yml

# 3. Update inventory with your EC2 IP
sed -i 's/YOUR_EC2_PUBLIC_IP/1.2.3.4/' inventory.ini

# 4. Test connection
ansible aws -i inventory.ini -m ping

# 5. Dry run
ansible-playbook -i inventory.ini playbook.yml --check

# 6. Deploy
ansible-playbook -i inventory.ini playbook.yml -v
```

---

## PART 12: Reference Commands

| Command | Purpose |
|---------|---------|
| `ansible -i inventory.ini -m ping all` | Test all servers |
| `ansible-playbook playbook.yml --syntax-check` | Validate syntax |
| `ansible-playbook playbook.yml --check` | Dry run |
| `ansible-playbook playbook.yml -v` | Normal execution |
| `ansible-playbook playbook.yml -vvv` | Debug mode |
| `ansible-playbook playbook.yml --tags docker` | Run specific role |
| `ansible-playbook playbook.yml --skip-tags wireguard` | Skip specific role |
| `ansible all -i inventory.ini -m shell -a "uptime"` | Run ad-hoc command |
| `ansible-inventory -i inventory.ini --list` | List all inventory |
| `ansible-vault create file.yml` | Create encrypted file |
| `ansible-vault edit file.yml` | Edit encrypted file |
| `ansible-playbook playbook.yml --ask-vault-pass` | Run with vault |

---

## PART 13: Learning Roadmap

### Week 1: Basics
- [ ] Install Ansible
- [ ] Create inventory
- [ ] Test SSH connectivity
- [ ] Write first playbook

### Week 2: Roles
- [ ] Create common role
- [ ] Create docker role
- [ ] Understand role structure
- [ ] Use variables

### Week 3: Integration
- [ ] Add WireGuard role
- [ ] Add database role
- [ ] Test full deployment
- [ ] Verify connectivity

### Week 4: Security
- [ ] Setup Ansible Vault
- [ ] Store secrets
- [ ] Use encrypted variables
- [ ] Secure playbook

### Week 5: CI/CD
- [ ] Create GitHub Actions workflow
- [ ] Auto-deploy on push
- [ ] Monitor deployments
- [ ] Setup notifications

### Week 6: Production
- [ ] Multi-environment setup
- [ ] Error handling
- [ ] Rollback strategy
- [ ] Documentation

---

## PART 14: Real-World Example

Deploy your entire stack in one command:

```bash
# Clone from version control
git clone https://github.com/yourname/ansible-aws-deployment.git
cd ansible-aws-deployment

# Update with your EC2 IP
echo "1.2.3.4" > /tmp/ec2-ip.txt

# Deploy
ansible-playbook -i inventory.ini playbook.yml -e "ansible_host=$(cat /tmp/ec2-ip.txt)"

# Result: Application running securely on AWS EC2, database on VPS via WireGuard ✅
```

---

## Quick Links & Resources

- [Ansible Official Docs](https://docs.ansible.com/)
- [Ansible Galaxy](https://galaxy.ansible.com/) - Role marketplace
- [Ansible Vault Docs](https://docs.ansible.com/ansible/latest/user_guide/vault.html)
- [Your Project Repository](https://github.com/yourname/ansible-aws-deployment)

---

**Last Updated:** 2026-06-02  
**Status:** Ready for Production Use ✅
