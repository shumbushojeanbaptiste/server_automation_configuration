upstream rayon_backend {

    server 127.0.0.1:5001 weight=3 max_fails=3 fail_timeout=30s;

    #server 10.10.0.3:5001 weight=1 max_fails=3 fail_timeout=30s;

    keepalive 32;
}
server {
  listen 80;
  listen [::]:80;
  listen 443 quic;
  listen 443 ssl;
  listen [::]:443 quic;
  listen [::]:443 ssl;
  http2 on;
  http3 off;
  {{ssl_certificate_key}}
  {{ssl_certificate}}
  server_name rayon.maafricapay.com;
  {{root}}

  {{nginx_access_log}}
  {{nginx_error_log}}

  if ($scheme != "https") {
    rewrite ^ https://$host$request_uri permanent;
  }

  location ~ /.well-known {
    auth_basic off;
    allow all;
  }

  {{settings}}

  include /etc/nginx/global_settings;

  index index.html;
location / {

    #proxy_pass http://rayon_backend;
    #proxy_pass http://10.10.0.1:30500;
    #proxy_pass http://localhost:30500;
    proxy_pass http://srv1158437:30500;
    

    proxy_http_version 1.1;

    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;

    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";

    proxy_connect_timeout 600s;
    proxy_send_timeout 600s;
    proxy_read_timeout 600s;
    
    client_body_timeout 600s;
    send_timeout 600s;

    client_max_body_size 10G;

   
    proxy_request_buffering off;
    proxy_buffering off;
}
  # access files
  location /uploads/ {
    alias /data/rayon/uploads/;
    autoindex on;
    expires 30d;
    add_header Cache-Control "public, immutable";
}
   # video
   location /hls/ {
    alias /data/rayon/uploads/;
    autoindex on;
    expires 7d;
    add_header Cache-Control "public";
}
}