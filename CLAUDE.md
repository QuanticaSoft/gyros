# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Qué es esto

Gyros es un sistema de gestión de préstamos (QuanticaSoft, Bolivia) que cobra cuotas debitando la cuenta del cliente mediante la app **UNImóvil Plus** del Banco Unión, automatizada sobre teléfonos Android físicos conectados por USB a hosts "agente".

## Repositorio y despliegue

Monorepo en `https://github.com/QuanticaSoft/gyros.git`, única fuente de verdad del proyecto. El tag `inicio-2026-10-05` marca el punto de partida del repo unificado (los repos anteriores `gyrosfe`, `opt` y `scz1` solo conservan historial). Cada subcarpeta se despliega en un host distinto:

| Carpeta | Qué es | Host (SSH) | Ruta en el host | Puerto del túnel |
|---|---|---|---|---|
| `gyrosfe/` | Backend + UI web en PHP 8 / PostgreSQL (sin framework, sin Composer) | `marco@flamenco.cnb.net` | `/webs/quanticasoft/gyrosfe` | — |
| `dev/` | Agente de desarrollo (Perl + Python) | `developer@100.95.139.122` (Tailscale) | `/home/dev` | 8087 |
| `cbb01/` | Agente (Perl + Python), Cochabamba | `robot@100.107.84.95` (Tailscale) | `/opt/gyros/agent` | 8080 |
| `scz01/` | Agente (Perl + Python), Santa Cruz | `agentescz1@100.117.246.119` (Tailscale) | `/opt/gyros/agent` | 8081 |

La web se usa en `https://www.quanticasoft.com/gyrosfe/ui/login.php`. El puerto del túnel es el puerto remoto en flamenco (`TUNNEL_REMOTE_PORT` en el `.env` del agente, `Agent.tunnelPort` en la DB); en el propio agente Flask siempre escucha en `:8080`.

`dev/`, `cbb01/` y `scz01/` contienen **el mismo código y en `main` deben quedar byte a byte idénticos** (`diff -rq -x .DS_Store dev cbb01; diff -rq -x .DS_Store dev scz01` no debe reportar nada). Solo difieren dentro de una rama mientras se desarrolla: el agente se edita **únicamente en `dev/`** y `scripts/promote.sh` copia el resultado a las otras dos; `cbb01/` y `scz01/` nunca se editan a mano. Lo que difiere por host vive fuera del código: el `.env` (ignorado por git), la línea `User=` de `gyros-tunnel.service` / `gyros-union-server.service` y, donde el código no vive en `/opt/gyros/agent`, un symlink desde esa ruta.

`dev` es un agente más del gyrosfe de producción (fila propia en `"Agent"`, heartbeats y eventos USB reales): un teléfono de cliente conectado a ese host rutea sus débitos reales por ahí.

La bitácora operativa histórica del agente (hallazgos del túnel, unidades systemd duplicadas, setup de `scz01`, checklist de salud) está en git aunque no en el árbol de trabajo: `git show inicio-2026-10-05:cbb01/memory.md`. Consultarla antes de tocar el túnel o el despliegue.

## Flujo Git y despliegue

- `main` es producción y no recibe push directo. Todo cambio va en `feature/<nombre>` (o `fix/<nombre>`), PR a `main`, squash merge. No hay rama `develop`.
- Los hosts no tienen git: reciben archivos con `scripts/deploy.sh`, que copia la subcarpeta por `rsync` (con `--delete`) y deja en el host un `.deployed` con el SHA, la fecha y la rama.
- Un cambio en el agente recorre siempre este camino:
  1. rama `feature/<nombre>`, editando solo `dev/`;
  2. `scripts/deploy.sh dev --apply` (única carpeta que se despliega desde una rama) y prueba en el host DEV;
  3. `scripts/promote.sh` para copiar `dev/` a `cbb01/` y `scz01/`, y commit;
  4. PR a `main`, squash merge;
  5. desde `main`, `scripts/deploy.sh cbb01 --apply` y `scripts/deploy.sh scz01 --apply`.

```bash
scripts/deploy.sh dev --apply        # despliega la rama actual al agente de desarrollo
scripts/promote.sh                   # deja cbb01/ y scz01/ idénticos a dev/ (no commitea)
scripts/deploy.sh gyrosfe            # simulación: lista lo que cambiaría en el host
scripts/deploy.sh gyrosfe --apply    # despliega y crea el tag local deploy/gyrosfe/<fecha-hora>
cat /webs/quanticasoft/gyrosfe/.deployed   # (en el host) qué commit está corriendo
```

Para producción el script solo corre desde `main`, limpio e igual a `origin/main`, y para `cbb01`/`scz01` exige que ambas carpetas sean idénticas a `dev/`; para `dev` solo exige el árbol limpio y no crea tag. Un host agente nuevo se prepara una vez con `scripts/setup-agent-host.sh` (ruta, symlink, reglas udev y units; se corre con sudo en el host). No toca `.env`, `.venv/` ni las units ya instaladas en `/etc/systemd/system`, y no reinicia servicios. Correr siempre la simulación antes de `--apply`.

