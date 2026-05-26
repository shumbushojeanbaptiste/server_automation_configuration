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
    server_name m.staystore.online;
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

    # Main location block for routing
    location / {
        # Check if file exists, if not pass to index.php for routing
        try_files $uri $uri/ /v1.0/index.php?route=$uri&$query_string;
        
        {{varnish_proxy_pass}}
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_hide_header X-Varnish;
        proxy_redirect off;
    }

    # Handle PHP files through PHP-FPM
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
        fastcgi_param ROUTE $uri;
        fastcgi_pass 127.0.0.1:{{php_fpm_port}};
        fastcgi_index index.php;
        fastcgi_intercept_errors on;
    }

    location ~* ^.+\.(css|js|jpg|jpeg|gif|png|ico|gz|svg|svgz|ttf|otf|woff|woff2|eot|mp4|ogg|ogv|webm|webp|zip|swf|map|mjs)$ {
        add_header Access-Control-Allow-Origin "*";
        add_header alt-svc 'h3=":443"; ma=86400';
        expires max;
        access_log off;
    }

    location ~ /\.(ht|svn|git) {
        deny all;
    }
}

server {
    listen 8080;
    listen [::]:8080;
    server_name m.staystore.online;
    {{root}}

    include /etc/nginx/global_settings;

    index index.php index.html;

    # Main location for routing
    location / {
        try_files $uri $uri/ /v1.0/index.php?route=$uri&$query_string;
    }

    # Handle PHP files
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
        fastcgi_param ROUTE $uri;
        fastcgi_pass 127.0.0.1:{{php_fpm_port}};
        fastcgi_index index.php;
        fastcgi_intercept_errors on;
    }
    
}