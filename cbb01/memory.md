# Gyros Agent — memoria del proyecto

> Este repo corre en más de un host en paralelo — ver la tabla de hosts en `CLAUDE.md`
> (hoy: `agent-01`/`cbb01` en Cochabamba, `scz01` en Santa Cruz). Esta bitácora es
> **compartida entre hosts**: las entradas indican a cuál se refieren cuando aplica; los
> hallazgos sobre el diseño (túnel, DB, etc.) valen para todos. Mantenerla actualizada tras
> cada hallazgo relevante — es más barato leer esto que re-explorar todo el proyecto.

## Qué hace este agente

Controla un teléfono Android físico conectado por USB (Alcatel/ZTE, ver
`systemd/51-android.rules`). Automatiza la app **UNImóvil Plus** del Banco Unión
(Bolivia) vía `uiautomator2` para:

1. Consultar saldo de una cuenta (`union/main.py`, `POST /consultar-saldo`).
2. Debitar esa cuenta hacia una "cuenta oficina" fija por transferencia ACH
   (`POST /debitar`), usado por el flujo `debitar` del sistema de gestión financiera-logística.

El resultado se expone vía HTTP (Flask, puerto 8080 local) y se tuneliza por SSH inverso
hacia `flamenco.cnb.net` (puerto remoto propio de cada host, ver `CLAUDE.md`) para que el
backend central pueda invocarlo.

## Inventario de servicios (systemd)

