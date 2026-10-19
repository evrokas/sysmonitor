#!/bin/bash
# Lint + unit/integration tests + real-HTTP end-to-end.
set -e
cd "$(dirname "$0")/.."
for f in $(find src bin web tests -name '*.php'); do php -l "$f" >/dev/null; done
php tests/run.php
bash tests/e2e.sh
