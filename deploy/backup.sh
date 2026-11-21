#!/usr/bin/env bash
# Daily backup of the database and uploaded files.
#
# 1. A local copy in $BACKUP_PATH, kept 14 days, for quick restores.
# 2. When backup.env exists next to this script, an encrypted off-site copy in
#    S3-compatible storage (DigitalOcean Spaces) made with restic. The server can
#    be lost entirely and the store still restored. See backup.env.example.
#
# Cron example: 30 2 * * * /opt/store/backup.sh >> /var/log/store-backup.log 2>&1
# Monthly restore check: /opt/store/backup.sh verify
set -euo pipefail

cd "$(dirname "$0")"
target="${BACKUP_PATH:-/opt/store-backups}"
restic_image="restic/restic:0.17.3"

restic() {
    docker run --rm -i --env-file backup.env -v "$target:/backups:ro" "$restic_image" "$@"
}

if [ "${1:-}" = "verify" ]; then
    # Reads back a tenth of the stored data and checks it decrypts intact.
    restic check --read-data-subset=10%
    restic snapshots --latest 3
    exit 0
fi

stamp="$(date -u +%Y%m%d-%H%M%S)"
mkdir -p "$target"
chmod 700 "$target"

docker compose exec -T mysql sh -c \
    'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -uroot --single-transaction --routines --no-tablespaces "$MYSQL_DATABASE"' \
    | gzip > "$target/db-$stamp.sql.gz.partial"
mv "$target/db-$stamp.sql.gz.partial" "$target/db-$stamp.sql.gz"

docker compose exec -T app tar -czf - -C /app/storage/app . > "$target/files-$stamp.tar.gz.partial"
mv "$target/files-$stamp.tar.gz.partial" "$target/files-$stamp.tar.gz"

find "$target" -type f -name '*.gz' -mtime +14 -delete

if [ -f backup.env ]; then
    chmod 600 backup.env
    # Creates the encrypted repository on the first run only.
    restic cat config > /dev/null 2>&1 || restic init
    restic backup --host store --tag daily "/backups/db-$stamp.sql.gz" "/backups/files-$stamp.tar.gz"
    restic forget --host store --keep-daily 14 --keep-weekly 8 --keep-monthly 12 --prune
    echo "Off-site copy stored"
else
    echo "WARNING: no backup.env, the backup exists only on this server" >&2
fi

echo "Backup finished: $stamp"
