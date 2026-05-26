# Docker Container Management Guide

## Purpose

This document provides clear, reusable best practices for running, monitoring, and maintaining Docker container workloads. It is intended for day-one operations and future handoff documentation.

---

## 1) Start a container

Command:

    docker run -d --name <container_name> --restart=unless-stopped <image_name>:<tag>

Explanation:

- `-d`: detached mode (background)
- `--name`: user-friendly container name
- `--restart=unless-stopped`: restart after crash/server reboot; do not restart if manually stopped
- `<image_name>:<tag>`: image reference (`latest` or specific tag)

Example:

    docker run -d --name my_api --restart=unless-stopped my_api_image:latest

---

## 2) Change restart policy for an existing container

Set the policy:

    docker update --restart=unless-stopped <container_id_or_name>

Alternative:

    docker update --restart=always <container_id_or_name>

Policy behavior:

- `always`: restart in all cases (including manual stop)
- `unless-stopped`: restart unless manually stopped
- `no`: never restart

---

## 3) Inspect container status

- List all containers (running + stopped):

      docker ps -a

- List running containers only:

      docker ps

- Inspect restart policy:

      docker inspect <container_id_or_name> --format '{{json .HostConfig.RestartPolicy}}'

---

## 4) Logs and troubleshooting

- Show logs from a container:

      docker logs <container_id_or_name>

- Tail the last N lines:

      docker logs --tail 100 <container_id_or_name>

- Follow logs live:

      docker logs -f <container_id_or_name>

- Restart loops / crashes:
  1. check logs
  2. check exit code:

      docker inspect <container_id_or_name> --format '{{.State.ExitCode}}'

  3. validate the process command in container:

      docker inspect <container_id_or_name> --format '{{.Config.Cmd}}'

---

## 5) Stop and remove container

- Stop:

      docker stop <container_id_or_name>

- Remove:

      docker rm <container_id_or_name>

> ⚠️ If `--restart=always`, a stopped container may restart immediately unless you change restart policy first.

---

## 6) Recommended production patterns

- Prefer `--restart=unless-stopped` for service containers.
- Add resource constraints for stability:

      docker run ... --cpus 1 --memory 512m

- Manage logs to prevent disk exhaustion:

      docker run ... --log-opt max-size=10m --log-opt max-file=3

- Use `HEALTHCHECK` in Dockerfile and validate service health:

    HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD curl -f http://localhost:3000/health || exit 1

- Keep images short-lived and tagged with immutable versions (avoid `latest` in prod).
- Document container purpose, ingress, and secret source in operations playbook.

---

## 7) Example operational workflow

1. Build image:

       docker build -t my_api_image:1.0.0 .

2. Run container:

       docker run -d --name my_api --restart=unless-stopped my_api_image:1.0.0

3. Confirm status:

       docker ps

4. If the container exits unexpectedly:

       docker logs my_api --tail 50
       docker inspect my_api --format '{{.State}}'

5. Update restart policy (if needed):

       docker update --restart=unless-stopped my_api

6. For maintenance updates:

       docker stop my_api
       docker rm my_api
       docker run -d --name my_api --restart=unless-stopped my_api_image:1.0.1

---

## 8) Future-proof checklist

- Store commands in scripts or runbooks (PowerShell/Bash) with clear variable names.
- Use tagging: `my_api_image:release-2026-03-26`.
- Keep the guide under version control (this file) and update when policy changes.
- Include a monitoring alert: `container crash loop` and `log disk usage`.
- Add `docker system prune --volumes` policy for periodic cleanup (scheduled maintenance, not auto-run in prod).
