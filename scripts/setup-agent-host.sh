#!/usr/bin/env bash
# Prepara como root un host agente nuevo. Se corre en el host, no en local:
#
#   sudo bash setup-agent-host.sh <usuario> <ruta-del-codigo>
#
# Es idempotente y se corre dos veces: antes del primer deploy (crea la ruta,
# que deploy.sh necesita) y despues (instala units y reglas udev, que salen
# del codigo ya desplegado). No habilita ni arranca ningun servicio.
set -euo pipefail

user="${1:-}"
path="${2:-}"
if [ -z "$user" ] || [ -z "$path" ]; then
    echo "Uso: sudo bash $0 <usuario> <ruta-del-codigo>" >&2; exit 2
fi
[ "$(id -u)" -eq 0 ] || { echo "ERROR: correr con sudo" >&2; exit 1; }
id "$user" >/dev/null

# Scripts y units tienen fija la ruta /opt/gyros/agent.
canonical="/opt/gyros/agent"

install -d -o "$user" -g "$user" "$path"
if [ "$path" != "$canonical" ]; then
    install -d /opt/gyros
    ln -sfn "$path" "$canonical"
fi
echo "Ruta lista: $canonical -> $(readlink -f "$canonical")"

# Un portatil usado como servidor no debe suspenderse al cerrar la tapa.
if [ -d /proc/acpi/button/lid ]; then
    install -d /etc/systemd/logind.conf.d
    printf '[Login]\nHandleLidSwitch=ignore\nHandleLidSwitchExternalPower=ignore\n' \
        > /etc/systemd/logind.conf.d/gyros-lid.conf
    systemctl kill -s HUP systemd-logind
    echo "Tapa: cerrarla ya no suspende el equipo"
fi

if [ ! -d "$path/systemd" ]; then
    echo "Aun no hay codigo en $path: desplegar y volver a correr este script."
    exit 0
fi

install -m 644 "$path/systemd/51-android.rules" /etc/udev/rules.d/51-android.rules
udevadm control --reload-rules
udevadm trigger --subsystem-match=usb

install -m 755 "$path/systemd/gyros-tunnel-cleanup.sh" /usr/local/bin/gyros-tunnel-cleanup.sh

for unit in gyros-agent gyros-usb-monitor gyros-union-server gyros-tunnel; do
    sed "s/^User=robot$/User=$user/" "$path/systemd/$unit.service" \
        > "/etc/systemd/system/$unit.service"
    chmod 644 "/etc/systemd/system/$unit.service"
done
systemctl daemon-reload

echo "Units instaladas (sin habilitar ni arrancar):"
grep -H '^User=' /etc/systemd/system/gyros-*.service
