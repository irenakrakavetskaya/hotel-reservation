#!/bin/sh
set -eu
set -o pipefail

umask 077
export LC_ALL=C

project="${COMPOSE_PROJECT_NAME:?COMPOSE_PROJECT_NAME is required}"
case "$project" in
    *[!a-z0-9_-]*|'') echo "Invalid Compose project name" >&2; exit 1 ;;
esac
max_bytes="${LOG_MAX_BYTES:-10485760}"
case "$max_bytes" in
    *[!0-9]*|'') echo "LOG_MAX_BYTES must be a positive integer" >&2; exit 1 ;;
esac
max_bytes=$((10#$max_bytes))
if [ "$max_bytes" -le 0 ]; then
    echo "LOG_MAX_BYTES must be a positive integer" >&2
    exit 1
fi

file="/logs/compose-${project}.log"
for path in "$file" "$file.1" "$file.2" "$file.3"; do
    if [ -L "$path" ]; then
        echo "Log paths must not be symbolic links: $path" >&2
        exit 1
    fi
done

touch "$file"
chown "$(stat -c '%u:%g' /logs)" "$file"
chmod 600 "$file"
size="$(wc -c < "$file")"

docker compose -p "$project" -f /project/docker-compose.yml logs \
    --follow --timestamps --no-color |
while IFS= read -r line || [ -n "$line" ]; do
    line_size=$((${#line} + 1))
    if [ "$size" -gt 0 ] && [ $((size + line_size)) -gt "$max_bytes" ]; then
        [ ! -e "$file.2" ] || mv -f "$file.2" "$file.3"
        [ ! -e "$file.1" ] || mv -f "$file.1" "$file.2"
        mv -f "$file" "$file.1"
        touch "$file"
        chown "$(stat -c '%u:%g' /logs)" "$file"
        chmod 600 "$file"
        size=0
    fi
    printf '%s\n' "$line" >> "$file"
    size=$((size + line_size))
done
