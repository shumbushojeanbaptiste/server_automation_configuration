server {
    listen 80;
    listen [::]:80;
    server_name live.oleeplay.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name live.oleeplay.com;

    ssl_certificate /etc/nginx/ssl-certificates/live.oleeplay.com.crt;
    ssl_certificate_key /etc/nginx/ssl-certificates/live.oleeplay.com.key;

    # HLS location
    location /hls/ {
        proxy_pass http://127.0.0.1:8088;
        proxy_http_version 1.1;
        
        # Proxy headers
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;

        # Disable buffering for live streaming
        proxy_buffering off;
        proxy_cache off;
        
        # Connection timeouts
        proxy_connect_timeout 5s;
        proxy_read_timeout 60s;
        proxy_send_timeout 60s;

        # CORS headers - MUST be here
        add_header 'Access-Control-Allow-Origin' '*' always;
        add_header 'Access-Control-Allow-Methods' 'GET, HEAD, OPTIONS' always;
        add_header 'Access-Control-Allow-Headers' 'DNT,User-Agent,X-Requested-With,If-Modified-Since,Cache-Control,Content-Type,Range' always;
        add_header 'Access-Control-Expose-Headers' 'Content-Length,Content-Range' always;

        # Handle preflight requests
        if ($request_method = 'OPTIONS') {
            add_header 'Access-Control-Allow-Origin' '*';
            add_header 'Access-Control-Allow-Methods' 'GET, HEAD, OPTIONS';
            add_header 'Access-Control-Allow-Headers' 'DNT,User-Agent,X-Requested-With,If-Modified-Since,Cache-Control,Content-Type,Range';
            add_header 'Access-Control-Max-Age' 86400;
            return 204;
        }
    }
}










# command to check size per second

cat /tmp/hls/radio1/index.m3u8

# check the interval segmnent
watch -n 1 'cat /tmp/hls/radio1/index.m3u8'