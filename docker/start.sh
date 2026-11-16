#!/bin/sh
set -eu

if [ -z "${SITE_USER:-}" ] || [ -z "${SITE_PASSWORD:-}" ]; then
    echo "Erro: credenciais de acesso ao site ausentes."
    exit 1
fi

htpasswd -bc /etc/apache2/.htpasswd "$SITE_USER" "$SITE_PASSWORD"
exec apache2-foreground
