# Kafka Deployment & Kafka UI — Quick Reference

## 1. Server Details

| Item | Value |
|---|---|
| Server | `31.97.215.87` |
| Kafka container | `central-kafka` |
| Kafka version | `apache/kafka:4.0.1` |
| Kafka UI | `http://31.97.215.87:8085/` |
| External broker | `31.97.215.87:9092` |
| Internal broker (Docker) | `central-kafka:9095` |
| Docker network | `kafka-shared` |
| Persistent data | `/data/kafka` |
| Compose directory | `/opt/kafka` |

## 2. Connect Kafka UI

1. Open `http://31.97.215.87:8085/` in your browser.
2. Open the Kafka cluster configuration.
3. Set **Bootstrap Servers** to:

   `central-kafka:9095`

4. Ensure Kafka UI and Kafka share the `kafka-shared` Docker network.
5. Save the configuration and check the **Topics** section.

**Important:** Kafka UI uses the internal Docker address. Applications running outside this Docker network use `31.97.215.87:9092`.

## 3. Current Topics

- `sms.dispatch.v1` — SMS dispatch events.
- `wallet.topup.confirmed.v1` — confirmed wallet top-ups.

Both topics are configured with 3 partitions and replication factor 1.

## 4. Common Commands

Run these commands on the server:

```bash
cd /opt/kafka
```

**Check container status**

```bash
docker ps --filter name=central-kafka
```

**View Kafka logs**

```bash
docker logs --tail 100 central-kafka
```

**List topics**

```bash
docker exec central-kafka \
  /opt/kafka/bin/kafka-topics.sh \
  --bootstrap-server central-kafka:9095 \
  --list
```

**Describe a topic**

```bash
docker exec central-kafka \
  /opt/kafka/bin/kafka-topics.sh \
  --bootstrap-server central-kafka:9095 \
  --describe --topic sms.dispatch.v1
```

**Restart Kafka**

```bash
cd /opt/kafka
docker compose restart kafka
```

**Verify persistent storage**

```bash
ls -lah /data/kafka
```

## 5. Create a Topic

Example for a new topic:

```bash
docker exec central-kafka \
  /opt/kafka/bin/kafka-topics.sh \
  --bootstrap-server central-kafka:9095 \
  --create \
  --topic example.events.v1 \
  --partitions 3 \
  --replication-factor 1
```

Use a unique topic name and verify it afterward with `--list`.

## 6. Troubleshooting

| Problem | What to check |
|---|---|
| Kafka UI cannot connect | Confirm Kafka is running and the UI uses `central-kafka:9095`. |
| No topics appear | Run the topic-list command and verify the application is using the correct broker. |
| Kafka fails to start | Check `docker logs central-kafka` and `/data/kafka` permissions. |
| External application cannot connect | Check firewall rules, public port `9092`, and the broker's advertised listener. |
| Kafka data disappears after recreation | Verify `KAFKA_LOG_DIRS=/var/lib/kafka/data` and the `/data/kafka` volume mount. |

## 7. Important Production Rules

- **Never delete `/data/kafka`** to troubleshoot Kafka; it contains persistent Kafka data.
- Keep `KAFKA_LOG_DIRS=/var/lib/kafka/data` configured in Docker Compose.
- Back up Kafka data and configuration before making major changes.
- Replication factor 1 does not protect against broker or disk failure.
- Port `9092` uses plaintext in the current configuration. Restrict access with firewall rules or a VPN, and plan authentication/TLS before exposing it broadly.
- Recreating topics does not restore historical messages or consumer offsets that have already been lost.

**Quick health check:** Confirm the container is running, Kafka UI connects through `central-kafka:9095`, and both expected topics appear in the topic list.