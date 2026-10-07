#!/bin/bash
# Libera una sesion SSH reversa huerfana en flamenco antes de levantar el tunel.
#
# Version anterior (agente unico): mataba cualquier sesion "sshd-session: marco"
# sin tty en flamenco, sin importar cuantas hubiera. Con varios agentes en
# paralelo eso es peligroso: la limpieza de un agente podia matar el tunel
# legitimo de otro.
#
# Idealmente se identificaria la sesion por el puerto que tunela (cada agente
# tiene el suyo, ver Agent.tunnelPort en la DB de gyrosfe), pero en este
# servidor no es posible: `ss -ltnp` no expone el PID dueno del socket para
# un usuario sin privilegios, y `lsof -p <pid>` da "Permission denied" incluso
# sobre el propio proceso (el mismo privilege separation de sshd que ya
# documento memory.md, hallazgo tunel 2026-07-13) — una sesion sana y una
# huerfana de OTRO agente se ven identicas ("sshd-session: marco", tty "?"),
# sin ninguna forma de distinguirlas desde aqui.
#
# Mitigacion aceptada (no es un fix perfecto): solo se mata si en ese momento
# hay EXACTAMENTE UNA sesion huerfana candidata. Ese es el caso normal de un
# solo agente reconectando tras un corte de red. Si hay 2 o mas candidatas
# (ambiguedad real - no se puede saber cual es la propia), no se toca nada;
# se confia en Restart=always para seguir reintentando hasta que el sistema
# operativo libere el puerto por timeout de TCP o alguien intervenga a mano.
#
# TUNNEL_SSH_KEY llega por variable de entorno (systemd la inyecta via
# EnvironmentFile=/opt/gyros/agent/.env en gyros-tunnel.service, que es quien
# invoca este script como ExecStartPre). Si se corre este script a mano fuera
# de systemd, cae al fallback de leerla directo de .env.

if [ -z "$TUNNEL_SSH_KEY" ]; then
    TUNNEL_SSH_KEY=$(grep -m1 '^TUNNEL_SSH_KEY=' /opt/gyros/agent/.env | cut -d= -f2-)
fi

ssh -i "$TUNNEL_SSH_KEY" \
    -o StrictHostKeyChecking=no \
    -o ConnectTimeout=10 \
    -o BatchMode=yes \
    marco@flamenco.cnb.net bash -s 2>/dev/null <<'REMOTE' || true
mapfile -t candidatos < <(
    ps -u marco -o pid=,tty=,args= | while read -r pid tty rest; do
        if [ "$tty" = "?" ] && [ "$rest" = "sshd-session: marco" ]; then
            echo "$pid"
        fi
    done
)
if [ "${#candidatos[@]}" -eq 1 ]; then
    kill "${candidatos[0]}" 2>/dev/null
fi
REMOTE

# Espera breve para que flamenco libere el puerto (si se mato algo)
sleep 3
