#!/usr/bin/env bash
set -euo pipefail

mkdir -p coverage/html

if command -v herd >/dev/null 2>&1; then
  herd coverage ./vendor/bin/phpunit --coverage-html coverage/html
else
  XDEBUG_MODE=coverage ./vendor/bin/phpunit --coverage-html coverage/html
fi
