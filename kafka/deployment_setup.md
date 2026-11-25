# Apache Kafka Deployment Guide
**Server:** `srv1158437`  
**Kafka version:** Apache Kafka 4.0.1  
**Deployment:** Docker Compose, KRaft mode  
**Purpose:** Central messaging for SMS and other microservices.

## 1. Prerequisites

- Ubuntu VPS with Docker and Docker Compose.
- WireGuard VPN configured with VPS IP `10.10.0.1` and client IP `10.10.0.2`.
- Kafka data directory: `/data/kafka`.
- Deployment directory: `/opt/kafka`.

Verify WireGuard and Docker:

```bash
ip addr show wg0
docker --version
docker compose version
```

## 2. Configure Kafka

Create `/opt/kafka/docker-compose.yml`:

```yaml
services:
  kafka:
    image: apache/kafka:4.0.1
    container_name: central-kafka
    restart: unless-stopped

    ports:
      - "10.10.0.1:9092:9092"

    environment:
      KAFKA_NODE_ID: 1
      KAFKA_PROCESS_ROLES: broker,controller

      KAFKA_LISTENERS: INTERNAL://:9092,CONTROLLER://:9093,LOCAL://:9094
      KAFKA_ADVERTISED_LISTENERS: INTERNAL://10.10.0.1:9092,LOCAL://localhost:9094
      KAFKA_LISTENER_SECURITY_PROTOCOL_MAP: INTERNAL:PLAINTEXT,CONTROLLER:PLAINTEXT,LOCAL:PLAINTEXT

      KAFKA_INTER_BROKER_LISTENER_NAME: INTERNAL
      KAFKA_CONTROLLER_LISTENER_NAMES: CONTROLLER
      KAFKA_CONTROLLER_QUORUM_VOTERS: 1@localhost:9093

      KAFKA_OFFSETS_TOPIC_REPLICATION_FACTOR: 1
      KAFKA_TRANSACTION_STATE_LOG_REPLICATION_FACTOR: 1
      KAFKA_TRANSACTION_STATE_LOG_MIN_ISR: 1

      KAFKA_NUM_PARTITIONS: 3
      KAFKA_GROUP_INITIAL_REBALANCE_DELAY_MS: 0

    volumes:
      - /data/kafka:/var/lib/kafka/data
```

**Important:** Back up the existing configuration before changing it. Never delete `/data/kafka` to troubleshoot Kafka.

## 3. Start Kafka

```bash
cd /opt/kafka
docker compose config
docker compose up -d
docker ps --filter name=central-kafka
docker logs --tail 100 central-kafka
```

Confirm that the logs show Kafka started successfully and listeners are accepting connections.

## 4. Test Kafka locally on the VPS

Use the dedicated local listener on port `9094`:

```bash
docker exec central-kafka \
  /opt/kafka/bin/kafka-broker-api-versions.sh \
  --bootstrap-server 127.0.0.1:9094
```

If this succeeds, Kafka is responding to protocol requests inside the container.

## 5. Create application topics

Create the SMS dispatch topic:

```bash
docker exec central-kafka \
  /opt/kafka/bin/kafka-topics.sh \
  --bootstrap-server 127.0.0.1:9094 \
  --create --if-not-exists \
  --topic sms.dispatch.v1 \
  --partitions 3 \
  --replication-factor 1
```

Create the wallet top-up topic:

```bash
docker exec central-kafka \
  /opt/kafka/bin/kafka-topics.sh \
  --bootstrap-server 127.0.0.1:9094 \
  --create --if-not-exists \
  --topic wallet.topup.confirmed.v1 \
  --partitions 3 \
  --replication-factor 1
```

Verify the topics:

```bash
docker exec central-kafka \
  /opt/kafka/bin/kafka-topics.sh \
  --bootstrap-server 127.0.0.1:9094 \
  --list
```

Expected:

```text
sms.dispatch.v1
wallet.topup.confirmed.v1
```

## 6. Connect local Go applications

Ensure WireGuard is connected on Windows, then run PowerShell:

```powershell
Test-NetConnection 10.10.0.1 -Port 9092
```

Expected:

```text
TcpTestSucceeded : True
```

In each local microservice `.env` file:

```env
KAFKA_BROKERS=10.10.0.1:9092
```

Ensure your Go code reads this variable and passes it to the Kafka client.

Test the Kafka protocol from Windows:

```powershell
.\bin\windows\kafka-topics.bat --bootstrap-server 10.10.0.1:9092 --list
```

Adjust the command path to match your Kafka CLI installation.

## 7. Troubleshooting

| Problem | What to check |
|---|---|
| Kafka container stopped | `docker ps -a` and `docker logs central-kafka` |
| TCP connection fails | WireGuard, firewall, listener binding and routing |
| TCP works but Kafka CLI times out | Advertised listeners, Kafka logs and broker protocol connectivity |
| Outbox publishing times out | Producer configuration, metadata, broker acknowledgements and delivery errors |
| Consumer group list is empty | Check that a consumer has started with a group ID |

View consumer groups:

```bash
docker exec central-kafka \
  /opt/kafka/bin/kafka-consumer-groups.sh \
  --bootstrap-server 127.0.0.1:9094 \
  --list
```

An empty list is normal if no consumer groups have been established.

## 8. Production safety

- Keep controller port `9093` and local administration port `9094` unpublished to external networks.
- Restrict port `9092` to trusted WireGuard clients.
- Kafka currently uses a single broker with replication factor `1`; this does not provide broker redundancy.
- Plan backups and recovery testing for `/data/kafka`.
- PLAINTEXT has no Kafka-level authentication or encryption. WireGuard encrypts traffic through the VPN, but access controls are still important.

## Deployment checklist

- [ ] Kafka container is running.
- [ ] Kafka protocol test succeeds on `127.0.0.1:9094`.
- [ ] Both application topics exist.
- [ ] Windows TCP test succeeds on `10.10.0.1:9092`.
- [ ] Kafka CLI works from Windows.
- [ ] Go services publish and consume messages successfully.
- [ ] Logs show no repeated publishing timeouts.
