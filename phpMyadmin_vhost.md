root@srv1158437:~# cat /etc/nginx/sites-enabled/db.oleeplay.com.conf
server {
 listen 80;
 listen [::]:80;
 server_name db.oleeplay.com;

 location ^~ /.well-known/acme-challenge/ {
 auth_basic off;
 allow all;
 default_type "text/plain";
 root /var/www/letsencrypt;
 try_files $uri =404;
 }

 location / {
 return 301 https://$host$request_uri;
 }
}

server {
 listen 443 ssl;
 listen [::]:443 ssl;
 http2 on;

 server_name db.oleeplay.com;

 ssl_certificate /etc/nginx/ssl-certificates/db.oleeplay.com.crt;
 ssl_certificate_key /etc/nginx/ssl-certificates/db.oleeplay.com.key;

 root /usr/share/phpmyadmin;
 index index.php index.html;

 client_max_body_size 64M;

 location / {
 try_files $uri $uri/ /index.php?$args;
 }

 location ~ \.php$ {
 include fastcgi_params;
 fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
 fastcgi_param PATH_INFO $fastcgi_path_info;
 fastcgi_index index.php;

 # Common PHP-FPM socket (adjust if different on your VPS)
 #fastcgi_pass unix:/run/php/php8.2-fpm.sock;
 fastcgi_pass 127.0.0.1:17000;
 }

 location ~* \.(?:css|js|jpg|jpeg|gif|png|svg|ico|ttf|woff|woff2)$ {
 expires 7d;
 access_log off;
 add_header Cache-Control "public";
 }

 location ~ /\. {
 deny all;
 }
}