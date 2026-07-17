daemon off;

error_log /dev/stdout info;

events {
    worker_connections 1024;
}

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

    allow publish all;
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
