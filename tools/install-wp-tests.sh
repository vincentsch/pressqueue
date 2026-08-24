#!/usr/bin/env bash
set -euo pipefail

DB_NAME=${1:-pressqueue_tests}
DB_USER=${2:-root}
DB_PASS=${3:-}
DB_HOST=${4:-127.0.0.1}
WP_VERSION=${5:-6.8.8}
SKIP_DB_CREATE=${6:-false}

TMPDIR=${TMPDIR:-/tmp}
WP_CORE_DIR=${WP_CORE_DIR:-"$TMPDIR/wordpress-$WP_VERSION"}
WP_TESTS_DIR=${WP_TESTS_DIR:-"$TMPDIR/wordpress-tests-lib-$WP_VERSION"}
DEVELOP_DIR="$TMPDIR/pressqueue-wordpress-develop-$WP_VERSION"
DEVELOP_EXTRACTED="$TMPDIR/wordpress-develop-$WP_VERSION"
DEVELOP_ZIP="$TMPDIR/wordpress-develop-$WP_VERSION.zip"

download() {
	local url=$1
	local destination=$2

	if command -v curl >/dev/null 2>&1; then
		curl -fsSL "$url" -o "$destination"
		return
	fi

	php -r "copy('$url', '$destination');"
}

if [ ! -d "$WP_CORE_DIR" ]; then
	mkdir -p "$WP_CORE_DIR"
	CORE_ZIP="$TMPDIR/wordpress-core-$WP_VERSION.zip"
	download "https://wordpress.org/wordpress-$WP_VERSION.zip" "$CORE_ZIP"
	unzip -qo "$CORE_ZIP" -d "$TMPDIR"
	rm -rf "$WP_CORE_DIR"
	mv "$TMPDIR/wordpress" "$WP_CORE_DIR"
fi

if [ ! -d "$WP_TESTS_DIR" ]; then
	download "https://github.com/WordPress/wordpress-develop/archive/refs/tags/$WP_VERSION.zip" "$DEVELOP_ZIP"
	rm -rf "$DEVELOP_DIR"
	rm -rf "$DEVELOP_EXTRACTED"
	unzip -qo "$DEVELOP_ZIP" -d "$TMPDIR"
	mv "$DEVELOP_EXTRACTED" "$DEVELOP_DIR"
	mkdir -p "$WP_TESTS_DIR"
	cp -R "$DEVELOP_DIR/tests/phpunit/includes" "$WP_TESTS_DIR/includes"
	cp -R "$DEVELOP_DIR/tests/phpunit/data" "$WP_TESTS_DIR/data"
fi

if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
	sed \
		-e "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR/':" \
		-e "s:youremptytestdbnamehere:$DB_NAME:" \
		-e "s:yourusernamehere:$DB_USER:" \
		-e "s:yourpasswordhere:$DB_PASS:" \
		-e "s|localhost|$DB_HOST|" \
		"$DEVELOP_DIR/wp-tests-config-sample.php" > "$WP_TESTS_DIR/wp-tests-config.php"
fi

if [ "$SKIP_DB_CREATE" != "true" ]; then
	mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" >/dev/null 2>&1 || true
fi

echo "WP_CORE_DIR=$WP_CORE_DIR"
echo "WP_TESTS_DIR=$WP_TESTS_DIR"
