#!/usr/bin/env bash
# Runs the standalone unit tests. No WordPress or PHPUnit needed — each file stubs
# the handful of WP functions the class under test touches.
set -e
cd "$(dirname "$0")"
status=0
for f in test-*.php; do
	echo "--- $f"
	php "$f" || status=1
	echo
done
exit $status
