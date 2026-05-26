# Linux Port Access Management (AWS/VPS) - Step-by-Step

This guide explains how to manage port access on Linux VPS (Ubuntu/Debian/CentOS) and AWS EC2.

## 1. Check current firewall status

- Ubuntu with UFW:
  - `sudo ufw status verbose`
- CentOS/RHEL with firewalld:
  - `sudo firewall-cmd --state`
  - `sudo firewall-cmd --list-all`

If status is `inactive`/`not running`, all ports are effectively reachable (not safe for production).

## 2. Always allow SSH first (critical)

1. For UFW:
   - `sudo ufw allow ssh` (port 22)
   - or `sudo ufw allow 22/tcp`
2. For firewalld:
   - `sudo firewall-cmd --permanent --add-service=ssh`
   - `sudo firewall-cmd --reload`

> ❗ Do this before enabling firewall to avoid locking yourself out.

## 3. Open only required service ports

Common streaming or web ports:
- RTMP: `1935/tcp`
- Custom stream API: `5002/tcp`
- HTTP: `80/tcp`
- HTTPS: `443/tcp`

### UFW examples
- `sudo ufw allow 1935/tcp`
- `sudo ufw allow 5002/tcp`
- `sudo ufw allow 80/tcp`
- `sudo ufw allow 443/tcp`

### firewalld examples
- `sudo firewall-cmd --permanent --add-port=1935/tcp`
- `sudo firewall-cmd --permanent --add-port=5002/tcp`
- `sudo firewall-cmd --permanent --add-service=http`
- `sudo firewall-cmd --permanent --add-service=https`
- `sudo firewall-cmd --reload`

### Verify rules
- UFW: `sudo ufw status numbered`
- firewalld: `sudo firewall-cmd --list-all`

## 4. Enable firewall safely

- UFW: `sudo ufw enable`
- firewalld: `sudo systemctl enable --now firewalld`

Then verify again.

## 5. AWS EC2 security group rules

1. Go to AWS Console → EC2 → Instances → select instance
2. Click on Security group → Inbound rules
3. Add rules:
   - SSH: TCP 22 (source your IP only, e.g., `203.0.113.5/32`)
   - 1935: TCP (source `0.0.0.0/0` or restricted CIDR)
   - 5002: TCP (source restricted to app clients or VPN)
   - 80, 443: TCP 0.0.0.0/0
4. Save.

> For best security, restrict source IPs and use a jump host or VPN for SSH.

## 6. Close all extra ports (hardening)

### UFW default deny
- `sudo ufw default deny incoming`
- `sudo ufw default allow outgoing`

### firewalld zone-based approach
- `sudo firewall-cmd --set-default-zone=public`
- remove unneeded ports/services

## 7. Advanced strategies (streaming + Docker + CI/CD)

- Use Nginx reverse proxy for HTTP/HTTPS, and map RTMP internal port from container.
- Keep RTMP (`1935`) internal or on isolated network; expose only 80/443.
- Use `docker network` and host-level firewall to block direct access.
- For CI/CD in AWS, use IAM + AWS Systems Manager Session Manager instead of open SSH.

## 8. Quick troubleshooting

- Check listening ports:
  - `sudo ss -tuln | grep -E '1935|5002|80|443|22'`
- Test from remote:
  - `curl -I http://your-host:80`
  - `telnet your-host 1935` or `nc -zv your-host 5002`
- If port blocked, re-check firewall and AWS security group, and host network ACL.

## 9. Backup rules and recover access

- UFW:
  - Save: `sudo ufw status numbered > ufw-backup.txt`
- firewalld:
  - `sudo firewall-cmd --runtime-to-permanent`
  - `sudo cp /etc/firewalld/zones/public.xml ~/firewalld-public-backup.xml`

## 10. Extra operational tips

- Set up automatic IP whitelisting for SSH:
  - `ufw limit ssh` or `firewall-cmd --permanent --add-rich-rule='rule family="ipv4" service name="ssh" log prefix="SSH" accept'`
- Use fail2ban (or equivalent) to block repeated failed connections.
- Monitor with `sudo ufw status verbose` or `sudo firewall-cmd --list-all` regularly.
- Document your commands and keep them in repo docs for future team use.

---

This is a clear, step-by-step port access management workflow for Linux VPS/AWS, with extra safety and hardening guidance.