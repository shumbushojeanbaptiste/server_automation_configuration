server {
    # Listen directives without protocol options
    listen 80;
    listen [::]:80;
    listen 443 ssl;
    listen [::]:443 ssl;
    listen 443 quic;
    listen [::]:443 quic;
    
    # HTTP2 and HTTP3 as separate directives
    http2 on;
    http3 on;
    
    # QUIC specific settings
    quic_retry on;
    add_header Alt-Svc 'h3=":443"; ma=86400';
    
    {{ssl_certificate_key}}
    {{ssl_certificate}}
    server_name m.staystore.online;
    {{root}}
    {{nginx_access_log}}
    {{nginx_error_log}}

    # ADD THIS LINE - Specify default index files
    index index.php index.html index.htm;

    # Redirect HTTP to HTTPS
    if ($scheme != "https") {
        return 301 https://$server_name$request_uri;
    }

    # Fixed typo
    location ~ /.well-known {
        auth_basic off;
        allow all;
    }

    {{settings}}

    # Main location block
    location / {
        try_files $uri $uri/ @php_router;
    }

    location @php_router {
        # Use map or if for routing
        set $router_script "/v1.0/index.php";
        if ($uri ~ ^/store/) {
            set $router_script "/store/index.php";
        }
        if ($uri ~ ^/_api/) {
            set $router_script "/_api/index.php";
        }
         
        rewrite ^ $router_script?route=$uri&$query_string last;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
        fastcgi_param REQUEST_URI $request_uri;
        fastcgi_param ROUTE $uri;
        fastcgi_pass 127.0.0.1:{{php_fpm_port}};
        fastcgi_index index.php;
        fastcgi_intercept_errors on;
    }

    location ~* \.(css|js|jpg|jpeg|gif|png|ico|gz|svg|svgz|ttf|otf|woff|woff2|eot|mp4|ogg|ogv|webm|webp|zip|swf|map|mjs)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    location ~ /\. {
        deny all;
    }
}