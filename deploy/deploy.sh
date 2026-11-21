#!/usr/bin/env bash
# Builds the image here and ships it to the Droplet, so the server never builds.
# Usage: DEPLOY_HOST=root@203.0.113.10 deploy/deploy.sh
set -euo pipefail

# Stops Git Bash on Windows rewriting Linux paths in remote commands.
export MSYS_NO_PATHCONV=1

: "${DEPLOY_HOST:?Set DEPLOY_HOST, for example root@203.0.113.10}"
DEPLOY_PATH="${DEPLOY_PATH:-/opt/store}"
cd "$(dirname "$0")/.."

revision="$(git rev-parse --short HEAD)"
image="store:${revision}"

if [ -n "$(git status --porcelain)" ]; then
    echo "Commit your changes first: the image must match ${revision}." >&2
    exit 1
fi

docker build --platform linux/amd64 --tag "$image" .

echo "Sending ${image} to ${DEPLOY_HOST}"
docker save "$image" | gzip | ssh "$DEPLOY_HOST" 'gunzip | docker load'
scp deploy/compose.yaml deploy/backup.sh deploy/backup.env.example "${DEPLOY_HOST}:${DEPLOY_PATH}/"

ssh "$DEPLOY_HOST" "cd '${DEPLOY_PATH}' \
    && test -f .env \
    && chmod +x backup.sh \
    && export APP_IMAGE='${image}' \
    && sed -i '/^APP_IMAGE=/d' .env && echo \"APP_IMAGE=\${APP_IMAGE}\" >> .env \
    && docker compose up -d --wait mysql redis \
    && docker compose run --rm --no-deps app php artisan migrate --force --no-ansi \
    && docker compose up -d --wait --remove-orphans \
    && docker image prune -f --filter 'until=168h' > /dev/null"

echo "Deployed ${image}"
