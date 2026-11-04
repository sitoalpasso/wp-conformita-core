#!/usr/bin/env bash
#
# Prepara la suite di test di WordPress per PHPUnit.
#
# Uso: bin/installa-suite-test.sh <nome-db> <utente> <password> [host] [versione-wp]
#
# La versione di WordPress puo essere un numero (6.5) oppure "latest": in questo
# secondo caso viene risolta dall'API di wordpress.org, cosi il core e la suite
# di test provengono sempre dalla stessa versione. Suite e core disallineati
# producono errori che sembrano difetti del plugin e non lo sono.
#
# Richiede subversion: la suite di test non e inclusa nel pacchetto di rilascio
# di WordPress e si preleva dal repository di sviluppo.

set -euo pipefail

DB_NAME="${1:?indicare il nome del database}"
DB_USER="${2:?indicare utente del database}"
DB_PASS="${3:?indicare la password del database}"
DB_HOST="${4:-localhost}"
WP_VERSION="${5:-latest}"

WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress}"
WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"

if ! command -v svn > /dev/null 2>&1; then
	echo "subversion non trovato: installarlo prima di eseguire questo script." >&2
	exit 1
fi

if [ "$WP_VERSION" = "latest" ]; then
	WP_VERSION=$(curl -sS https://api.wordpress.org/core/version-check/1.7/ \
		| grep -o '"version":"[^"]*"' \
		| head -1 \
		| sed -e 's/.*:"//' -e 's/"$//')

	if [ -z "$WP_VERSION" ]; then
		echo "Impossibile risolvere l'ultima versione stabile di WordPress." >&2
		exit 1
	fi

	echo "Versione stabile risolta: ${WP_VERSION}"
fi

# Core, dal pacchetto gia costruito di wordpress.org.
if [ ! -d "$WP_CORE_DIR" ]; then
	mkdir -p "$WP_CORE_DIR"
	curl -sSL --fail "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" -o /tmp/wordpress.tar.gz
	tar --strip-components=1 -zxf /tmp/wordpress.tar.gz -C "$WP_CORE_DIR"
fi

# Suite di test, dal repository di sviluppo, sul tag corrispondente.
if [ ! -d "${WP_TESTS_DIR}/includes" ]; then
	mkdir -p "$WP_TESTS_DIR"
	svn export --quiet --force \
		"https://develop.svn.wordpress.org/tags/${WP_VERSION}/tests/phpunit/includes/" \
		"${WP_TESTS_DIR}/includes"
	svn export --quiet --force \
		"https://develop.svn.wordpress.org/tags/${WP_VERSION}/tests/phpunit/data/" \
		"${WP_TESTS_DIR}/data"
fi

cat > "${WP_TESTS_DIR}/wp-tests-config.php" <<PHP
<?php
define( 'ABSPATH', '${WP_CORE_DIR}/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'DB_NAME', '${DB_NAME}' );
define( 'DB_USER', '${DB_USER}' );
define( 'DB_PASSWORD', '${DB_PASS}' );
define( 'DB_HOST', '${DB_HOST}' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
\$table_prefix = 'wptests_';
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test' );
define( 'WP_PHP_BINARY', 'php' );
PHP

echo "Suite pronta in ${WP_TESTS_DIR} (WordPress ${WP_VERSION})."
