# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Qué es esto

Gyros es un sistema de gestión de préstamos (QuanticaSoft, Bolivia) que cobra cuotas debitando la cuenta del cliente mediante la app **UNImóvil Plus** del Banco Unión, automatizada sobre teléfonos Android físicos conectados por USB a hosts "agente".

## Repositorio y despliegue

Monorepo en `https://github.com/QuanticaSoft/gyros.git`. **`main` es lo que va a producción.** El tag `inicio-2026-10-05` marca el punto de partida del repo unificado. Cada subcarpeta se despliega en un host distinto:

| Carpeta | Qué es | Host (SSH) | Ruta en el host | Puerto del túnel |
|---|---|---|---|---|
| `gyrosfe/` | Backend + UI web en PHP 8 / PostgreSQL (sin framework, sin Composer) | `marco@flamenco.cnb.net` | `/webs/quanticasoft/gyrosfe` | — |
| `cbb01/` | Agente (Perl + Python), Cochabamba | `robot@100.107.84.95` (Tailscale) | `/opt/gyros/agent` | 8080 |
| `scz01/` | Agente (Perl + Python), Santa Cruz | `agentescz1@100.117.246.119` (Tailscale) | `/home/agentescz1/scz1` | 8081 |

La web se usa en `https://www.quanticasoft.com/gyrosfe/ui/login.php`. El puerto del túnel es el puerto remoto en flamenco (`TUNNEL_REMOTE_PORT` en el `.env` del agente, `Agent.tunnelPort` en la DB); en el propio agente Flask siempre escucha en `:8080`.

`cbb01/` y `scz01/` contienen **el mismo código y deben quedar byte a byte idénticos** (`diff -rq cbb01 scz01` no debe reportar nada). Todo cambio en el agente se aplica en ambas carpetas. Lo que difiere por host vive fuera del código: el `.env` (ignorado por git) y la línea `User=` de `gyros-tunnel.service` / `gyros-union-server.service`.

`<agente>/memory.md` es la bitácora operativa compartida entre hosts (hallazgos, decisiones, pendientes, notas de conexión, checklist de salud). **Leerla antes de tocar el agente o su despliegue** y actualizarla tras cada hallazgo relevante. Esa bitácora menciona un `CLAUDE.md` propio del agente con la tabla de hosts y de variables `.env`; no está incluido en esta copia.

## Comandos

No hay build, linter ni suite de tests en ninguno de los dos proyectos.

Agente (desde `cbb01/` o `scz01/`, requiere teléfono por ADB y `.env` con las variables `BU_*`):

```bash
pip install -r requirements.txt flask   # flask lo importa union/server.py pero falta en requirements.txt
python -m union.main                    # flujo de consulta de saldo (pasos 1–10) contra el teléfono, imprime el saldo
python -m union.server                  # servidor Flask en 0.0.0.0:8080 (lo que corre gyros-union-server.service)
perl -c heartbeat.pl                    # chequeo de sintaxis de un script Perl
```

`python -m union.server` con un `POST /debitar` ejecuta una **transferencia ACH real**; no hay modo de prueba ni dry-run.

gyrosfe:

```bash
php -l api/debitar.php                  # chequeo de sintaxis; no hay otro tooling
```

No corre en local tal cual: `lib/db_connect.php` lee credenciales de `/webs/quanticasoft/_private/db.php` (ruta absoluta del servidor, debe devolver `['dsn','user','pass']`), la cookie de sesión exige HTTPS y todas las URLs están fijas bajo `/gyrosfe/`.

En los hosts agente (salud y diagnóstico, detalle en `memory.md`):

