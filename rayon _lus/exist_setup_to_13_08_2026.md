root@srv1158437:/opt/rtmp# cat docker-compose.yml


services:
  rtmp:
    image: alfg/nginx-rtmp:latest
    container_name: rtmp-server
    restart: unless-stopped
    ports:
      - "1935:1935"
      - "8088:80"
    volumes:
      - ./nginx.conf:/etc/nginx/nginx.conf:ro
      - /tmp/hls:/tmp/hls
      - /tmp/dvr:/tmp/dvr
    command: nginx -g "daemon off;"
    ulimits:
      nofile:
        soft: 65535
        hard: 65535
    logging:
      driver: "json-file"
      options:
        max-size: "10m"
        max-file: "3"



root@srv1158437:/opt/rtmp# cat nginx.conf
error_log /dev/stdout warn;

events {
    worker_connections 4096;
}

rtmp {
    server {
        listen 1935;
        chunk_size 4096;

        application stream {
            live on;
            record off;
            allow publish all;
            allow play all;

            hls on;
            hls_path /tmp/hls;
            hls_fragment 2s;
            hls_playlist_length 8s;
            hls_cleanup on;
            hls_continuous on;
            hls_nested on;
        }
    }
}

http {
    server {
        listen 80;

        location /hls/ {
            alias /tmp/hls/;

            types {
                application/vnd.apple.mpegurl m3u8;
                video/mp2t ts;
            }

            # CORS headers
            add_header Access-Control-Allow-Origin "*" always;
            add_header Access-Control-Allow-Methods "GET, HEAD, OPTIONS" always;
            add_header Access-Control-Allow-Headers "*" always;

            location ~ \.m3u8$ {
                add_header Cache-Control "no-cache" always;
            }

            location ~ \.ts$ {
                add_header Cache-Control "public, max-age=2" always;
                expires 2s;
            }
        }
    }
}