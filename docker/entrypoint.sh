#!/bin/sh
set -e

# Canonical links, the sitemap, sharing previews and e-mail links are all built
# from APP_URL, so a wrong value quietly sends search engines elsewhere. Refuse
# to start in production until it is the store's real https address.
if [ "${APP_ENV:-production}" = "production" ]; then
    case "$APP_URL" in
        https://*) ;;
        *)
            echo "APP_URL must be the store's https:// address (now: '${APP_URL}')." >&2
            exit 1
            ;;
    esac

    if [ "$APP_URL" = "https://store.example.com" ]; then
        echo "APP_URL is still the example value. Set it to the store's domain in .env." >&2
        exit 1
    fi

    host="${APP_URL#https://}"
    host="${host%%/*}"

    if [ -n "$SERVER_NAME" ] && [ "$host" != "$SERVER_NAME" ]; then
        echo "APP_URL (${host}) and SERVER_NAME (${SERVER_NAME}) must name the same domain." >&2
        exit 1
    fi
fi

# Secrets arrive at run time, so config can only be cached when the container starts.
php artisan optimize --no-ansi

exec "$@"
