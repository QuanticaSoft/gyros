#!/usr/bin/env bash
# Promueve lo probado en dev/ a los agentes de produccion: deja cbb01/ y
# scz01/ identicos a dev/. No commitea ni despliega.
set -euo pipefail

cd "$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"

for target in cbb01 scz01; do
    rsync -a --delete --exclude .DS_Store dev/ "$target/"
done

git status --short