`gyrosfe/` se copia tal cual a la raíz web: no poner ahí nada que no deba ser público. `gyrosfe/.htaccess` bloquea archivos ocultos, `.md` y `.sql`, pero no directorios ocultos.

## Comandos

No hay build, linter ni suite de tests en ninguno de los proyectos.

Agente (desde `dev/`, requiere teléfono por ADB y `.env` con las variables `BU_*`):

```bash
pip install -r requirements.txt flask   # flask lo importa union/server.py pero falta en requirements.txt
python -m union.main                    # flujo de consulta de saldo (pasos 1–10) contra el teléfono, imprime el saldo
python -m union.server                  # servidor Flask en 0.0.0.0:8080 (lo que corre gyros-union-server.service)
perl -c heartbeat.pl                    # chequeo de sintaxis de un script Perl
diff -rq -x .DS_Store dev cbb01; diff -rq -x .DS_Store dev scz01   # (desde la raíz) las tres carpetas siguen idénticas
```

`python -m union.server` con un `POST /debitar` ejecuta una **transferencia ACH real**; no hay modo de prueba ni dry-run.

gyrosfe:

```bash
php -l api/debitar.php                  # chequeo de sintaxis; no hay otro tooling
```

No corre en local tal cual: `lib/db_connect.php` lee credenciales de `/webs/quanticasoft/_private/db.php` (ruta absoluta del servidor, debe devolver `['dsn','user','pass']`), la cookie de sesión exige HTTPS y todas las URLs están fijas bajo `/gyrosfe/`.

En los hosts agente (salud y diagnóstico):

```bash
systemctl status gyros-agent gyros-usb-monitor gyros-union-server gyros-tunnel --no-pager
journalctl -u gyros-union-server -f     # log "[PASO N] ..." de la automatización: dice en qué pantalla se atoró
journalctl -u gyros-tunnel -n 30 --no-pager
adb devices
ps -o pid,ppid,cmd -e | grep -E "detecta.pl|heartbeat.pl"   # exactamente 2 procesos, hijos de gyros-agent.pl
```

## Arquitectura

### Flujo de un débito de extremo a extremo

1. El operador pulsa "debitar" en `gyrosfe/ui/main.php` → `POST api/debitar.php` (`id_pago`, `monto`).
2. `debitar.php` resuelve: cuota (`pago`) → préstamo → `Cliente.dispositivo` (serial ADB) → credenciales bancarias en `banco_cliente` con `nickname = 'pago'` y `isActive`.
3. **Ruteo dinámico al agente**: busca en `UsbDeviceState` la fila `status='connected'` más reciente para ese serial y toma `Agent.tunnelPort` (cbb01 = 8080, scz01 = 8081). Si el teléfono se mueve físicamente a otro host, el ruteo lo sigue solo en cuanto llega el evento USB.
4. Llama por curl a `http://127.0.0.1:<tunnelPort>/debitar`. Ese puerto en flamenco es el extremo de un **túnel SSH inverso** (`gyros-tunnel.service`) hacia el Flask `:8080` del agente.
5. El agente (`union/server.py`) maneja la app en el teléfono con `uiautomator2` y devuelve `numero_envio`.
6. `debitar.php` marca la cuota como `pagado` y graba los valores reales de amortización.

`api/consulta_saldo.php` sigue el mismo camino hacia `/consultar-saldo` e inserta el resultado en la tabla `saldo` (opcionalmente ligado a una cuota con `tipo` = `antes` | `despues`).

Estas llamadas son lentas (la automatización de UI tarda minutos): timeouts de curl de 180 s (saldo) y 280 s (débito).

**El débito no es transaccional**: la transferencia ocurre en el paso 5 y la cuota se actualiza recién en el 6. La única protección contra un doble cobro es que `pago.nro_envio_transferencia` ya tenga valor (409). Si algo falla entre ambos pasos (timeout de curl, error de DB), el dinero ya se movió y la cuota sigue pendiente: nunca reintentar un débito fallido sin verificar antes en el banco o en el journal del agente.

### Agente (`cbb01/`, `scz01/`)

Tres piezas independientes, cada una con su unit en `systemd/`:

- **`union/`** — servidor Flask. `steps.py` (pasos 1–10: login, lectura de saldo, cierre de sesión y de app) y `steps_transferencia.py` (pasos 11–18: transferencia ACH hacia la "cuenta oficina" fija definida por `BU_OFICINA_*`). Cada paso es una función `pasoN_*` que valida la pantalla esperada y lanza excepción si no coincide; `server.py` solo los encadena. `FueraDeHorarioACH` y `DestinatarioNoCoincide` se devuelven como 409. Hay un lock por serial ADB: dispositivos distintos corren en paralelo, el mismo dispositivo responde 503 si está ocupado. Las credenciales bancarias llegan en el body de cada request (vienen de la DB de gyrosfe); las `BU_USUARIO`/`BU_PASSWORD` del `.env` solo las usa `union/main.py` para pruebas manuales.
- **`gyros-agent.pl`** — supervisor que hace fork de `heartbeat.pl` (POST cada 60 s a `gyrosfe/agent/heartbeat.php`) y `detecta.pl` (escucha `udevadm`, reporta connect/disconnect a `gyrosfe/agent/usb_event.php`, con ventana de gracia para re-enumeraciones USB). Ambos se autentican con headers `x-agent-id` / `x-agent-token` contra la tabla `Agent`. `detecta.pl` es lo que alimenta `UsbDeviceState`, es decir, el ruteo del paso 3. No correrlos además como units propios: duplica heartbeats y eventos.
- **`usb-monitor.pl`** — segundo reporte USB, por socket TCP crudo a `flamenco.cnb.net:4000` (`config.conf`). Redundante con `detecta.pl`; no está decidido cuál es el vigente — no tocar sin confirmar.

