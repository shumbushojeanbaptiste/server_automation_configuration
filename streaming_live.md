# OleePlay Streaming Setup Documentation

Complete guide for setting up a live streaming server with Nginx-RTMP and Docker.

---

## 📋 Table of Contents

1. [Prerequisites](#prerequisites)
2. [Docker Installation](#docker-installation)
3. [Nginx-RTMP Container Setup](#nginx-rtmp-container-setup)
4. [Nginx Configuration](#nginx-configuration)
5. [Domain & Reverse Proxy Setup](#domain--reverse-proxy-setup)
6. [OBS Streaming Client Setup](#obs-streaming-client-setup)
7. [Testing & Verification](#testing--verification)
8. [Troubleshooting Guide](#troubleshooting-guide)
9. [Optional Enhancements](#optional-enhancements)

---

## Prerequisites

- Linux server (Ubuntu 20.04+ recommended) or Docker for Windows/Mac
- Domain name pointing to your server IP (e.g., `live.oleeplay.com`)
- SSL certificates for HTTPS (Let's Encrypt recommended)
- Open firewall ports: `1935`, `5002`, `80`, `443`
- OBS Studio installed on streaming client machine

---

## Docker Installation

**Skip this section if Docker is already installed.**

```bash
sudo apt update
sudo apt install docker.io docker-compose -y
sudo systemctl enable --now docker
```

Verify installation:
```bash
docker --version
```

---

## Nginx-RTMP Container Setup

### Step 1: Pull the Image

```bash
docker pull alfg/nginx-rtmp
```

### Step 2: Start the Container

```bash
docker run -d --name rtmp-server \
  -p 1935:1935 -p 5002:5002 -p 8088:80 \
  alfg/nginx-rtmp
```

**Port Mapping:**
| Port | Purpose |
|------|---------|
| `1935` | RTMP input (where OBS streams to) |
| `5002` | HTTP HLS output (where viewers fetch video) |
| `8088` | Optional web interface |

---

## Nginx Configuration

### Step 3: Access Container & Edit Config

```bash
docker exec -it rtmp-server sh
vi /etc/nginx/nginx.conf
```

### Step 4: Configure RTMP → HLS

Replace or add this to `/etc/nginx/nginx.conf`:

```nginx
rtmp {
    server {
        listen 1935;
        chunk_size 4096;

        application stream {
            live on;
            record off;

            hls on;
            hls_path /tmp/hls;
            hls_fragment 3s;
        }
    }
}

http {
    server {
        listen 5002;

        location /hls {
            root /tmp;
            add_header Cache-Control no-cache;
            add_header Access-Control-Allow-Origin *;
        }
    }
}
```

### Step 5: Create HLS Directory

Inside the container:

```bash
mkdir -p /tmp/hls && chmod 755 /tmp/hls
```

### Step 6: Reload Nginx

```bash
nginx -t              # Test configuration
nginx -s reload       # Reload without downtime
```

---

## Domain & Reverse Proxy Setup

Configure your reverse proxy to route domain traffic to the Docker container.

### HTTP Configuration (Non-HTTPS)

```nginx
server {
    listen 80;
    server_name live.oleeplay.com;

    location /hls {
        proxy_pass http://127.0.0.1:5002/hls;
        add_header Access-Control-Allow-Origin *;
    }
}
```

### HTTPS Configuration (Recommended)

> **⚠️ IMPORTANT:** HTTPS block MUST include the `/hls` location, or playback will fail.

```nginx
server {
    listen 443 ssl http2;
    server_name live.oleeplay.com;

    ssl_certificate /etc/nginx/ssl-certificates/live.oleeplay.com.crt;
    ssl_certificate_key /etc/nginx/ssl-certificates/live.oleeplay.com.key;

    location /hls {
        proxy_pass http://127.0.0.1:5002/hls;
        add_header Access-Control-Allow-Origin *;
        add_header Cache-Control no-cache;
    }
}
```

Reload your main Nginx:
```bash
nginx -t && nginx -s reload
```

---

## OBS Streaming Client Setup

### Configure OBS Output

| Setting | Value |
|---------|-------|
| **Stream Type** | Custom RTMP |
| **Server URL** | `rtmp://<your-domain-or-ip>/stream` |
| **Stream Key** | `test123` |

> **Note:** The stream key (`test123`) must match the `application stream {}` block in nginx.conf.

---

## Testing & Verification

### Verify HLS Stream is Being Generated

```bash
# Inside Docker container
ls -la /tmp/hls/
```

You should see `.m3u8` and `.ts` files.

### Test Playback URLs

Once OBS is streaming:

| Location | URL |
|----------|-----|
| **Local IP** | `http://<server-ip>:5002/hls/test123.m3u8` |
| **HTTP Domain** | `http://live.oleeplay.com/hls/test123.m3u8` |
| **HTTPS Domain** | `https://live.oleeplay.com/hls/test123.m3u8` |

Test with VLC, ffplay, or embed in a webpage (see [Optional Enhancements](#optional-enhancements)).

---

## Troubleshooting Guide

### ❌ No HLS Files in `/tmp/hls`

**Causes:**
- OBS not actively streaming
- Stream key mismatch (check nginx.conf `application` name)
- RTMP port 1935 not reachable

**Solution:**
```bash
docker logs rtmp-server          # Check container logs
docker exec -it rtmp-server sh   # Enter container
ls -la /tmp/hls/                 # Verify directory exists
```

### ❌ HTTPS Returns 404

**Cause:** `/hls` location not defined in SSL server block.

**Solution:** Ensure your HTTPS server block includes:
```nginx
location /hls {
    proxy_pass http://127.0.0.1:5002/hls;
    add_header Access-Control-Allow-Origin *;
    add_header Cache-Control no-cache;
}
```

### ❌ CORS Errors in Browser Console

**Symptom:** Browser blocking requests to HLS stream.

**Solution:** Add CORS headers to both HTTP and SSL blocks:
```nginx
add_header Access-Control-Allow-Origin *;
```

### ❌ Firewall / Port Issues

**Verify ports are open:**
```bash
sudo ufw allow 1935
sudo ufw allow 5002
sudo ufw allow 80
sudo ufw allow 443
```

Or:
```bash
netstat -tuln | grep -E '1935|5002|80|443'
```

### ❌ Changes Not Taking Effect

**Solution:** Always test and reload after config changes:
```bash
nginx -t              # Test syntax
nginx -s reload       # Reload (no downtime)
```

---

## Optional Enhancements

### 1. HTML5 Player with HLS.js

Create an `index.html` file to serve on your domain:

```html
<!DOCTYPE html>
<html>
<head>
    <title>OleePlay Live Stream</title>
    <style>
        body { font-family: Arial, sans-serif; background: #222; color: #fff; padding: 20px; }
        video { max-width: 100%; height: auto; }
    </style>
</head>
<body>
    <h1>OleePlay Live Stream</h1>
    <video id="video" controls autoplay controls>
        <source src="https://live.oleeplay.com/hls/test123.m3u8" type="application/x-mpegURL">
    </video>

    <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
    <script>
        if(Hls.isSupported()) {
            var video = document.getElementById('video');
            var hls = new Hls();
            hls.loadSource(video.src);
            hls.attachMedia(video);
            hls.on(Hls.Events.MANIFEST_PARSED, function() {
                video.play();
            });
        }
    </script>
</body>
</html>
```

### 2. Adaptive Bitrate (Multiple Qualities)

- Configure OBS to output multiple bitrates (360p, 480p, 720p)
- Create HLS master playlist combining all streams
- Viewers can switch quality during playback

### 3. Use Cloudflare

- Provides free HTTPS and caching
- Reduces server bandwidth usage
- Improves global playback performance

### 4. Stream Recording

Enable in nginx.conf:
```nginx
application stream {
    live on;
    record all;
    record_path /recordings;
}
```

---

## Quick Reference Cheat Sheet

```bash
# Docker Commands
docker pull alfg/nginx-rtmp
docker run -d --name rtmp-server -p 1935:1935 -p 5002:5002 -p 8088:80 alfg/nginx-rtmp
docker logs rtmp-server
docker exec -it rtmp-server sh
docker restart rtmp-server

# Container Nginx Commands
nginx -t                    # Test config
nginx -s reload             # Reload
nginx -s stop               # Stop

# Directory Check
mkdir -p /tmp/hls && chmod 755 /tmp/hls
ls -la /tmp/hls/

# Test URLs
curl http://<server-ip>:5002/hls/test123.m3u8
```

---

## Contact & Support

For issues or questions, check logs first:
```bash
docker logs -f rtmp-server
```

Last Updated: March 2026