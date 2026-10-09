#!/usr/bin/env bash
# Despliega una subcarpeta del monorepo a su host.
#
#   scripts/deploy.sh <gyrosfe|dev|cbb01|scz01>           simulacion: muestra que cambiaria
#   scripts/deploy.sh <gyrosfe|dev|cbb01|scz01> --apply   copia los archivos
#
# Produccion (gyrosfe, cbb01, scz01) solo despliega main, limpio e igual a
# origin/main, para que lo que corre alli sea siempre un commit que existe en
# GitHub. dev es el agente de desarrollo: despliega cualquier rama, porque es
# donde se prueba un feature antes de promoverlo. No reinicia servicios.
set -euo pipefail

target="${1:-}"
mode="${2:-}"

case "$target" in
    gyrosfe) remote="marco@flamenco.cnb.net";      path="/webs/quanticasoft/gyrosfe" ;;
    dev)     remote="developer@100.95.139.122";    path="/home/dev" ;;
    cbb01)   remote="robot@100.107.84.95";         path="/opt/gyros/agent" ;;
    scz01)   remote="agentescz1@100.117.246.119";  path="/home/agentescz1/scz1" ;;
    *) echo "Uso: $0 <gyrosfe|dev|cbb01|scz01> [--apply]" >&2; exit 2 ;;
esac
if [ -n "$mode" ] && [ "$mode" != "--apply" ]; then
    echo "Opcion desconocida: $mode" >&2; exit 2
fi

cd "$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"

die() { echo "ERROR: $*" >&2; exit 1; }

branch="$(git rev-parse --abbrev-ref HEAD)"
[ -z "$(git status --porcelain)" ] || die "hay cambios sin commitear"

if [ "$target" != "dev" ]; then
    [ "$branch" = "main" ] || die "solo se despliega desde main"
    git fetch --quiet origin main
    [ "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)" ] || die "main local no coincide con origin/main"
fi

# Un cambio probado en dev y no promovido no debe llegar a produccion.
if [ "$target" = "cbb01" ] || [ "$target" = "scz01" ]; then
    diff -rq -x .DS_Store dev cbb01 || die "dev y cbb01 deben ser identicos (falta scripts/promote.sh?)"
    diff -rq -x .DS_Store dev scz01 || die "dev y scz01 deben ser identicos (falta scripts/promote.sh?)"
fi

sha="$(git rev-parse HEAD)"

# Lo excluido tampoco se borra en el host: es lo que vive solo alli.
rsync_args=(
    -rlc --delete --itemize-changes
    --exclude .DS_Store --exclude .deployed --exclude .git/
    --exclude .env --exclude '.env.*' --exclude .venv/
    --exclude __pycache__/ --exclude '*.pyc' --exclude '*.egg-info/' --exclude '*.bak*'
)

if [ "$mode" != "--apply" ]; then
    echo "SIMULACION $target -> $remote:$path ($branch, commit ${sha:0:7})"
    rsync -n "${rsync_args[@]}" "$target/" "$remote:$path/"
    echo "Sin cambios aplicados. Repetir con --apply para desplegar."
    exit 0
fi

echo "DESPLEGANDO $target -> $remote:$path ($branch, commit ${sha:0:7})"
rsync "${rsync_args[@]}" "$target/" "$remote:$path/"
ssh "$remote" "printf '%s %s %s\n' '$sha' \"\$(date -u +%Y-%m-%dT%H:%M:%SZ)\" '$branch' > '$path/.deployed'"

if [ "$target" = "dev" ]; then
    echo "Desplegado."
else
    tag="deploy/$target/$(date +%Y-%m-%d-%H%M)"
    git tag "$tag"
    echo "Desplegado. Tag local $tag creado; publicarlo con: git push origin $tag"
fi

if [ "$target" != "gyrosfe" ]; then
    echo "Los servicios no se reiniciaron. Si cambio codigo del agente, en $remote:"
    echo "  sudo systemctl restart gyros-union-server gyros-agent gyros-usb-monitor"
fi
