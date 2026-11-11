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

for module in mpm_event mpm_worker; do
    rm -f "/etc/apache2/mods-enabled/${module}.load" \
        "/etc/apache2/mods-enabled/${module}.conf"
done

a2enmod mpm_prefork

enabled_mpm_count=0
enabled_mpm=
for module_file in /etc/apache2/mods-enabled/*.load; do
    [ -e "$module_file" ] || continue
    module=${module_file##*/}
    case "$module" in
        mpm_*.load)
            enabled_mpm_count=$((enabled_mpm_count + 1))
            enabled_mpm=$module
            ;;
    esac
done

if [ "$enabled_mpm_count" -ne 1 ] || [ "$enabled_mpm" != "mpm_prefork.load" ]; then
    printf 'Apache startup aborted: expected only mpm_prefork.load, found %s MPM module(s); last found: %s\n' \
        "$enabled_mpm_count" "${enabled_mpm:-none}" >&2
    ls -la /etc/apache2/mods-enabled/ >&2 || true
    exit 1
fi

if ! apache2ctl -t >/dev/null 2>&1; then
    printf '%s\n' 'Apache startup aborted: apache2ctl -t configuration validation failed.' >&2
    exit 1
fi

if ! loaded_modules=$(apache2ctl -M 2>&1); then
    printf '%s\n' 'Apache startup aborted: apache2ctl -M could not list loaded modules.' >&2
    exit 1
fi

loaded_mpms=$(printf '%s\n' "$loaded_modules" \
    | awk '/^[[:space:]]*mpm_[[:alnum:]_]+_module[[:space:]]+\((shared|static)\)$/ { sub(/^[[:space:]]+/, ""); print $1 }')
loaded_mpm_count=$(printf '%s\n' "$loaded_mpms" | awk 'NF { count++ } END { print count + 0 }')

if [ "$loaded_mpm_count" -ne 1 ] || [ "$loaded_mpms" != "mpm_prefork_module" ]; then
    printf 'Apache startup aborted: expected only mpm_prefork_module; found %s loaded MPM module(s): %s\n' \
        "$loaded_mpm_count" "${loaded_mpms:-none}" >&2
    exit 1
fi

printf '%s\n' '=== APACHE RUNTIME DIAGNOSTICS ==='
printf '%s\n' 'Enabled module directory entries (symlink targets included):'
ls -la /etc/apache2/mods-enabled/ 2>&1 || true

printf 'Loaded Apache MPM: %s\n' "$loaded_mpms"

printf '%s\n' 'Apache/PHP/MPM environment variable names:'
env | cut -d= -f1 | grep -Ei 'apache|php|mpm' || true

printf '%s\n' 'MPM LoadModule directives in Apache configuration:'
grep -RniE '^[[:space:]]*LoadModule[[:space:]]+mpm_[[:alnum:]_]+_module([[:space:]]|$)' \
    /etc/apache2 2>&1 || true

exec apache2-foreground
