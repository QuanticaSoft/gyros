#!/usr/bin/env bash
# Promueve lo probado en cbb01/ (la fuente del agente) a scz01/: lo deja
# identico a cbb01/. No commitea ni despliega.
set -euo pipefail

cd "$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"

rsync -a --delete --exclude .DS_Store cbb01/ scz01/

git status --short
