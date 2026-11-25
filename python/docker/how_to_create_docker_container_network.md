# set ip route on MYSQL

sudo iptables -I INPUT 1 \
  -s 172.24.0.0/16 \
  -p tcp \
  --dport 3306 \
  -j ACCEPT 

# set ip rout on Redis

sudo iptables -I INPUT 1 \
  -s 172.24.0.0/16 \
  -p tcp \
  --dport 6379 \
  -j ACCEPT

# create user and add database user privilege

ALTER USER 'olee_admin'@'172.24.%'
IDENTIFIED BY 'YOUR_DB_PASSWORD';

GRANT ALL PRIVILEGES
ON staypay_db.*
TO 'olee_admin'@'172.24.%';

FLUSH PRIVILEGES;

# create docker compose and specify the network

services:

  staypay:
    image: 0788644687/staypay:latest
    container_name: staypay
    restart: unless-stopped
    env_file:
      - .env
    ports:
      - "127.0.0.1:8090:80"
    extra_hosts:
      - "host.docker.internal:host-gateway"
    volumes:
      - ./logs:/var/www/html/logs
    networks:
      - staypay_net
    healthcheck:
      test:
        [
          "CMD-SHELL",
          "curl -fsS http://127.0.0.1/health || exit 1"
        ]
      interval: 30s
      timeout: 5s
      retries: 3
      start_period: 20s

  callback-worker:
    image: 0788644687/staypay:latest
    container_name: staypay-callback-worker
    restart: unless-stopped
    env_file:
      - .env
    extra_hosts:
      - "host.docker.internal:host-gateway"
    volumes:
      - ./logs:/var/www/html/logs
    networks:
      - staypay_net
    command:
      - php
      - worker/callback_worker.php

networks:
  staypay_net:
    external: true



# then pull image and run via that net work

docker compose down
docker network rm staypay_default
docker network create --driver bridge --subnet 172.24.0.0/16 --gateway 172.24.0.1 staypay_net
docker compose up -d

# check the running docker network

docker inspect staypay \
  --format '{{range .NetworkSettings.Networks}}{{.NetworkID}} {{.IPAddress}} {{.Gateway}}{{end}}'

