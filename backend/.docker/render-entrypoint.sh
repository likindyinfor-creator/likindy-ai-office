#!/bin/sh
set -eu

: "${PORT:=10000}"
case "$PORT" in
    ''|*[!0-9]*) echo "PORT must be a numeric TCP port." >&2; exit 1 ;;
esac
if [ "$PORT" -lt 1 ] || [ "$PORT" -gt 65535 ]; then
    echo "PORT must be between 1 and 65535." >&2
    exit 1
fi

mkdir -p \
    /var/www/html/storage/framework/cache/data \
    /var/www/html/storage/framework/sessions \
    /var/www/html/storage/framework/views \
    /var/www/html/storage/logs \
    /var/www/html/bootstrap/cache
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

if [ "$#" -gt 0 ]; then
    if [ "${1:-}" = "php" ] && [ "${2:-}" = "artisan" ] && [ "${3:-}" = "queue:work" ]; then
        exec "$@"
    fi

    echo "Unsupported container command. Only the queue worker may override the web server." >&2
    exit 64
fi

printf 'Listen %s\n' "$PORT" > /etc/apache2/ports.conf
cat > /etc/apache2/sites-available/000-default.conf <<EOF
<VirtualHost *:${PORT}>
    DocumentRoot /var/www/html/public
    <Directory /var/www/html/public>
        AllowOverride All
        Options FollowSymLinks
        Require all granted
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/error.log
    CustomLog \${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
EOF

printf '%s\n' '=== APACHE RUNTIME DIAGNOSTICS ==='
printf '%s\n' 'Enabled module directory entries (symlink targets included):'
ls -la /etc/apache2/mods-enabled/ 2>&1 || true

printf '%s\n' 'Loaded Apache modules (apache2ctl -M):'
if apache2ctl -M 2>&1; then
    :
else
    diagnostic_status=$?
    printf 'apache2ctl -M failed with exit status %s; continuing startup attempt.\n' "$diagnostic_status"
fi

printf '%s\n' 'Apache/PHP/MPM environment variable names:'
env | cut -d= -f1 | grep -Ei 'apache|php|mpm' || true

printf '%s\n' 'MPM LoadModule directives in Apache configuration:'
grep -RniE '^[[:space:]]*LoadModule[[:space:]]+mpm_[[:alnum:]_]+_module([[:space:]]|$)' \
    /etc/apache2 2>&1 || true

exec apache2-foreground
