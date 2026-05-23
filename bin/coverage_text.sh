#!/usr/bin/env bash
set -euo pipefail

mkdir -p coverage

if command -v herd >/dev/null 2>&1; then
  herd coverage ./vendor/bin/phpunit --coverage-text=coverage/coverage.txt
else
  XDEBUG_MODE=coverage ./vendor/bin/phpunit --coverage-text=coverage/coverage.txt
fi
