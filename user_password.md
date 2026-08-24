ALTER USER 'gm-user'@'%' IDENTIFIED WITH caching_sha2_password BY 'M00dle!!';
FLUSH PRIVILEGES;


services:

  # ==========================================================================
  # PROMETHEUS
  # ==========================================================================
  prometheus:
    image: prom/prometheus:v3.13.2
    container_name: prometheus
    restart: unless-stopped

    command:
      - "--config.file=/etc/prometheus/prometheus.yml"
      - "--storage.tsdb.path=/prometheus"
      - "--storage.tsdb.retention.time=30d"
      - "--storage.tsdb.wal-compression"

    ports:
      - "0.0.0.0:9090:9090"

    volumes:
      - prometheus_data:/prometheus
      - ./prometheus/prometheus.yml:/etc/prometheus/prometheus.yml:ro
    depends_on:
      - mysqld-exporter
      - node-exporter

    extra_hosts:
      - "host.docker.internal:host-gateway"

    networks:
      - monitoring

    healthcheck:
      test:
        [
          "CMD",
          "wget",
          "--spider",
          "-q",
          "http://localhost:9090/-/ready"
        ]
      interval: 30s
      timeout: 5s
      retries: 3
      start_period: 20s


  # ==========================================================================
  # GRAFANA
  # ==========================================================================
  grafana:
    image: grafana/grafana:12.4.5
    container_name: grafana
    restart: unless-stopped

    environment:
      GF_SERVER_HTTP_PORT: 3000
      GF_SERVER_DOMAIN: ${GRAFANA_DOMAIN}
      GF_SERVER_ROOT_URL: ${GRAFANA_ROOT_URL}

      GF_SECURITY_ADMIN_USER: ${GRAFANA_ADMIN_USER}
      GF_SECURITY_ADMIN_PASSWORD: ${GRAFANA_ADMIN_PASSWORD}

      GF_USERS_ALLOW_SIGN_UP: "false"
      GF_USERS_ALLOW_ORG_CREATE: "false"

      GF_SECURITY_DISABLE_GRAVATAR: "true"
      GF_ANALYTICS_REPORTING_ENABLED: "false"
      GF_ANALYTICS_CHECK_FOR_UPDATES: "false"

      GF_LOG_MODE: "console"
      GF_LOG_LEVEL: "info"

    ports:
      - "127.0.0.1:3001:3000"

    volumes:
      - grafana_data:/var/lib/grafana

    depends_on:
      prometheus:
        condition: service_healthy

    networks:
      - monitoring

    healthcheck:
      test:
        [
          "CMD",
          "wget",
          "--spider",
          "-q",
          "http://localhost:3000/api/health"
        ]
      interval: 30s
      timeout: 5s
      retries: 5
      start_period: 30s


  # ==========================================================================
  # NODE EXPORTER
  # ==========================================================================
  node-exporter:
    image: prom/node-exporter:v1.10.2
    container_name: node-exporter
    restart: unless-stopped

    command:
      - "--path.rootfs=/host"

    volumes:
      - "/:/host:ro,rslave"

    networks:
      - monitoring


  # ==========================================================================
  # MYSQL EXPORTER
  # ==========================================================================
  mysqld-exporter:
    image: prom/mysqld-exporter:v0.17.2
    container_name: mysqld-exporter
    restart: unless-stopped
    command:
      - "--config.my-cnf=/cfg/.my.cnf"
    volumes:
      - ./mysql/.my.cnf:/cfg/.my.cnf:ro"

    extra_hosts:
      - "host.docker.internal:host-gateway"

    networks:
      - monitoring


# ============================================================================
# PERSISTENT STORAGE
# ============================================================================
volumes:

  prometheus_data:
    name: rayonplus_prometheus_data

  grafana_data:
    name: rayonplus_grafana_data


# ============================================================================
# NETWORK
# ============================================================================
networks:

  monitoring:
    name: rayonplus_monitoring
    driver: bridge