| Unit | Script | Rol | Usuario | Estado |
|---|---|---|---|---|
| `gyros-agent.service` | `gyros-agent.pl` | Proceso supervisor: hace `fork()+exec` de `heartbeat.pl` y `detecta.pl`, loguea "alive" cada 60s | root | activo |
| `gyros-heartbeat.service` (en `cbb01`) | `heartbeat.pl` | Heartbeat HTTP redundante | root | **deshabilitado 2026-07-13** (hallazgo #2) |
| `usb-agent.service` (en `cbb01`) ⚠️ sin prefijo `gyros-` | `detecta.pl` | Detección USB redundante | root | **deshabilitado 2026-07-13** (hallazgo #2) |
| `gyros-usb-monitor.service` | `usb-monitor.pl` | Escucha `udevadm monitor`, envía eventos USB por socket TCP crudo a `BACKEND_HOST:BACKEND_PORT` (`config.conf`) | root | activo |
| `gyros-union-server.service` | `${PYTHON_BIN} -m union.server` | Servidor Flask (saldo/débito), puerto 8080 local | *(host, ver `.env`)* | activo |
| `gyros-tunnel.service` | `ssh` directo (`Restart=always`) | Túnel SSH inverso hacia flamenco, puerto remoto propio de `.env` | *(host, ver `.env`)* | activo |

Comando rápido de salud:
```
systemctl status gyros-agent gyros-usb-monitor gyros-union-server gyros-tunnel --no-pager
systemctl status gyros-heartbeat usb-agent --no-pager   # solo en cbb01, deben mostrar inactive/disabled
```

## Flujo funcional (`union/steps.py`, `union/steps_transferencia.py`)

Pasos 1–10: login + lectura de saldo + cierre de sesión/app (usados tanto por
`consultar-saldo` como como prefijo de `debitar`).
Pasos 11–18: apertura de menú ACH, validación de horario (`FueraDeHorarioACH`),
búsqueda/verificación de destinatario (`DestinatarioNoCoincide`), selección de cuenta
origen, monto/glosa, confirmación y lectura de número de envío.

Cada paso loguea `[PASO N] ...` a stdout → journal de `gyros-union-server`, útil para
diagnosticar en qué punto de la UI se atoró la automatización.

## Conectividad externa

- `flamenco.cnb.net:22` — SSH, usado por el túnel inverso (llave de `TUNNEL_SSH_KEY` en
  `.env`) y por `usb-monitor.pl` (socket TCP a puerto 4000, no HTTP).
- `quanticasoft.com/gyrosfe/agent/{heartbeat,usb_event}.php` — API HTTP usada por
  `heartbeat.pl` y `detecta.pl`, autenticada con headers `x-agent-id` / `x-agent-token`
  (de `.env`).

## Secretos (no volcar valores en este archivo ni en el repo)

- `.env` (gitignored, ver tabla completa en `CLAUDE.md`): `AGENT_ID`, `AGENT_TOKEN`,
  `TUNNEL_SSH_KEY`, `TUNNEL_REMOTE_PORT`, `PYTHON_BIN`, `BU_USUARIO`, `BU_PASSWORD`,
  `BU_DISPOSITIVO`, `BU_NOMBRE_TITULAR`, `BU_OFICINA_ALIAS`, `BU_OFICINA_CUENTA`,
  `BU_OFICINA_BANCO`, `BU_OFICINA_MONEDA`.
- La ruta de `TUNNEL_SSH_KEY` (una llave por host, nunca compartida entre hosts).

## Hallazgos

1. **[RESUELTO 2026-07-13, cbb01] Túnel se queda colgado tras un corte de red.**
   Causa raíz confirmada: `flamenco.cnb.net` **no se cae** (826 días de uptime sin reboot).
   Lo que pasa es un corte de red transitorio entre el cliente y flamenco; `autossh`
   detecta la conexión muerta vía `ServerAliveInterval` y reconecta del lado del agente,
   pero la sesión SSH vieja del lado de **flamenco** queda huérfana reteniendo el bind del
   puerto (nadie le avisó que el cliente se fue). Los reintentos posteriores fallan con
   `remote port forwarding failed for listen port <puerto>` hasta que esa sesión muere o
   se mata a mano.

   Diagnóstico: en flamenco, `sshd` reescribe el título del proceso por privilege
   separation, así que no se puede distinguir la sesión del túnel por su comando. La única
   señal fiable es la columna TTY: la sesión de solo-reenvío (`-N`, sin comando ni pty)
   aparece como `sshd-session: marco` con TTY `?`; una sesión interactiva real tendría una
   pty (`pts/N`); una ejecución de comando puntual aparece como `sshd-session: marco@notty`.

   Fix aplicado: `systemd/gyros-tunnel-cleanup.sh` mata exactamente esa sesión (título
   exacto + tty `?`) en vez del patrón viejo `sshd.*notty`, que nunca coincidía con nada
   real (commit `ad745b3`).

   **Fix estructural aplicado (2026-07-13, commit `4eebddf`)**: `gyros-tunnel.service`
   reemplazó `autossh` por `ssh` directo bajo `Restart=always` (+ `StartLimitIntervalSec=0`
   para que systemd nunca se rinda, igual que hacía `autossh`). El problema real era que
   `ExecStartPre` (la limpieza) solo se disparaba al (re)iniciar el *unit* completo, nunca
   cuando `autossh` reconectaba internamente (su proceso padre nunca moría). Con `ssh`
   directo, cualquier caída del proceso hace que systemd reinicie el unit completo y
   vuelva a correr `ExecStartPre` en cada intento — **verificado matando el proceso ssh a
   la fuerza** (`kill -9`): systemd detectó la caída, corrió el cleanup, y reconectó solo
   en ~15s sin intervención manual.

   Alternativa de raíz descartada por ahora: `ClientAliveInterval`/`ClientAliveCountMax`
   en el `sshd_config` de flamenco resolvería esto del lado servidor sin importar el
   cliente. No se aplicó porque **`marco` no tiene sudo en flamenco** (confirmado: "marco
   is not in the sudoers file. This incident has been reported to the administrator." —
   no reintentar sudo ahí sin credenciales de un usuario que sí sea sudoer, para no seguir
   generando alertas de seguridad).

   Chequeo rápido: `journalctl -u gyros-tunnel -n 30 --no-pager`. Si reaparece "remote
   port forwarding failed", correr `systemctl restart gyros-tunnel` (pide sudo).

2. **[RESUELTO 2026-07-13, cbb01] Procesos duplicados**: `heartbeat.pl` y `detecta.pl`
   corrían dos veces cada uno por **4 unidades systemd independientes y solapadas**,
   ninguna trackeada en `systemd/` del repo (alguien las desplegó a mano directo en
   `/etc/systemd/system/`, por fuera de git). No eran huérfanos de un restart (hipótesis
   descartada): los procesos arrancaban todos en el boot del sistema.

   | Unit | Qué corre | Estado (2026-07-13) |
   |---|---|---|
   | `gyros-agent.service` | `gyros-agent.pl` → hace `fork()` de `heartbeat.pl` y `detecta.pl` | activo (es la copia que se conserva de ambos) |
   | `gyros-heartbeat.service` | `heartbeat.pl` directo | **deshabilitado y detenido** |
   | `usb-agent.service` ⚠️ sin prefijo `gyros-` | `detecta.pl` directo (+ su propio `udevadm monitor` hijo) | **deshabilitado y detenido** |
   | `gyros-usb-monitor.service` | `usb-monitor.pl` (script y backend distintos, no es el duplicado) | activo, sin cambios |

   Ahora `heartbeat.pl` y `detecta.pl` corren una sola vez cada uno, ambos como hijos de
   `gyros-agent.pl`. Nota: ambas unidades duplicadas se detuvieron/deshabilitaron pero los
   archivos unit siguen en `/etc/systemd/system/` por si hace falta revertir, no se
   eliminaron.

   Chequeo: `systemctl status usb-agent gyros-heartbeat` debe mostrar
   `inactive`/`disabled` en ambas; `ps -o pid,ppid,cmd -e | grep -E "detecta.pl|heartbeat.pl"`
   debe mostrar exactamente 2 procesos por host.

3. **[RESUELTO 2026-09-10] Token de agente placeholder**: `AGENT_TOKEN = 'TOKEN_SECRETO'`
   en `cbb01` era literalmente ese placeholder, nunca reemplazado — y la DB lo validaba
   igual (`hash_equals` contra el mismo string). Se generó un token real
   (`openssl rand -hex 32`) y se actualizó coordinado en `.env` de `cbb01` y en la fila
   `Agent` de la DB. Ver hallazgo #9 para el contexto completo (movió además
   `AGENT_ID`/`AGENT_TOKEN` de código hardcodeado a `.env`).

4. **Dos mecanismos de reporte USB redundantes**: `detecta.pl` (POST a
   `quanticasoft.com/.../usb_event.php`) y `usb-monitor.pl` (socket TCP crudo a
   `flamenco.cnb.net:4000`). No está claro cuál es el vigente/autoritativo — revisar con el
   dueño del backend antes de tocar cualquiera de los dos.
5. **`banco_union/` es código legado**: módulo anterior a `union/` (login+balance sin
   arquitectura de pasos), no lo importa ningún servicio systemd ni `union/*`. Solo
   `setup.py` lo referencia. Candidato a eliminar, pero no borrar sin confirmar con el
   usuario.
6. Backups sueltos en la raíz (`detecta.pl.bak.*`) — ya cubiertos por `.gitignore`
   (`*.bak*`), no se trackean, pero conviene limpiarlos del filesystem.

7. **[2026-09-09] Rename `agent-01`→`cbb01` (Cochabamba) + nuevo agente `scz01` (Santa
   Cruz), en paralelo**. Se necesitaba un segundo agente de producción en otra ciudad,
   corriendo al mismo tiempo que `cbb01` (no en reemplazo). Trabajo hecho sobre una rama
   `nuevo_agente` en cada clon (no directo a `main`), para poder volver atrás limpio:

   - `AGENT_ID` pasó de `agent-01` a `cbb01` en `config.conf`/`heartbeat.pl`/`detecta.pl`
     (en ese momento aún hardcodeado, ver hallazgo #9) y en la fila `Agent` de la DB.
     Reinicio de `gyros-agent`/`gyros-usb-monitor` coordinado con el `UPDATE` de la DB
     para minimizar la ventana de heartbeats fallando por `agentId` desconocido.
   - Nueva columna `"Agent"."tunnelPort"` (migración
     `migrations/2026_agent_tunnel_port.sql` en el repo de gyrosfe): `cbb01`=8080 (el de
     siempre), `scz01`=8081. `api/consulta_saldo.php`/`debitar.php` en gyrosfe ya no
     tienen el puerto hardcodeado — lo resuelven en runtime vía `UsbDeviceState` (serial
     del dispositivo → agente conectado ahora mismo → su `tunnelPort`). El dispositivo que
     en ese momento tenga `status='connected'` bajo un agente es el que efectivamente
     atiende esa cuenta: si el mismo teléfono se pasa físicamente de un host a otro, el
     ruteo lo sigue automáticamente en cuanto `detecta.pl` reporta el evento USB.
   - **`systemd/gyros-tunnel-cleanup.sh`**: la versión de un solo agente mataba
     *cualquier* sesión huérfana `sshd-session: marco` sin tty en flamenco — con varios
     agentes tuneleando a la vez eso podía matar el túnel sano de otro. Se intentó
     identificar la sesión por el puerto real (`ss -ltnp` en flamenco) — **no es posible
     en ese host**: `ss -ltnp` no expone el PID dueño del socket para un usuario sin
     privilegios, y `lsof -p <pid>` da "Permission denied" incluso sobre el propio proceso
     (mismo privilege separation de sshd del hallazgo #1) — una sesión sana y una huérfana
     de OTRO agente se ven idénticas desde `marco`, sin ninguna señal para distinguirlas.
     Confirmado en vivo probando contra el túnel real de `cbb01` (`ss -ltnp` sin columna
     Process, `lsof -p` con "Permission denied" en los 4 fds). Mitigación aceptada (no es
     un fix perfecto): el script solo mata si hay **exactamente una** sesión huérfana
     candidata en ese momento; con 2+ (ambigüedad real) no toca nada y confía en
     `Restart=always` + timeout de TCP del SO.
   - Setup de `scz01` (host nuevo, Ubuntu 26.04): llave de gestión propia
     (`~/.ssh/id_ed25519_gyros`) para inventariar/aplicar cambios en `cbb01`/flamenco
     durante el setup; llave separada para el túnel (`~/.ssh/id_ed25519_flamenco`, propia,
     nunca compartida); deploy key de GitHub propia y separada
     (`~/.ssh/id_ed25519_opt_deploy`, lectura+escritura) — tres llaves con propósitos
     distintos, no reusar una para otra cosa. `/opt/gyros` no existía, requirió `sudo
     mkdir`+`chown` (este host no tiene sudo sin password). Python 3.14/Ubuntu 26.04 exige
     venv (PEP 668) — `python3 -m venv .venv` falló la primera vez ("ensurepip is not
     available") hasta instalar el paquete específico `python3.14-venv` (no alcanza
     `python3-venv` genérico). `heartbeat.pl`/`detecta.pl` fallaban con "Can't locate
     LWP/UserAgent.pm"/"Can't locate JSON.pm" — Ubuntu 26.04 no trae esos módulos Perl por
     defecto, resuelto con `apt install libwww-perl libjson-perl`.
   - Teléfono real: un ZTE Blade A34 (serial `NBA34BOAC5036471`, vendor `19d2`, ya cubierto
     por las reglas udev existentes) fue movido físicamente por el usuario desde `cbb01` a
     `scz01` para esta prueba (confirmado por el usuario, no es la reubicación real a
     Santa Cruz todavía). Ya tenía la app UNImóvil Plus instalada. Autorización ADB y
     `python -m uiautomator2 init <serial>` sin problemas. Ya existía un cliente real
     (`Cliente.uuid = a9c0d300-...`) apuntando a este serial, así que en cuanto el evento
     USB se reportó `connected` bajo `scz01`, ese cliente pasó a rutearse ahí
     automáticamente.
   - **[VALIDADO 2026-09-09]** Consulta de saldo real desde el dashboard para ese cliente
     devolvió 13.79 Bs correctamente, confirmando el ruteo dinámico end-to-end en
     producción real.

8. **[2026-09-10] `AGENT_ID`/`AGENT_TOKEN`/puerto-de-túnel/llave/python-bin: de código
   hardcodeado a `.env`, y merge de `scz01` a `main`**. El hallazgo #7 dejó
   `AGENT_ID`/`AGENT_TOKEN` hardcodeados en `heartbeat.pl`/`detecta.pl` y `config.conf`,
   distintos por host — eso hacía que un merge real entre las ramas de `cbb01` y `scz01`
   chocara exactamente en esas líneas (ambos valores correctos, cada uno para su máquina,
   sin nada que "resolver"). Se movieron a `.env`:
   - `heartbeat.pl`/`detecta.pl`/`usb-monitor.pl`: ya no tienen `$AGENT_ID`/`$AGENT_TOKEN`
     como constantes — los leen de `.env` con una función `read_kv_file` (mismo patrón que
     ya usaba `usb-monitor.pl` para `config.conf`, generalizado). `config.conf` perdió la
     línea `AGENT_ID` (quedó solo con `BACKEND_HOST`/`PORT`/`HEARTBEAT_INTERVAL`, iguales
     en todos los hosts).
   - De paso, se resolvió el hallazgo #3: token real generado para `cbb01`, coordinado con
     un `UPDATE` en la DB.
   - `systemd/gyros-tunnel.service`, `gyros-union-server.service`,
     `gyros-tunnel-cleanup.sh`: mismo tratamiento para `TUNNEL_SSH_KEY`,
     `TUNNEL_REMOTE_PORT`, `PYTHON_BIN` — vía `EnvironmentFile=/opt/gyros/agent/.env` en
     las unidades systemd, y lectura directa (con fallback a parsear `.env` a mano) en el
     script bash. **Nota técnica**: systemd expande `${VAR}` en los argumentos de
     `ExecStart` pero *no* en la posición del ejecutable — `gyros-union-server.service`
     necesitó `ExecStart=/usr/bin/env ${PYTHON_BIN} ...` como workaround (confirmado con
     un fallo real, `status=203/EXEC`, antes de aplicar el fix). Única línea que sigue
     siendo distinta por host: `User=` (systemd no permite leerlo de `EnvironmentFile=`),
     documentada con un comentario en cada unit.
   - Con el código ya idéntico entre hosts, se completó el merge de la rama `nuevo_agente`
     de `scz01` a `main` (junto con la de `cbb01`, ya mergeada antes). Únicos conflictos
     reales al mergear: `CLAUDE.md`/`memory.md` (docs, esperable que difieran por host —
     se unificaron en una sola versión compartida con tabla de hosts) y la línea `User=`
     en los dos `.service` (resuelta a favor de `cbb01` como valor por defecto documentado
     a editar por host).
   - Verificado en ambos hosts tras cada cambio: los 4 servicios activos, heartbeat
     reportando el `agentId` correcto, túnel con el puerto/llave propios expandidos.

## Pendiente

- Reubicación física real de `scz01` a Santa Cruz (al momento de escribir esto sigue en el
  mismo lugar que `cbb01`, para las pruebas). Tailscale ya está conectado y debería
  reconectar solo con la misma IP tras el traslado.
- Punto 4 (mecanismos de reporte USB redundantes) sigue sin decidir.
- `banco_union/` sigue sin borrarse (legado, hallazgo #5).
- No hay alerta/monitoreo activo si un túnel cae (más allá del auto-heal de
  `Restart=always` + cleanup) — el servidor de gyrosfe ya corre `zabbix-agent`, candidato
  natural para un chequeo por host/puerto. No implementado.

## Checklist para iteraciones de `/loop` sobre cualquiera de estos hosts

1. `git status && git log --oneline -5` — detectar cambios de otra persona antes de tocar
   nada.
2. `systemctl status gyros-agent gyros-usb-monitor gyros-union-server gyros-tunnel --no-pager`
   (+ en `cbb01`: `systemctl status gyros-heartbeat usb-agent --no-pager`, deben seguir
   `inactive`/`disabled` — hallazgo #2; `usb-agent.service` no tiene prefijo `gyros-`, se
   escapa fácil de un grep "gyros*").
3. `journalctl -u gyros-tunnel -n 20 --no-pager` — el servicio es autosanable
   (hallazgo #1): un `remote port forwarding failed` aislado debería resolverse solo en
   ~15s. Si el patrón se repite en bucle por más de un par de minutos, investigar (podría
   ser flamenco realmente caído, no solo una sesión huérfana).
4. `adb devices` — confirmar que el teléfono sigue conectado.
5. `ps aux | grep -E "heartbeat.pl|detecta.pl"` — exactamente 2 procesos, ambos con PPID =
   PID de `gyros-agent.pl` (hallazgo #2).
6. `df -h /` — espacio en disco.
7. Reportar solo lo que cambió respecto a la iteración anterior — evitar ruido si el
   estado es idéntico.
8. No mostrar ni loguear valores de `.env` ni de llaves SSH.
9. No hacer `git push` ni reiniciar servicios sin confirmación explícita del usuario.

## Notas de conexión

- `robot@100.107.84.95` (agent-01/cbb01): acceso por llave pública ya autorizada en
  `~/.ssh/authorized_keys`.
- `agentescz1@100.117.246.119` (scz01): idem, llave de gestión propia autorizada tanto ahí
  como en `cbb01`/flamenco.
- `marco@flamenco.cnb.net`: alcanzable con la llave dedicada del túnel de cada host
  (`TUNNEL_SSH_KEY` en `.env`, nunca compartida entre hosts). Para diagnosticar flamenco
  desde una sesión sin acceso directo, saltar por cualquiera de los dos agentes.
- **`marco` no tiene sudo en flamenco** (confirmado: "marco is not in the sudoers file.
  This incident has been reported to the administrator."). No reintentar sudo ahí sin
  credenciales de un usuario que sí sea sudoer real — ya generó una alerta de seguridad
  una vez. Cambios que requieran root en flamenco necesitan que el usuario los aplique él
  mismo o dé acceso a otra cuenta con sudo real.
- flamenco tiene otros servicios corriendo para `marco` (PM2, VSCode Server, `php-fpm:
  pool gyros`) — cualquier limpieza de sesiones/procesos ahí debe ser quirúrgica, nunca un
  pattern-match amplio (hallazgo #1).