Los scripts Perl y los units tienen fija la ruta `/opt/gyros/agent` (incluido `.env` y `config.conf`). Los valores por host (`AGENT_ID`, `AGENT_TOKEN`, `TUNNEL_SSH_KEY`, `TUNNEL_REMOTE_PORT`, `PYTHON_BIN`, `BU_*`) salen del `.env`; no volver a hardcodearlos, porque rompe la igualdad entre las dos carpetas. systemd expande `${VAR}` en los argumentos de `ExecStart` pero no en la posición del ejecutable, de ahí el `/usr/bin/env ${PYTHON_BIN}` en `gyros-union-server.service`.

`banco_union/` es código legado anterior a `union/` (solo lo referencia `setup.py`). No extenderlo, y no borrarlo sin confirmación.

**Túnel**: `gyros-tunnel.service` usa `ssh` directo con `Restart=always` (no `autossh`) a propósito: así cada reconexión vuelve a ejecutar `ExecStartPre=gyros-tunnel-cleanup.sh`, que libera la sesión sshd huérfana que retiene el puerto en flamenco tras un corte de red (síntoma: `remote port forwarding failed for listen port`). El script solo mata si hay exactamente una candidata, porque desde `marco` no se puede distinguir el túnel de un agente del de otro; el porqué completo está en su encabezado.

### gyrosfe

PHP plano, un archivo por endpoint:

- `ui/main.php` — la aplicación entera (~2700 líneas: consulta principal, HTML, modales y todo el JS inline). `index.php` y el login redirigen ahí; `ui/dashboard.php` (estado de agentes y dispositivos) ya no es el destino del login.
- `api/*.php` — endpoints JSON llamados por `fetch` desde `main.php`. Patrón común: `auth_require_login()` → validar `$_POST` → `db_connect()` → sentencias preparadas → `{"ok": bool, ...}`.
- `agent/*.php` — endpoints para los agentes. `heartbeat.php` y `usb_event.php` validan el token del agente; `usb_events.php` y `devices.json.php` son vistas de diagnóstico sin autenticación.
- `lib/auth.php` (sesión con cookie restringida a `/gyrosfe`), `lib/db_connect.php` (PDO).

**Nombres en PostgreSQL**: el esquema mezcla tablas y columnas camelCase que exigen comillas dobles (`"Agent"`, `"UsbDeviceState"`, `"Cliente"`, `"Heartbeat"`, `"clienteIdCliente"`, `"prestamoIdPrestamo"`, `"isActive"`, `"tunnelPort"`) con otras en minúsculas (`pago`, `prestamo`, `banco_cliente`, `saldo`). Copiar el entrecomillado exacto de una consulta existente. PDO devuelve las claves del array con esas mismas mayúsculas (`$row['prestamoIdPrestamo']`, no `prestamoidprestamo`).

**Préstamos**: `api/prestamo_crear.php` genera el plan con amortización francesa (cuota fija, `tasa_interes` mensual en %) e inserta una fila `pago` por cuota. Modo `migrar` registra solo las cuotas restantes de un préstamo preexistente a partir de `numero_cuota_actual` / `saldo_pendiente_actual`. Al debitar se calculan aparte los valores reales (`dias_real`, `interes_real`, `capital_real`, `saldo_deudor_real`) con interés diario = tasa / 100 / 30 sobre los días transcurridos desde el débito de la cuota anterior (o desde `fecha_prestamo` en la cuota 1).

**Migraciones** (`migrations/`): no hay runner. `.htaccess` bloquea el acceso HTTP y los `.php` llaman a `auth_require_login()`, que corta la ejecución por CLI; en la práctica el SQL se aplica a mano con `psql` y el archivo queda como registro, con fecha de aplicación y rollback comentado.

## Restricciones operativas

- No hacer `git push`, reiniciar servicios ni lanzar débitos sin confirmación explícita del usuario.
- No mostrar ni registrar valores de `.env`, tokens de agente, llaves SSH ni credenciales bancarias (`banco_cliente.usuario` / `key`).
- En `flamenco.cnb.net` el usuario `marco` no tiene sudo: no reintentar `sudo` (genera alertas de seguridad). El host es compartido (PM2, VSCode Server, `php-fpm`), así que cualquier limpieza de procesos ahí debe ser quirúrgica, nunca por patrón amplio.