```bash
systemctl status gyros-agent gyros-usb-monitor gyros-union-server gyros-tunnel --no-pager
journalctl -u gyros-union-server -f     # log "[PASO N] ..." de la automatización
journalctl -u gyros-tunnel -n 30 --no-pager
adb devices
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

### Agente (`cbb01/`, `scz01/`)

Tres piezas independientes, cada una con su unit en `systemd/`:

- **`union/`** — servidor Flask. `steps.py` (pasos 1–10: login, lectura de saldo, cierre de sesión y de app) y `steps_transferencia.py` (pasos 11–18: transferencia ACH hacia la "cuenta oficina" fija definida por `BU_OFICINA_*`). Cada paso es una función `pasoN_*` que valida la pantalla esperada y lanza excepción si no coincide; `server.py` solo los encadena. `FueraDeHorarioACH` y `DestinatarioNoCoincide` se devuelven como 409. Hay un lock por serial ADB: dispositivos distintos corren en paralelo, el mismo dispositivo responde 503 si está ocupado. Las credenciales bancarias llegan en el body de cada request (vienen de la DB de gyrosfe); las `BU_USUARIO`/`BU_PASSWORD` del `.env` solo las usa `union/main.py` para pruebas manuales.
- **`gyros-agent.pl`** — supervisor que hace fork de `heartbeat.pl` (POST periódico a `gyrosfe/agent/heartbeat.php`) y `detecta.pl` (escucha `udevadm`, reporta connect/disconnect a `gyrosfe/agent/usb_event.php`, con ventana de gracia para re-enumeraciones USB). Ambos se autentican con headers `x-agent-id` / `x-agent-token` contra la tabla `Agent`. `detecta.pl` es lo que alimenta `UsbDeviceState`, es decir, el ruteo del paso 3.
- **`usb-monitor.pl`** — segundo reporte USB, por socket TCP crudo a `flamenco.cnb.net:4000` (`config.conf`). Redundante con `detecta.pl`; no está decidido cuál es el vigente — no tocar sin confirmar.

Los scripts Perl y los units tienen fija la ruta `/opt/gyros/agent` (incluido `.env` y `config.conf`). Los valores por host (`AGENT_ID`, `AGENT_TOKEN`, `TUNNEL_SSH_KEY`, `TUNNEL_REMOTE_PORT`, `PYTHON_BIN`, `BU_*`) salen del `.env`; no volver a hardcodearlos, porque rompe la igualdad entre las dos carpetas.

`banco_union/` es código legado anterior a `union/` (solo lo referencia `setup.py`). No extenderlo, y no borrarlo sin confirmación.

`systemd/gyros-tunnel-cleanup.sh` mata sesiones sshd huérfanas en flamenco, un host compartido con otros servicios. Su lógica es deliberadamente conservadora (solo actúa si hay exactamente una candidata); el porqué está en el encabezado del script y en `memory.md`.

### gyrosfe

PHP plano, un archivo por endpoint:

- `ui/main.php` — la aplicación entera (~2700 líneas: consulta principal, HTML, modales y todo el JS inline). `index.php` redirige ahí o a `ui/login.php`.
- `api/*.php` — endpoints JSON llamados por `fetch` desde `main.php`. Patrón común: `auth_require_login()` → validar `$_POST` → `db_connect()` → sentencias preparadas → `{"ok": bool, ...}`.
- `agent/*.php` — endpoints para los agentes. `heartbeat.php` y `usb_event.php` validan el token del agente; `usb_events.php` y `devices.json.php` son vistas de diagnóstico sin autenticación.
- `lib/auth.php` (sesión con cookie restringida a `/gyrosfe`), `lib/db_connect.php` (PDO).

**Nombres en PostgreSQL**: el esquema mezcla tablas y columnas camelCase que exigen comillas dobles (`"Agent"`, `"UsbDeviceState"`, `"Cliente"`, `"Heartbeat"`, `"clienteIdCliente"`, `"prestamoIdPrestamo"`, `"isActive"`, `"tunnelPort"`) con otras en minúsculas (`pago`, `prestamo`, `banco_cliente`, `saldo`). Copiar el entrecomillado exacto de una consulta existente.

**Préstamos**: `api/prestamo_crear.php` genera el plan con amortización francesa (cuota fija, `tasa_interes` mensual en %) e inserta una fila `pago` por cuota. Modo `migrar` registra solo las cuotas restantes de un préstamo preexistente a partir de `numero_cuota_actual` / `saldo_pendiente_actual`. Al debitar se calculan aparte los valores reales (`dias_real`, `interes_real`, `capital_real`, `saldo_deudor_real`) con interés diario = tasa / 100 / 30 sobre los días transcurridos desde el débito de la cuota anterior.

**Migraciones** (`migrations/`): no hay runner. `.htaccess` bloquea el acceso HTTP y los `.php` llaman a `auth_require_login()`, que corta la ejecución por CLI; en la práctica el SQL se aplica a mano con `psql` y el archivo queda como registro, con fecha de aplicación y rollback comentado.

## Restricciones operativas

- No hacer `git push`, reiniciar servicios ni lanzar débitos sin confirmación explícita del usuario.
- No mostrar ni registrar valores de `.env`, tokens de agente, llaves SSH ni credenciales bancarias (`banco_cliente.usuario` / `key`).
- En `flamenco.cnb.net` el usuario `marco` no tiene sudo: no reintentar `sudo` (genera alertas de seguridad), y cualquier limpieza de procesos ahí debe ser quirúrgica.
