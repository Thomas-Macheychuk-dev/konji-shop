#!/usr/bin/env bash
set -euo pipefail

APP_USER="${APP_USER:-ubuntu}"
APP_PATH="${APP_PATH:-/var/www/konji-shop}"

if [ "$(id -u)" -ne 0 ]; then
  echo "Run this script as root, for example: sudo APP_PATH=${APP_PATH} $0" >&2
  exit 1
fi

apt-get update
apt-get install -y ca-certificates curl gnupg git unzip jq

install -m 0755 -d /etc/apt/keyrings
if [ ! -f /etc/apt/keyrings/docker.gpg ]; then
  curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
  chmod a+r /etc/apt/keyrings/docker.gpg
fi

. /etc/os-release
cat >/etc/apt/sources.list.d/docker.list <<DOCKER_REPO
deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu ${VERSION_CODENAME} stable
DOCKER_REPO

apt-get update
apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

usermod -aG docker "${APP_USER}"
install -d -o "${APP_USER}" -g "${APP_USER}" "${APP_PATH}"

systemctl enable docker
systemctl restart docker

echo "EC2 bootstrap completed. Log out and back in so ${APP_USER} can use docker without sudo."
echo "Application path: ${APP_PATH}"
