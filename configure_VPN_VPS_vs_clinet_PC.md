# WireGuard VPN Setup on VPS and Laptop

This guide shows how to set up a WireGuard VPN on your VPS without disturbing existing apps, web sites, or Docker containers. It uses a separate interface, subnet, and UDP port so the VPN runs in isolation.

## Overview

- VPS public IP: `31.97.215.87`
- WireGuard VPN subnet: `10.10.0.0/24`
- Server VPN IP: `10.10.0.1`
- Client VPN IP: `10.10.0.2`
- WireGuard port: `51820/udp`
- Internal API address: `10.10.0.1:5000`

Only VPN-connected clients should access the internal API.

---

## Safety Rules

### Do

- ✅ Use a new VPN subnet
- ✅ Use a new UDP port for WireGuard
- ✅ Use a separate internal API port
- ✅ Keep existing `nginx` configs unchanged
- ✅ Keep existing firewall rules unchanged except opening the VPN port

### Do not

- ❌ Modify existing nginx reverse proxy configs
- ❌ Change ports `80` or `443`
- ❌ Change the VPS primary IP
- ❌ Replace firewall rules blindly
- ❌ Enable full-tunnel routing for normal internet traffic yet

---

## Step-by-Step Setup

### Step 1: Install WireGuard on the VPS

1. SSH into the VPS.
2. Run:

```bash
sudo apt update
sudo apt install wireguard -y
```

This installs WireGuard without stopping existing apps or requiring a reboot.

### Step 2: Generate server keys

On the VPS, run:

```bash
wg genkey | tee server_private.key | wg pubkey > server_public.key
```

Save the output values from:

```bash
cat server_private.key
cat server_public.key
```

Keep them safe.

### Step 3: Create the server configuration

Create or edit `/etc/wireguard/wg0.conf`:

```bash
sudo nano /etc/wireguard/wg0.conf
```

Paste this content:

```ini
[Interface]
Address = 10.10.0.1/24
ListenPort = 51820
PrivateKey = <SERVER_PRIVATE_KEY>
SaveConfig = true
```

Replace `<SERVER_PRIVATE_KEY>` with the value from `server_private.key`.

### Step 4: Open the VPN UDP port

If you are using UFW:

```bash
sudo ufw allow 51820/udp
```

This only opens the WireGuard port and does not affect websites or APIs on ports `80`/`443`.

If you use another firewall, add the equivalent rule for `51820/udp`.

### Step 5: Start WireGuard on the VPS

Run:

```bash
sudo systemctl enable wg-quick@wg0
sudo systemctl start wg-quick@wg0
sudo wg
```

Confirm the `wg0` interface is up and listening.

### Step 6: Install WireGuard on the laptop

Install the WireGuard client for your operating system.

### Step 7: Generate laptop keys

In the WireGuard client or with CLI, create a new key pair.

Save both values:

- `client_private.key`
- `client_public.key`

### Step 8: Add the laptop peer to the VPS

Edit `/etc/wireguard/wg0.conf` on the VPS and append:

```ini
[Peer]
PublicKey = <CLIENT_PUBLIC_KEY>
AllowedIPs = 10.10.0.2/32
```

Replace `<CLIENT_PUBLIC_KEY>` with the client public key.

Restart WireGuard on the VPS:

```bash
sudo systemctl restart wg-quick@wg0
sudo wg
```

Verify the peer is listed.

### Step 9: Configure the laptop tunnel

Create a WireGuard tunnel config on the laptop with:

```ini
[Interface]
PrivateKey = <CLIENT_PRIVATE_KEY>
Address = 10.10.0.2/24
DNS = 1.1.1.1

[Peer]
PublicKey = <SERVER_PUBLIC_KEY>
Endpoint = 31.97.215.87:51820
AllowedIPs = 10.10.0.0/24
PersistentKeepalive = 25
```

Important:

- `AllowedIPs = 10.10.0.0/24` ensures only VPN internal traffic goes through the tunnel.
- Normal internet traffic stays unchanged.

### Step 10: Connect the laptop to the VPN

Activate the WireGuard tunnel on the laptop.

### Step 11: Test the VPN connection

From the laptop, run:

```bash
ping 10.10.0.1
```

Success means the VPN is working.

### Step 12: Create a private internal API on the VPS

Install Flask if needed:

```bash
pip install flask
```

Create a file such as `/home/<user>/vpn_api.py`:

```python
from flask import Flask
app = Flask(__name__)

@app.route('/')
def home():
    return 'VPN INTERNAL API WORKING'

if __name__ == '__main__':
    app.run(host='10.10.0.1', port=5000)
```

Run it:

```bash
python /home/<user>/vpn_api.py
```

### Step 13: Test VPN-only API access

From the laptop, run:

```bash
curl http://10.10.0.1:5000
```

Expected response:

```text
VPN INTERNAL API WORKING
```

### Step 14: Confirm the API is not publicly accessible

From outside the VPN, run:

```bash
curl http://31.97.215.87:5000
```

It should fail because the app is bound only to `10.10.0.1`.

---

## Why This Setup Does Not Disturb Existing Apps

This setup is isolated by:

- separate interface: `wg0`
- separate subnet: `10.10.0.0/24`
- separate UDP port: `51820`
- separate internal API bind address: `10.10.0.1`
- no changes to `nginx`
- no changes to Docker or existing services

Existing production apps continue normally.

---

## Optional Next Steps

After the VPN is working, you can extend it to:

- FastAPI internal services
- internal `nginx` routing
- Prometheus/Grafana monitoring
- Loki/ELK logging
- Docker Compose service isolation
- multi-user VPN access management
