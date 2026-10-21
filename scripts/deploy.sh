#!/usr/bin/env bash
# Despliega una subcarpeta del monorepo a su host.
#
#   scripts/deploy.sh <gyrosfe|cbb01|scz01>           simulacion: muestra que cambiaria
#   scripts/deploy.sh <gyrosfe|cbb01|scz01> --apply   copia los archivos
#
# gyrosfe y scz01 solo despliegan main, limpio e igual a origin/main, para que
# lo que corre alli sea siempre un commit que existe en GitHub. cbb01 es la
# fuente del agente y, sin host de desarrollo, tambien donde se prueba un
# feature antes de promoverlo: acepta cualquier rama, pero solo un despliegue
# desde main crea tag. No reinicia servicios.
set -euo pipefail

target="${1:-}"
mode="${2:-}"

case "$target" in
    gyrosfe) remote="marco@flamenco.cnb.net";      path="/webs/quanticasoft/gyrosfe" ;;
    cbb01)   remote="robot@100.107.84.95";         path="/opt/gyros/agent" ;;
    scz01)   remote="agentescz1@100.117.246.119";  path="/opt/gyros/agent" ;;
    *) echo "Uso: $0 <gyrosfe|cbb01|scz01> [--apply]" >&2; exit 2 ;;
esac
if [ -n "$mode" ] && [ "$mode" != "--apply" ]; then
    echo "Opcion desconocida: $mode" >&2; exit 2
fi

cd "$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"

die() { echo "ERROR: $*" >&2; exit 1; }

branch="$(git rev-parse --abbrev-ref HEAD)"
[ -z "$(git status --porcelain)" ] || die "hay cambios sin commitear"

if [ "$target" = "cbb01" ] && [ "$branch" != "main" ]; then
    echo "AVISO: cbb01 es produccion y atiende debitos reales; se despliega la rama $branch."
else
    [ "$branch" = "main" ] || die "solo se despliega desde main"
    git fetch --quiet origin main
    [ "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)" ] || die "main local no coincide con origin/main"
fi

# En main cbb01 y scz01 deben ser iguales: un cambio no promovido no llega a produccion.
if [ "$target" = "scz01" ] || { [ "$target" = "cbb01" ] && [ "$branch" = "main" ]; }; then
    diff -rq -x .DS_Store cbb01 scz01 || die "cbb01 y scz01 deben ser identicos (falta scripts/promote.sh?)"
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

if [ "$branch" != "main" ]; then
    echo "Desplegado (prueba desde rama, sin tag)."
else
    tag="deploy/$target/$(date +%Y-%m-%d-%H%M)"
    git tag "$tag"
    echo "Desplegado. Tag local $tag creado; publicarlo con: git push origin $tag"
fi

if [ "$target" != "gyrosfe" ]; then
    echo "Los servicios no se reiniciaron. Si cambio codigo del agente, en $remote:"
    echo "  sudo systemctl restart gyros-union-server gyros-agent gyros-usb-monitor"
fi
