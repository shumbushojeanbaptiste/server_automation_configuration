# Docker Commands Cheat Sheet

This is a step-by-step guide to common Docker commands for future reference.

## 1. View Running Containers
- **Command**: `docker ps`
- **Description**: Shows all currently running containers.
- **Usage**: Run this to see active containers and their details like container ID, image, status, etc.

## 2. View All Containers
- **Command**: `docker ps -a`
- **Description**: Shows all containers, including those that are stopped or exited.
- **Usage**: Use this to see the history of all containers created on your system.

## 3. Stop a Running Container
- **Command**: `docker stop <container_id>`
- **Description**: Stops a running container gracefully.
- **Usage**: Replace `<container_id>` with the actual container ID from `docker ps`. This sends a SIGTERM signal to stop the container.

## 4. Remove a Container
- **Command**: `docker rm <container_id>`
- **Description**: Removes a stopped container from your system.
- **Usage**: First stop the container if it's running, then use this command. Replace `<container_id>` with the container ID.

## 5. List Docker Images
- **Command**: `docker images`
- **Description**: Shows all Docker images stored locally on your machine.
- **Usage**: This lists images you've pulled or built, including their repository, tag, image ID, and size.

## 6. Remove a Docker Image
- **Command**: `docker rmi <image_id>`
- **Description**: Removes a Docker image from your local system.
- **Usage**: Replace `<image_id>` with the image ID from `docker images`. Note: You cannot remove an image if it's being used by a running container.

## 7. Pull a Docker Image
- **Command**: `docker pull <repository>/<imagename>:<tag>`
- **Description**: Downloads a Docker image from a registry (like Docker Hub).
- **Usage**: Replace `<repository>/<imagename>:<tag>` with the actual image details. For example: `docker pull ubuntu:latest`. If no tag is specified, it defaults to `latest`.

## Additional Tips
- Always check container/image IDs using `docker ps` or `docker images` before stopping/removing.
- Use `docker --help` or `docker <command> --help` for more options on any command.
- Be cautious with `docker rm` and `docker rmi` as they permanently delete containers and images.