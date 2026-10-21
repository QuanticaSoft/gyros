<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
auth_require_login();

function esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$puedeVerEstadisticas = auth_puede_ver_estadisticas();

$userName = trim((string) ($_SESSION['full_name'] ?? ''));
if ($userName === '') {
    $userName = (string) ($_SESSION['email'] ?? 'Usuario');
}

// -------------------------------------------------------------------------------------
// Umbral online (segundos)
$onlineThresholdSec = 120; // 2 min

date_default_timezone_set('UTC');
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

require_once __DIR__ . '/../lib/db_connect.php';
require_once __DIR__ . '/../lib/atm.php';

try {
    $pdo = db_connect();
} catch (Throwable $e) {
    http_response_code(500);
    echo "DB connection failed";
    exit;
}

// -------------------------------------------------------------------------------------
// Query: dispositivos ACTUALMENTE CONECTADOS al USB del agente
// — Fuente de verdad: UsbDeviceState (estado en tiempo real, actualizado por el agente)
// — Solo status = 'connected'
// — Solo agentes con lastSeen dentro del umbral (ONLINE)
// — Estado válido: lastChangeAt >= arranque del agente (lastSeen - uptimeSec)
//   Esto descarta estados obsoletos de sesiones anteriores del agente
// -------------------------------------------------------------------------------------
$sqlUsb = <<<'SQL'
SELECT
  uds.serial                               AS ev_serial,
  uds.vendor                               AS ev_vendor,
  uds.product                              AS ev_product,
  uds."lastChangeAt"                       AS ev_at,

  a."agentId"                              AS ag_agentId,
  a.hostname                               AS ag_hostname,

  cl.uuid                                  AS cl_uuid,
  cl.nombrecompleto                        AS cl_nombre,
  cl.ci                                    AS cl_ci,
  cl.numerocelular                         AS cl_celular,
  cl.numerofijo                            AS cl_fijo,
  cl.vtotarjeta                            AS cl_vtotarjeta,
  cl.codigo                                AS cl_codigo,
  cl.sector                                AS cl_sector,
  cl."isActive"                            AS cl_activo,
  cl.garantenombre                         AS cl_garantenombre,
  cl.garantecelular                        AS cl_garantecelular,
  cl.garante2nombre                        AS cl_garante2nombre,
  cl.garante2celular                       AS cl_garante2celular,
  cl.observaciones                         AS cl_observaciones,
  cl.fecharegistro                         AS cl_fecharegistro,

  pp.id_pago                               AS pp_id_pago,
  pp.fecha_pago                            AS pp_fecha,
  pp.estado_calc                           AS pp_estado,
  pp.mes                                   AS pp_mes,
  pp.dia_prestamo                          AS pp_dia_prestamo,

  bc.usuario                               AS bc_usuario,
  bc.key                                   AS bc_key,
  bcp.banco                                AS bcp_banco,
  bcp.credenciales                         AS bcp_credenciales,

  sl.saldo                                 AS sl_saldo,
  sl.fecha_hora                            AS sl_fecha_hora,
  sd.sld_antes                             AS sd_antes,
  sd.sld_despues                           AS sd_despues,

  lp.lp_total                              AS lp_total,
  lp.lp_detalle                            AS lp_detalle,
  lp.lp_cantidad                           AS lp_cantidad,

  cq.cq_total                              AS cq_total,
  cq.cq_detalle                            AS cq_detalle,
  cq.cq_cantidad                           AS cq_cantidad

FROM "UsbDeviceState" uds
JOIN "Agent" a ON a.id = uds."agentId"
LEFT JOIN LATERAL (
    SELECT "uptimeSec"
    FROM "Heartbeat"
    WHERE "agentId" = a.id
    ORDER BY "createdAt" DESC
    LIMIT 1
) hb ON true
LEFT JOIN "Cliente" cl ON cl.dispositivo = uds.serial
LEFT JOIN LATERAL (
    SELECT
        pg.id_pago,
        pg.fecha_pago,
        pg.mes,
        EXTRACT(DAY FROM pr.fecha_prestamo)::int AS dia_prestamo,
        CASE
            WHEN pg.estado = 'pagado'        THEN 'pagado'
            WHEN pg.fecha_pago < CURRENT_DATE THEN 'vencido'
            ELSE 'pendiente'
        END AS estado_calc
    FROM "pago" pg
    JOIN "prestamo" pr ON pr."id_prestamo" = pg."prestamoIdPrestamo"
    WHERE pr."clienteIdCliente" = cl.uuid
      AND pr."isActive" = true
      AND pg."isActive" = true
      AND pg.estado <> 'pagado'
    ORDER BY pg.fecha_pago ASC
    LIMIT 1
) pp ON true
LEFT JOIN LATERAL (
    SELECT bc.usuario, bc.key
    FROM "banco_cliente" bc
    WHERE bc."clienteIdCliente" = cl.uuid
      AND bc."isActive" = true
    LIMIT 1
) bc ON true
LEFT JOIN LATERAL (
    -- Cuenta desde la que se debita (misma que usa api/debitar.php); de las credenciales
    -- solo importa si están configuradas
    SELECT COALESCE(bc.banco, '') AS banco,
           (trim(COALESCE(bc.usuario, '')) <> '' AND trim(COALESCE(bc.key, '')) <> '') AS credenciales
    FROM "banco_cliente" bc
    WHERE bc."clienteIdCliente" = cl.uuid
      AND lower(bc.nickname) = 'pago'
      AND bc."isActive" = true
    LIMIT 1
) bcp ON true
LEFT JOIN LATERAL (
    SELECT s.saldo, s.fecha_hora
    FROM saldo s
    WHERE s.cliente_id = cl.uuid
    ORDER BY s.fecha_hora DESC
    LIMIT 1
) sl ON true
LEFT JOIN LATERAL (
    -- Saldo antes → después del último ATM del cliente
    SELECT t.saldo_antes AS sld_antes, t.saldo_despues AS sld_despues
    FROM transferencia t
    WHERE t.cliente_id = cl.uuid
    ORDER BY t.nro_operacion DESC
    LIMIT 1
) sd ON true
/* Antes el par antes/después salía de la última cuota pagada; el ATM ya no paga cuotas.
LEFT JOIN LATERAL (
    -- Última cuota debitada (pagada) del cliente, para mostrar su par antes/después
    SELECT pg.id_pago
    FROM "pago" pg
    JOIN "prestamo" pr ON pr."id_prestamo" = pg."prestamoIdPrestamo"
    WHERE pr."clienteIdCliente" = cl.uuid
      AND pg.estado = 'pagado'
      AND pg."isActive" = true
    ORDER BY pg."fecha_de_debito" DESC NULLS LAST, pg."updated_at" DESC
    LIMIT 1
) lastpaid ON true
LEFT JOIN LATERAL (
    SELECT
        MAX(CASE WHEN s.tipo = 'antes'   THEN s.saldo END) AS sld_antes,
        MAX(CASE WHEN s.tipo = 'despues' THEN s.saldo END) AS sld_despues
    FROM saldo s
    WHERE s.id_pago = lastpaid.id_pago
) sd ON true
*/
LEFT JOIN LATERAL (
    SELECT
        SUM(pr.monto_prestado)::numeric                                              AS lp_total,
        STRING_AGG(pr.monto_prestado::text, '|' ORDER BY pr.fecha_prestamo ASC)     AS lp_detalle,
        COUNT(*)::int                                                                AS lp_cantidad
    FROM "prestamo" pr
    WHERE pr."clienteIdCliente" = cl.uuid
      AND pr."isActive" = true
) lp ON true
LEFT JOIN LATERAL (
    SELECT
        SUM(pg.cuota_fija)::numeric                                                  AS cq_total,
        STRING_AGG(pg.cuota_fija::text, '|' ORDER BY pg.fecha_pago ASC)             AS cq_detalle,
        COUNT(*)::int                                                                AS cq_cantidad
    FROM "pago" pg
    JOIN "prestamo" pr ON pr."id_prestamo" = pg."prestamoIdPrestamo"
    WHERE pr."clienteIdCliente" = cl.uuid
      AND pr."isActive" = true
      AND pg."isActive" = true
      AND pg.estado = 'pendiente'
      AND EXTRACT(YEAR  FROM pg.fecha_pago) = EXTRACT(YEAR  FROM CURRENT_DATE)
      AND EXTRACT(MONTH FROM pg.fecha_pago) = EXTRACT(MONTH FROM CURRENT_DATE)
) cq ON true

WHERE
    -- Dispositivo físicamente conectado AHORA
    lower(uds.status) = 'connected'
    -- Agente ONLINE: lastSeen dentro del umbral
    AND EXTRACT(EPOCH FROM (now() - a."lastSeen"))::int <= :threshold
    -- Estado reportado en la sesión actual del agente (no es un estado obsoleto de sesiones anteriores)
    AND uds."lastChangeAt" >= (a."lastSeen" - COALESCE(hb."uptimeSec", 0) * interval '1 second')

ORDER BY uds."lastChangeAt" DESC
SQL;

$stmtUsb = $pdo->prepare($sqlUsb);
$stmtUsb->execute([':threshold' => $onlineThresholdSec]);
$usbRows = $stmtUsb->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="/gyrosfe/assets/app.css?v=1">
    <style>
        /* ── Modales ────────────────────────────────────────────── */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.55);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.active { display: flex; }

        .modal-box {
            background: #1e2130;
            border: 1px solid #33374d;
            border-radius: 10px;
            padding: 28px 32px;
            min-width: 360px;
            max-width: 520px;
            width: 100%;
            color: #e0e4f0;
            position: relative;
        }
        .modal-box h2 { margin: 0 0 20px; font-size: 1.1rem; }
        .modal-close {
            position: absolute;
            top: 14px; right: 18px;
            background: none; border: none;
            color: #888; font-size: 1.4rem;
            cursor: pointer;
        }
        .modal-close:hover { color: #fff; }

        .modal-form label {
            display: block;
            font-size: .78rem;
            color: #9aa0b8;
            margin-bottom: 3px;
            margin-top: 12px;
        }
        .modal-form input, .modal-form select {
            width: 100%;
            box-sizing: border-box;
            padding: 7px 10px;
            border-radius: 6px;
            border: 1px solid #33374d;
            background: #131624;
            color: #e0e4f0;
            font-size: .9rem;
        }
        .modal-form input[readonly] {
            opacity: .6;
            cursor: not-allowed;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }
        .btn-primary {
            padding: 8px 20px;
            border-radius: 6px;
            border: none;
            background: #4f6ef7;
            color: #fff;
            font-size: .9rem;
            cursor: pointer;
        }
        .btn-primary:hover { background: #3a57e8; }
        .btn-cancel {
            padding: 8px 16px;
            border-radius: 6px;
            border: 1px solid #33374d;
            background: transparent;
            color: #9aa0b8;
            font-size: .9rem;
            cursor: pointer;
        }
        .btn-cancel:hover { color: #fff; border-color: #888; }

        /* info-grid dentro del modal 📝 */
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 16px; margin-top: 4px; }
        .info-cell label { font-size:.75rem; color:#9aa0b8; }
        .info-cell p { margin:2px 0 0; font-size:.9rem; }

        /* ── Buscador de devoluciones en toolbar ───────────────── */
        .devol-buscador {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-left: auto;
        }
        .devol-titulo {
            font-size: .82rem;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: #e0e4f0;
        }
        .devol-caja {
            display: inline-flex;
            align-items: center;
            border: 1px solid #2a2f45;
            border-radius: 10px;
            background: #131624;
            overflow: hidden;
        }
        .devol-caja:focus-within { border-color: #7b93ff; }
        .devol-caja button {
            width: auto;
            background: #1a1f35;
            border: none;
            padding: 7px 10px;
            font-size: .95rem;
            cursor: pointer;
            line-height: 1;
        }
        .devol-caja button:hover { background: #262c48; }
        .devol-caja button:disabled { opacity: .4; cursor: wait; }
        .toolbar .devol-caja input {
            min-width: 150px;
            width: 150px;
            border: none;
            border-radius: 0;
            background: transparent;
            color: #e0e4f0;
            outline: none;
        }
        #modal-devoluciones .modal-box { max-width: 1100px; }

        /* ── Estadísticas generales ────────────────────────────── */
        .estad-btn {
            width: auto;
            margin-left: 18px;
            padding: 7px 12px;
            border: 1px solid #2a2f45;
            border-radius: 10px;
            background: #1a1f35;
            color: #e0e4f0;
            font-size: .82rem;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            cursor: pointer;
        }
        .estad-btn:hover { background: #262c48; }
        #modal-estadisticas .modal-box { max-width: 1100px; }
        .estad-filtros { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 10px 14px; margin-bottom: 18px; }
        .estad-filtros label { display: block; font-size: .75rem; color: #9aa0b8; margin-bottom: 4px; }
        .estad-filtros input, .estad-filtros select { width: auto; }
        .estad-filtros button { width: auto; }
        .estad-atajos { display: flex; gap: 6px; }
        .estad-tarjetas { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; margin-bottom: 18px; }
        .estad-tarjeta { border: 1px solid #2a2f45; border-radius: 10px; background: #131624; padding: 12px 14px; }
        .estad-tarjeta .t { font-size: .72rem; letter-spacing: .07em; text-transform: uppercase; color: #9aa0b8; }
        .estad-tarjeta .v { font-size: 1.35rem; font-weight: 700; color: #e0e4f0; margin-top: 4px; }
        .estad-tarjeta .s { font-size: .78rem; color: #9aa0b8; margin-top: 2px; }

        /* ── Badge online en toolbar ───────────────────────────── */
        .tabla-cabecera {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 14px;
            background: #131624;
            border-bottom: 1px solid #1e2235;
        }
        .tabla-cabecera-titulo {
            font-size: .82rem;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: #e0e4f0;
        }
        .tabla-cabecera-controles {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .tabla-cabecera select {
            background: #1a1f35;
            color: #c0c8e8;
            border: 1px solid #2a2f45;
            border-radius: 6px;
            padding: 4px 10px;
            font-size: .78rem;
            cursor: pointer;
            outline: none;
        }
        .tabla-cabecera select:hover {
            border-color: #4a5080;
        }
        .btn-refresh {
            background: #1a1f35;
            border: 1px solid #2a2f45;
            border-radius: 6px;
            padding: 4px 9px;
            font-size: .88rem;
            cursor: pointer;
            color: #c0c8e8;
            line-height: 1;
        }
        .btn-refresh:hover { border-color: #4a5080; }
        .badge-online {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: rgba(52, 211, 153, .15);
            color: #34d399;
            border: 1px solid rgba(52, 211, 153, .35);
            border-radius: 20px;
            padding: 3px 12px;
            font-size: .78rem;
            font-weight: 600;
            letter-spacing: .04em;
        }
        .badge-online::before {
            content: '';
            display: inline-block;
            width: 7px; height: 7px;
            border-radius: 50%;
            background: #34d399;
            box-shadow: 0 0 6px #34d399;
            animation: pulse-dot 2s infinite;
        }
        @keyframes pulse-dot {
            0%, 100% { opacity: 1; }
            50%       { opacity: .4; }
        }

        /* ── Botones de acción en la tabla ─────────────────────── */
        .btn-accion {
            width: auto;
            background: none;
            border: none;
            font-size: 1.3rem;
            cursor: pointer;
            line-height: 1;
            padding: 2px 4px;
            border-radius: 4px;
            transition: background .15s;
        }
        .btn-accion:hover { background: rgba(255,255,255,.1); }
        .btn-accion:disabled { opacity: .35; cursor: not-allowed; }

        /* ── Columna Aporte: DEBITAR / APORTE / DEVOLUCION ─────── */
        .aporte-grid {
            display: grid;
            grid-template-columns: 112px 98px 34px;
            grid-auto-rows: minmax(30px, auto);
            align-items: center;
            column-gap: 8px;
            font-size: .78rem;
            color: #9aa0b8;
            white-space: nowrap;
        }
        .aporte-grid .aporte-etiqueta { grid-column: 1; text-align: right; letter-spacing: .03em; }
        .aporte-grid .aporte-input    { grid-column: 2; }
        .aporte-grid .aporte-calc     { grid-column: 2; text-align: right; padding-right: 7px;
                                        font-size: .82rem; font-weight: 600; color: #34d399; }
        .aporte-grid .aporte-calc.negativo { color: #f87171; }
        /* El 🏧 va después de APORTE en el DOM (orden del Tab) pero se ve junto a DEBITAR */
        .aporte-grid .btn-accion      { grid-row: 1; grid-column: 3; }
        .btn-accion:focus-visible { outline: 2px solid #7b93ff; outline-offset: 2px; }
        .aporte-resultado { font-size: .78rem; text-align: right; }
        .aporte-resultado:empty { display: none; }
        .aporte-input {
            box-sizing: border-box;
            width: 100%;
            padding: 3px 6px;
            text-align: right;
            font-size: .82rem;
            font-weight: 600;
            color: #1a3fc4;
            background: rgba(255,255,255,.05);
            border: 1px solid #2a2f45;
            border-radius: 6px;
        }
        .aporte-input::placeholder { color: #9aa0b8; font-weight: 400; }
        .aporte-input:disabled { opacity: .45; }
        .aporte-ocupado .aporte-input,
        .aporte-ocupado .btn-accion { pointer-events: none; opacity: .4; }

        /* ── Info del dispositivo en modal ────────────────────── */
        .device-info-bar {
            background: #131624;
            border-radius: 6px;
            padding: 8px 12px;
            margin-bottom: 14px;
            font-size: .82rem;
            color: #9aa0b8;
        }
        .device-info-bar span { color: #c8d0ea; margin-left: 4px; }

        /* ── Layout de 2 columnas dentro del modal ──────────────── */
        .form-row {
            display: grid;
            gap: 0 14px;
        }
        .form-row.col2 { grid-template-columns: 1fr 1fr; }
        .form-row.col3 { grid-template-columns: 1fr 1fr 1fr; }

        /* ── Separador de sección dentro del modal ──────────────── */
        .form-section {
            margin-top: 16px;
            padding-top: 10px;
            border-top: 1px solid #33374d;
            font-size: .78rem;
            font-weight: 700;
            color: #c8d0ea;
            letter-spacing: .06em;
        }

        /* modal más ancho para caber los nuevos campos */
        #modal-registrar .modal-box,
        #modal-editar .modal-box {
            max-width: 640px;
            max-height: 90vh;
            overflow-y: auto;
        }

        /* ── Modal 📲 Registro Cliente (tabbed) ────────────────── */
        #modal-registro .modal-box {
            width: 1420px;
            max-width: 99vw;
            height: 720px;
            max-height: 96vh;
            display: flex;
            flex-direction: column;
            padding: 0;
            overflow: hidden;
        }
        .registro-header {
            flex-shrink: 0;
            padding: 20px 28px 0;
            border-bottom: 1px solid #33374d;
        }
        .registro-header h2 {
            margin: 0 0 14px;
            font-size: 1.05rem;
            color: #e0e4f0;
            padding-right: 32px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .registro-header h2 span {
            color: #7b93ff;
        }

        /* Tabs nav */
        .tab-nav {
            display: flex;
            gap: 0;
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .tab-nav li {
            padding: 9px 22px;
            font-size: .8rem;
            font-weight: 700;
            letter-spacing: .07em;
            color: #9aa0b8;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            user-select: none;
            transition: color .15s, border-color .15s;
        }
        .tab-nav li:hover { color: #c8d0ea; }
        .tab-nav li.disabled,
        .tab-nav li.disabled:hover { opacity: .35; color: #9aa0b8; cursor: not-allowed; }
        .tab-nav li.active {
            color: #7b93ff;
            border-bottom-color: #7b93ff;
        }

        /* Tab panels — cuerpo de altura fija con scroll interno */
        .tab-body {
            flex: 1;
            overflow-y: auto;
            padding: 22px 28px 28px;
        }
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }

        /* General info grid */
        .gen-section {
            font-size: .72rem;
            font-weight: 700;
            letter-spacing: .07em;
            color: #7b93ff;
            text-transform: uppercase;
            margin: 18px 0 8px;
            padding-bottom: 5px;
            border-bottom: 1px solid #2a2f45;
        }
        .gen-section:first-child { margin-top: 0; }
        .gen-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px 20px;
        }
        .gen-grid.col3 { grid-template-columns: 1fr 1fr 1fr; }
        .gen-cell label {
            display: block;
            font-size: .72rem;
            color: #9aa0b8;
            margin-bottom: 2px;
        }
        .gen-cell p {
            margin: 0;
            font-size: .9rem;
            color: #e0e4f0;
            word-break: break-word;
        }
        .gen-cell p.empty { color: #555c7a; font-style: italic; }

        /* Toggle switch activo */
        .toggle-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 2px;
        }
        .toggle-switch {
            position: relative;
            width: 42px;
            height: 22px;
            flex-shrink: 0;
        }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-track {
            position: absolute;
            inset: 0;
            border-radius: 22px;
            background: #33374d;
            cursor: pointer;
            transition: background .2s;
        }
        .toggle-track::after {
            content: '';
            position: absolute;
            top: 3px; left: 3px;
            width: 16px; height: 16px;
            border-radius: 50%;
            background: #9aa0b8;
            transition: transform .2s, background .2s;
        }
        .toggle-switch input:checked + .toggle-track {
            background: rgba(52,211,153,.35);
            border: 1px solid rgba(52,211,153,.5);
        }
        .toggle-switch input:checked + .toggle-track::after {
            transform: translateX(20px);
            background: #34d399;
        }
        .toggle-switch input:disabled + .toggle-track { opacity: .5; cursor: not-allowed; }
        .toggle-label {
            font-size: .88rem;
            font-weight: 600;
            transition: color .2s;
        }
        .toggle-label.activo  { color: #34d399; }
        .toggle-label.inactivo { color: #f87171; }
        .toggle-saving { font-size: .75rem; color: #9aa0b8; margin-left: 4px; display: none; }

        /* Placeholder tabs */
        .tab-placeholder {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            min-height: 340px;
            color: #555c7a;
            font-size: .9rem;
            gap: 10px;
        }
        .tab-placeholder span { font-size: 2.2rem; }

        /* ── Tabla de Préstamos ─────────────────────────────────── */
        .prest-table-wrap { overflow-x: auto; }
        .prest-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .82rem;
        }
        .prest-table th {
            background: #1a1f35;
            color: #7b93ff;
            font-size: .72rem;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            padding: 8px 10px;
            text-align: left;
            border-bottom: 2px solid #2a2f45;
            white-space: nowrap;
        }
        .prest-table td {
            padding: 8px 10px;
            border-bottom: 1px solid #1e2235;
            color: #c8d0ea;
            vertical-align: middle;
        }
        .prest-table tr:hover td { background: rgba(123,147,255,.06); }
        .prest-table .td-add {
            text-align: center;
            color: #555c7a;
            font-style: italic;
            font-size: .8rem;
        }
        .prest-table .td-num { text-align: right; font-family: monospace; }
        .prest-table th.th-num { text-align: right; }

        /* ── Sección Pagos (por préstamo) ───────────────────────── */
        .pago-bloque { margin-bottom: 28px; }
        .pago-bloque-header {
            background: #1a1f35;
            border-left: 3px solid #7b93ff;
            padding: 8px 14px;
            margin-bottom: 8px;
            border-radius: 4px;
            font-size: .82rem;
            color: #c8d0ea;
            display: flex;
            gap: 18px;
            flex-wrap: wrap;
        }
        .pago-bloque-header strong { color: #7b93ff; }
        .pago-table-wrap { overflow-x: auto; }
        .pago-fecha-input {
            background: transparent;
            border: 1px solid transparent;
            border-radius: 4px;
            color: #c0c8e8;
            font-size: .76rem;
            padding: 2px 4px;
            width: 110px;
            cursor: pointer;
            color-scheme: dark;
        }
        .pago-fecha-input:hover { border-color: #4a5080; }
        .pago-fecha-input:focus { border-color: #7b93ff; outline: none; background: #0d1120; }
        .pago-fecha-input.saving { opacity: .5; pointer-events: none; }
        .pago-cuota-input {
            background: transparent;
            border: 1px solid #2a2f45;
            border-radius: 4px;
            color: #7b93ff;
            font-size: .80rem;
            font-weight: 600;
            padding: 2px 4px;
            width: 78px;
            text-align: center;
        }
        .pago-cuota-input:hover { border-color: #4a5080; }
        .pago-cuota-input:focus { border-color: #7b93ff; outline: none; background: #0d1120; }
        .pago-cuota-input.saving { opacity: .5; pointer-events: none; }
        .pago-cuota-input:disabled { opacity: .6; border-color: transparent; }
        .pago-cerrado { font-size: 1.1rem; cursor: default; }
        .pago-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .78rem;
        }
        .pago-table th {
            background: #131624;
            color: #9aa0b8;
            font-size: .68rem;
            font-weight: 700;
            letter-spacing: .05em;
            text-transform: uppercase;
            padding: 6px 8px;
            text-align: center;
            border-bottom: 2px solid #2a2f45;
            white-space: nowrap;
        }
        .pago-table td {
            padding: 5px 8px;
            border-bottom: 1px solid #1a1e2f;
            color: #c8d0ea;
            text-align: center;
            vertical-align: middle;
            white-space: nowrap;
        }
        .pago-table tr:hover td { background: rgba(255,255,255,.03); }
        /* Estados */
        .estado-pagado   { color: #34d399; font-weight: 700; }
        .estado-pendiente{ color: #fbbf24; font-weight: 700; }
        .estado-vencido  { color: #f87171; font-weight: 700; }
        .estado-parcial  { color: #60a5fa; font-weight: 700; }
        .estado-default  { color: #9aa0b8; }

        /* ── Valores Programado / Real en tabla de pagos ───────── */
        .val-prog { font-size: .72rem; color: #555c7a; }
        .val-real { font-size: .80rem; color: #7b93ff; font-weight: 600; }
        .val-real.late { color: #f87171; }
        .val-debito { font-size: .80rem; color: #fbbf24; font-weight: 600; }
        .transf-lineas { line-height: 1.5; }
        .transf-fila { display: flex; gap: 16px; white-space: nowrap; }
        /* flex:1 en los tres para que el del medio quede centrado aunque los textos tengan anchos distintos */
        .transf-fila > div { flex: 1; }
        .transf-fila > div:nth-child(1) { text-align: left; }
        .transf-fila > div:nth-child(2) { text-align: center; }
        .transf-fila > div:nth-child(3) { text-align: right; }
        .transf-etq { color: #9aa0b8; font-weight: 400; }
        .transf-totales td { border-top: 2px solid #2a2f45; }
        .transf-totales .transf-lineas, .transf-totales .transf-etq { color: #fb923c; font-weight: 700; }
        .pago-table th .th-pr {
            font-size: .58rem; color: #555c7a;
            font-weight: 400; letter-spacing: .02em;
        }

        /* ── Tab Banco ───────────────────────────────────────────── */
        .banco-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
            padding: 14px 0;
        }
        .banco-card {
            border: 1px solid #1e2235;
            border-radius: 10px;
            overflow: hidden;
        }
        .banco-card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            background: #0d1120;
            border-bottom: 1px solid #1e2235;
            min-height: 56px;
        }
        .banco-logo-wrap {
            width: 72px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #fff;
            border-radius: 6px;
            overflow: hidden;
            flex-shrink: 0;
        }
        .banco-logo-wrap img {
            max-width: 68px;
            max-height: 32px;
            object-fit: contain;
        }
        .banco-logo-placeholder {
            width: 72px;
            height: 36px;
            border-radius: 6px;
            background: #1e2235;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .7rem;
            color: #555c7a;
            flex-shrink: 0;
        }
        .banco-card-title {
            font-size: .8rem;
            font-weight: 600;
            color: #c0c8e8;
        }
        .banco-card-body {
            padding: 12px 14px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px 12px;
        }
        .banco-card-body .full { grid-column: span 2; }
        .banco-field label {
            display: block;
            font-size: .7rem;
            color: #555c7a;
            margin-bottom: 3px;
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .banco-field input,
        .banco-field select,
        .banco-field textarea {
            width: 100%;
            box-sizing: border-box;
            background: #0d1120;
            border: 1px solid #2a2f45;
            border-radius: 6px;
            color: #e0e4f0;
            padding: 5px 8px;
            font-size: .82rem;
            outline: none;
        }
        .banco-field textarea { resize: vertical; min-height: 48px; }
        .banco-field input:focus,
        .banco-field select:focus { border-color: #4a6fa5; }
        .bc-btn-view, .bc-btn-edit { display: flex; align-items: center; }
        .bc-view-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2px 0;
            padding: 10px 14px;
        }
        .bc-view-row {
            display: flex;
            flex-direction: column;
            padding: 6px 8px;
            border-bottom: 1px solid #1a1f35;
        }
        .bc-view-row.full { grid-column: span 2; }
        .bc-lbl {
            font-size: .65rem;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: #555c7a;
            margin-bottom: 2px;
        }
        .bc-val {
            font-size: .82rem;
            color: #c0c8e8;
        }

        /* ── Modal genérico flotante (préstamos) ────────────────── */
        .modal-float {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.6);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }
        .modal-float.active { display: flex; }
        .modal-float-box {
            background: #1e2130;
            border: 1px solid #33374d;
            border-radius: 10px;
            padding: 26px 30px;
            width: 400px;
            max-width: 96vw;
            color: #e0e4f0;
            position: relative;
        }
        .modal-float-box h3 { margin: 0 0 18px; font-size: 1rem; }
        .mf-close {
            position: absolute; top: 12px; right: 14px;
            background: none; border: none; color: #888;
            font-size: 1.3rem; cursor: pointer;
        }
        .mf-close:hover { color: #fff; }
        .mf-label {
            display: block;
            font-size: .75rem;
            color: #9aa0b8;
            margin: 10px 0 3px;
        }
        .mf-input {
            width: 100%; box-sizing: border-box;
            padding: 7px 10px;
            border-radius: 6px;
            border: 1px solid #33374d;
            background: #131624;
            color: #e0e4f0;
            font-size: .9rem;
        }
        .mf-note {
            font-size: .72rem;
            color: #555c7a;
            margin-top: 3px;
        }
        .mf-actions {
            display: flex; gap: 10px;
            justify-content: flex-end;
            margin-top: 18px;
        }
    </style>
</head>

<body>

    <div class="dashboard">
        <!-- Header -->
        <header class="header">
          <div class="header-left">
            <span class="user-label"><?= esc($userName) ?></span>
          </div>
          <div class="header-right">
            <button class="icon-btn" title="Salir" onclick="window.location.href='/gyrosfe/ui/logout.php'">
              <span class="logout-btn">❗</span>
            </button>
          </div>
        </header>

        <!-- Main Content -->
        <main class="main-content">
          <!--
          <section class="top-cards"></section>
          <section class="clientes-section">
            <div class="card lista-clientes-card"></div>
          </section>
          -->
        </main>
    </div>

    <!-- Buscar -->
    <div class="toolbar">
        <!-- Buscador general oculto a pedido; filterRows() tolera su ausencia.
        <input id="q" type="search" placeholder="Buscar por agente, serial, dispositivo, cliente..." oninput="filterRows()" />
        -->
        <a href="" title="Refrescar">Refrescar</a>
        <span class="badge-online"><?= count($usbRows) ?> online</span>
        <div class="devol-buscador">
<?php if ($puedeVerEstadisticas): ?>
            <button type="button" class="estad-btn" title="Totales generales de la plataforma" onclick="abrirEstadisticas()">📊 Estadísticas</button>
<?php endif; ?>
            <span class="devol-titulo">Devoluciones</span>
            <div class="devol-caja">
                <button type="button" id="devol-btn" title="Buscar devoluciones por CI o código" onclick="buscarDevoluciones()">🔍</button>
                <input id="devol-ci" type="search" placeholder="CI o Código"
                       onkeydown="if (event.key === 'Enter') buscarDevoluciones()" />
            </div>
        </div>
    </div>

    <!-- ── Tabla: dispositivos ONLINE + conectados ahora ─────────── -->
    <div class="tabla-cabecera">
        <span class="tabla-cabecera-titulo">Lista de Clientes</span>
        <div class="tabla-cabecera-controles">
            <select id="fil-sector" onchange="filterRows()">
                <option value="">Todos los sectores</option>
                <option value="Salud">Salud</option>
                <option value="Magisterio">Magisterio</option>
                <option value="Petrolero">Petrolero</option>
                <option value="Comerciantes">Comerciantes</option>
                <option value="Otros">Otros</option>
            </select>
            <select id="fil-estado" onchange="filterRows()">
                <option value="">Todos los estados</option>
                <option value="1">Activos</option>
                <option value="0">Inactivos</option>
            </select>
            <button class="btn-refresh" title="Refrescar" onclick="location.reload()">🔁</button>
        </div>
    </div>
    <table id="tbl">
        <thead>
            <tr>
                <th>Periodo</th>
                <th>Código</th>
                <th>Dispositivo</th>
                <th>Cliente</th>
                <th>Saldo en Banco</th>
                <th>Usuario / Key</th>
                <th>Monto Prestado</th>
                <th>Cuota</th>
                <th>Aporte</th>
                <th>Acción</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($usbRows)): ?>
            <tr><td colspan="10" style="text-align:center;color:#9aa0b8;padding:32px">No hay dispositivos online y conectados en este momento.</td></tr>
        <?php endif; ?>
        <?php foreach ($usbRows as $r):
            // ── Datos del dispositivo ──────────────────────────────
            $evAt    = !empty($r['ev_at'])
                ? (new DateTimeImmutable($r['ev_at'], new DateTimeZone('UTC')))->format('Y-m-d H:i:s')
                : '—';
            $agente    = esc((string) ($r['ag_hostname'] ?? $r['ag_agentid'] ?? '—'));
            $vendor    = esc((string) ($r['ev_vendor']  ?? ''));
            $product   = esc((string) ($r['ev_product'] ?? ''));
            $serial    = esc((string) ($r['ev_serial']  ?? ''));
            $dispLabel = trim("$vendor $product") ?: '—';

            // ── ¿Registrado en Cliente? ────────────────────────────
            $clienteUuid = (string) ($r['cl_uuid'] ?? '');
            $registrado  = ($clienteUuid !== '');

            // ── Datos cliente (para modal 📝) ──────────────────────
            $clNombre        = esc((string) ($r['cl_nombre']        ?? ''));
            $clCi            = esc((string) ($r['cl_ci']            ?? ''));
            $clCelular       = esc((string) ($r['cl_celular']       ?? ''));
            $clFijo          = esc((string) ($r['cl_fijo']          ?? ''));
            $clVtoTarjeta    = esc((string) ($r['cl_vtotarjeta']    ?? ''));
            $clCodigo        = esc((string) ($r['cl_codigo']        ?? ''));
            $clSector        = esc((string) ($r['cl_sector']        ?? ''));
            $clActivo        = !empty($r['cl_activo']) ? 'Sí' : 'No';
            $clActivoVal     = !empty($r['cl_activo']) ? '1' : '0';
            $clGaranteNombre = esc((string) ($r['cl_garantenombre'] ?? ''));
            $clGaranteCel    = esc((string) ($r['cl_garantecelular']?? ''));
            $clGarante2Nombre = esc((string) ($r['cl_garante2nombre']  ?? ''));
            $clGarante2Cel    = esc((string) ($r['cl_garante2celular'] ?? ''));
            $clObservaciones = esc((string) ($r['cl_observaciones'] ?? ''));
            $clFecha         = !empty($r['cl_fecharegistro'])
                ? (new DateTimeImmutable($r['cl_fecharegistro'], new DateTimeZone('UTC')))->format('Y-m-d H:i')
                : '—';

            // ── Periodo: día de préstamo . cuota actual / estado ───
            $ppEstado      = $r['pp_estado']      ?? null;
            $ppMes         = $r['pp_mes']         ?? null;
            $ppDiaPrestamo = $r['pp_dia_prestamo'] ?? null;
            $periodoHtml = '<span style="color:#555c7a">—</span>';
            if ($ppEstado !== null && $ppMes !== null && $ppDiaPrestamo !== null) {
                $dia        = str_pad((string) $ppDiaPrestamo, 2, '0', STR_PAD_LEFT);
                $cuotaNum   = str_pad((string) $ppMes, 2, '0', STR_PAD_LEFT);
                $estadoSlug = strtolower((string) $ppEstado);
                $periodoHtml = esc("$dia.$cuotaNum") . ' / <span class="estado-' . esc($estadoSlug) . '">' . esc((string) $ppEstado) . '</span>';
            }
        ?>
            <tr data-sector="<?= $clSector ?>" data-activo="<?= $clActivoVal ?>" data-uuid="<?= $clienteUuid ?>">
                <!-- Periodo -->
                <td class="mono" style="white-space:nowrap"><?= $periodoHtml ?></td>

                <!-- Código -->
                <td class="mono"><?= $clCodigo !== '' ? $clCodigo : '<span style="color:#555c7a">—</span>' ?></td>

                <!-- Dispositivo -->
                <td>
                    <div class="mono"><?= $serial !== '' ? $serial : '—' ?></div>
                    <div class="small" style="color:#9aa0b8;margin-top:3px">🖥 <?= $agente ?></div>
                    <div class="small mono" style="color:#555c7a"><?= esc($evAt) ?></div>
                </td>

                <!-- Cliente -->
                <td>
                <?php if ($registrado): ?>
                    <div><?= $clNombre ?></div>
                    <div class="small mono">CI: <?= $clCi ?></div>
                <?php else: ?>
                    <span style="color:#9aa0b8">—</span>
                <?php endif; ?>
                </td>

                <!-- Saldo en Banco -->
                <?php
                    $slSaldo     = $r['sl_saldo']     ?? null;
                    $slFechaHora = $r['sl_fecha_hora'] ?? null;
                    $sdAntes     = $r['sd_antes']      ?? null;
                    $sdDespues   = $r['sd_despues']    ?? null;
                    if ($slSaldo !== null) {
                        $saldoHtml = '<span style="color:#34d399;font-weight:700">Bs. ' . number_format((float) $slSaldo, 2) . '</span>';
                        if ($slFechaHora !== null) {
                            $dtSl = (new DateTimeImmutable($slFechaHora, new DateTimeZone('UTC')))
                                ->setTimezone(new DateTimeZone('America/La_Paz'));
                            $saldoHtml .= '<div class="small" style="color:#9aa0b8;margin-top:2px">' . esc($dtSl->format('d/m/y H:i')) . '</div>';
                        }
                        if ($sdAntes !== null && $sdDespues !== null) {
                            $saldoHtml .= '<div class="small mono" style="color:#7b93ff;margin-top:2px">'
                                . number_format((float) $sdAntes, 2) . ' → ' . number_format((float) $sdDespues, 2)
                                . '</div>';
                        }
                    } else {
                        $saldoHtml = '<span style="color:#555c7a">—</span>';
                    }
                ?>
                <td class="mono" id="saldo-cell-<?= esc($clienteUuid) ?>" style="white-space:nowrap"><?= $saldoHtml ?></td>

                <!-- Usuario / Key -->
                <?php
                    $bcUsuario = esc((string) ($r['bc_usuario'] ?? ''));
                    $bcKey     = esc((string) ($r['bc_key']     ?? ''));
                ?>
                <td class="mono">
                    <div><?= $bcUsuario !== '' ? $bcUsuario : '<span style="color:#555c7a">—</span>' ?></div>
                    <div class="small" style="color:#9aa0b8"><?= $bcKey !== '' ? $bcKey : '<span style="color:#555c7a">—</span>' ?></div>
                </td>

                <!-- Monto Prestado -->
                <?php
                    $lpCantidad = (int) ($r['lp_cantidad'] ?? 0);
                    $lpTotal    = $r['lp_total']   ?? null;
                    $lpDetalle  = $r['lp_detalle'] ?? null;
                    if ($lpCantidad === 0 || $lpTotal === null) {
                        $montoHtml = '<span style="color:#555c7a">—</span>';
                    } elseif ($lpCantidad === 1) {
                        $montoHtml = 'Bs. ' . number_format((float) $lpTotal, 2);
                    } else {
                        $partes = array_map(
                            fn($v) => number_format((float) $v, 2),
                            explode('|', (string) $lpDetalle)
                        );
                        $montoHtml = 'Bs. ' . number_format((float) $lpTotal, 2)
                                   . ' = ' . implode(' + ', $partes);
                    }
                ?>
                <td class="mono" style="white-space:nowrap"><?= $montoHtml ?></td>

                <!-- Cuota -->
                <?php
                    $cqCantidad = (int) ($r['cq_cantidad'] ?? 0);
                    $cqTotal    = $r['cq_total']   ?? null;
                    $cqDetalle  = $r['cq_detalle'] ?? null;
                    if ($cqCantidad === 0 || $cqTotal === null) {
                        $cuotaHtml = '<span style="color:#555c7a">—</span>';
                    } elseif ($cqCantidad === 1) {
                        $cuotaHtml = 'Bs. ' . number_format((float) $cqTotal, 2);
                    } else {
                        $partesCq = array_map(
                            fn($v) => number_format((float) $v, 2),
                            explode('|', (string) $cqDetalle)
                        );
                        $cuotaHtml = 'Bs. ' . number_format((float) $cqTotal, 2)
                                   . ' = ' . implode(' + ', $partesCq);
                    }
                ?>
                <td class="mono" style="white-space:nowrap"><?= $cuotaHtml ?></td>

                <!-- Aporte: DEBITAR − APORTE = DEVOLUCIÓN (no exige préstamo; queda en "transferencia") -->
                <?php
                    $atmBloqueo = $registrado ? atmMotivoBloqueo([
                        'isActive'       => $r['cl_activo']         ?? false,
                        'nombrecompleto' => $r['cl_nombre']         ?? '',
                        'ci'             => $r['cl_ci']             ?? '',
                        'numerocelular'  => $r['cl_celular']        ?? '',
                        'sector'         => $r['cl_sector']         ?? '',
                        'codigo'         => $r['cl_codigo']         ?? '',
                        'vtotarjeta'     => $r['cl_vtotarjeta']     ?? '',
                        'garantenombre'  => $r['cl_garantenombre']  ?? '',
                        'garantecelular' => $r['cl_garantecelular'] ?? '',
                    ], $r['bcp_banco'] !== null ? [
                        'banco'        => (string) $r['bcp_banco'],
                        'credenciales' => filter_var($r['bcp_credenciales'], FILTER_VALIDATE_BOOLEAN),
                    ] : null) : null;
                    $uuidJs = esc($clienteUuid);
                ?>
                <td id="debitar-cell-<?= $uuidJs ?>">
                <?php if (!$registrado): ?>
                    <span style="color:#555c7a">—</span>
                <?php else: ?>
                    <div class="aporte-grid">
                        <span class="aporte-etiqueta">DEBITAR : Bs.</span>
                        <input type="number" class="aporte-input mono" min="0" step="0.01" value="0.00"
                               id="debitar-monto-<?= $uuidJs ?>" oninput="onDebitarInput('<?= $uuidJs ?>')"
                               <?= $atmBloqueo !== null ? 'disabled' : '' ?>>
                        <span class="aporte-etiqueta">APORTE : Bs.</span>
                        <input type="number" class="aporte-input mono" min="0" step="0.01" value="0.00"
                               id="aporte-monto-<?= $uuidJs ?>"
                               oninput="this.dataset.editado = '1'; recalcDevolucion('<?= $uuidJs ?>')"
                               onkeydown="tabAporteAlAtm(event, '<?= $uuidJs ?>')"
                               <?= $atmBloqueo !== null ? 'disabled' : '' ?>>
                    <?php if ($atmBloqueo !== null): ?>
                        <button class="btn-accion" disabled title="<?= esc($atmBloqueo) ?>">🏧</button>
                    <?php else: ?>
                        <button class="btn-accion" id="atm-btn-<?= $uuidJs ?>"
                                title="Debitar (Transferencia a Terceros a la cuenta oficina)"
                                onclick="ejecutarAporte('<?= $uuidJs ?>')">🏧</button>
                    <?php endif; ?>
                        <span class="aporte-etiqueta">DEVOLUCION : Bs.</span>
                        <span class="aporte-calc mono" id="devolucion-calc-<?= $uuidJs ?>">0.00</span>
                    </div>
                    <div class="aporte-resultado" id="aporte-resultado-<?= $uuidJs ?>"></div>
                <?php endif; ?>
                </td>

                <!-- Acción -->
                <td>
                <?php if (!$registrado): ?>
                    <!-- ➕ No registrado: abrir modal de registro -->
                    <button
                        class="btn-accion"
                        title="Registrar dispositivo"
                        onclick="abrirModalRegistrar(
                            '<?= $serial ?>',
                            '<?= addslashes($dispLabel) ?>',
                            '<?= addslashes((string)($r['ag_agentid'] ?? '')) ?>'
                        )"
                    >➕</button>
                <?php else: ?>
                    <div style="display:inline-flex;align-items:center;gap:2px">
                        <!-- 📲 Registro/expediente del cliente — izquierda -->
                        <button
                            class="btn-accion"
                            title="Registro del cliente"
                            onclick="abrirModalRegistro(<?= htmlspecialchars(json_encode([
                                'uuid'           => $clienteUuid,
                                'nombre'         => $r['cl_nombre']         ?? '',
                                'ci'             => $r['cl_ci']             ?? '',
                                'celular'        => $r['cl_celular']        ?? '',
                                'fijo'           => $r['cl_fijo']           ?? '',
                                'vtotarjeta'     => $r['cl_vtotarjeta']     ?? '',
                                'codigo'         => $r['cl_codigo']         ?? '',
                                'sector'         => $r['cl_sector']         ?? '',
                                'activo'         => !empty($r['cl_activo']),
                                'garantenombre'  => $r['cl_garantenombre']  ?? '',
                                'garantecelular' => $r['cl_garantecelular'] ?? '',
                                'garante2nombre'  => $r['cl_garante2nombre']  ?? '',
                                'garante2celular' => $r['cl_garante2celular'] ?? '',
                                'observaciones'  => $r['cl_observaciones']  ?? '',
                                'fecharegistro'  => $clFecha,
                                'serial'         => $r['ev_serial']         ?? '',
                                'dispositivo'    => trim(($r['ev_vendor'] ?? '') . ' ' . ($r['ev_product'] ?? '')),
                            ]), ENT_QUOTES, 'UTF-8') ?>)"
                        >📲</button>
                        <span style="color:#3a4060;margin:0 2px">|</span>
                        <!-- 📝 Ver / Editar cliente — derecha -->
                        <button
                            class="btn-accion"
                            title="Ver / Editar cliente"
                            onclick="abrirModalEditar(
                                '<?= $clienteUuid ?>',
                                '<?= $serial ?>',
                                '<?= addslashes($dispLabel) ?>',
                                '<?= $clNombre ?>',
                                '<?= $clCi ?>',
                                '<?= $clCelular ?>',
                                '<?= $clFijo ?>',
                                '<?= $clVtoTarjeta ?>',
                                '<?= $clCodigo ?>',
                                '<?= $clSector ?>',
                                '<?= $clActivo ?>',
                                '<?= addslashes($clGaranteNombre) ?>',
                                '<?= $clGaranteCel ?>',
                                '<?= addslashes($clGarante2Nombre) ?>',
                                '<?= $clGarante2Cel ?>',
                                '<?= addslashes($clObservaciones) ?>',
                                '<?= $clFecha ?>'
                            )"
                        >✏️</button>
                        <span style="color:#3a4060;margin:0 2px">|</span>
                        <!-- 💰 Consulta de Saldo -->
                        <button
                            class="btn-accion"
                            title="Consulta de Saldo"
                            onclick="consultaSaldo('<?= $clienteUuid ?>')"
                        ><img src="/gyrosfe/assets/img/cta_cliente.svg" style="width:1.3rem;height:1.3rem;vertical-align:middle"></button>
                    </div>
                <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>


    <!-- ════════════════════════════════════════════════════════════
         MODAL ➕  Registrar dispositivo con cliente nuevo
    ════════════════════════════════════════════════════════════ -->
    <div id="modal-devoluciones" class="modal-overlay" role="dialog" aria-modal="true">
        <div class="modal-box">
            <button class="modal-close" onclick="cerrarModal('modal-devoluciones')" title="Cerrar">✕</button>
            <h2 id="devol-modal-titulo">Devoluciones</h2>
            <div id="devol-modal-contenido"></div>
        </div>
    </div>

<?php if ($puedeVerEstadisticas): ?>
    <div id="modal-estadisticas" class="modal-overlay" role="dialog" aria-modal="true">
        <div class="modal-box">
            <button class="modal-close" onclick="cerrarModal('modal-estadisticas')" title="Cerrar">✕</button>
            <h2>📊 Estadísticas generales</h2>
            <div class="estad-filtros">
                <div><label for="estad-desde">Desde</label><input type="date" id="estad-desde"></div>
                <div><label for="estad-hasta">Hasta</label><input type="date" id="estad-hasta"></div>
                <div>
                    <label for="estad-agrupar">Agrupar por</label>
                    <select id="estad-agrupar">
                        <option value="dia">Día</option>
                        <option value="mes">Mes</option>
                        <option value="anio">Año</option>
                    </select>
                </div>
                <div class="estad-atajos">
                    <button type="button" class="btn-cancel" onclick="estadAtajo('hoy')">Hoy</button>
                    <button type="button" class="btn-cancel" onclick="estadAtajo('mes')">Este mes</button>
                    <button type="button" class="btn-cancel" onclick="estadAtajo('anio')">Este año</button>
                </div>
                <button type="button" class="btn-primary" id="estad-btn-consultar" onclick="cargarEstadisticas()">Consultar</button>
            </div>
            <div id="estad-contenido"></div>
        </div>
    </div>
<?php endif; ?>

    <div id="modal-registrar" class="modal-overlay" role="dialog" aria-modal="true">
        <div class="modal-box">
            <button class="modal-close" onclick="cerrarModal('modal-registrar')" title="Cerrar">✕</button>
            <h2>➕ Registrar Dispositivo</h2>

            <div class="device-info-bar" id="reg-device-info"></div>

            <form class="modal-form" id="form-registrar" onsubmit="submitRegistrar(event)">
                <input type="hidden" id="reg-serial" name="serial">
                <input type="hidden" id="reg-agentId" name="agentId">

                <label>Nombre Completo *</label>
                <input type="text" id="reg-nombre" name="nombrecompleto" required maxlength="255" placeholder="Ej: Juan Pérez">

                <div class="form-row col3">
                    <div>
                        <label>CI / Cédula *</label>
                        <input type="text" id="reg-ci" name="ci" required maxlength="50" placeholder="Ej: 3456289">
                    </div>
                    <div>
                        <label>Celular *</label>
                        <input type="text" id="reg-celular" name="numerocelular" required maxlength="20" placeholder="Ej: 69123456">
                    </div>
                    <div>
                        <label>Fijo</label>
                        <input type="text" id="reg-fijo" name="numerofijo" maxlength="20" placeholder="Ej: 2123456">
                    </div>
                </div>

                <div class="form-row col3">
                    <div>
                        <label>Vto. Tarjeta *</label>
                        <input type="text" id="reg-vtotarjeta" name="vtotarjeta" required maxlength="10" placeholder="MM/AAAA">
                    </div>
                    <div>
                        <label>Sector(Grupo) *</label>
                        <select id="reg-sector" name="sector" required>
                            <option value="">Seleccionar...</option>
                            <option value="Magisterio">Magisterio</option>
                            <option value="Salud">Salud</option>
                        </select>
                    </div>
                    <div>
                        <label>Código *</label>
                        <input type="text" id="reg-codigo" name="codigo" required maxlength="50" placeholder="Ej: COD-001">
                    </div>
                </div>

                <div class="form-section">GARANTE 1</div>

                <div class="form-row col2">
                    <div>
                        <label>Nombre Completo</label>
                        <input type="text" id="reg-garantenombre" name="garantenombre" maxlength="255" placeholder="Ej: María López">
                    </div>
                    <div>
                        <label>Celular</label>
                        <input type="text" id="reg-garantecelular" name="garantecelular" maxlength="20" placeholder="Ej: 69987654">
                    </div>
                </div>

                <div class="form-section">GARANTE 2</div>

                <div class="form-row col2">
                    <div>
                        <label>Nombre Completo</label>
                        <input type="text" id="reg-garante2nombre" name="garante2nombre" maxlength="255" placeholder="Ej: Juan Pérez">
                    </div>
                    <div>
                        <label>Celular</label>
                        <input type="text" id="reg-garante2celular" name="garante2celular" maxlength="20" placeholder="Ej: 69987654">
                    </div>
                </div>

                <label>Observaciones</label>
                <textarea id="reg-observaciones" name="observaciones" maxlength="1000" rows="3"
                    style="width:100%;box-sizing:border-box;padding:7px 10px;border-radius:6px;border:1px solid #33374d;background:#131624;color:#e0e4f0;font-size:.9rem;resize:vertical;"
                    placeholder="Notas adicionales..."></textarea>

                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="cerrarModal('modal-registrar')">Cancelar</button>
                    <button type="submit" class="btn-primary" id="btn-reg-submit">Registrar</button>
                </div>
            </form>
        </div>
    </div>


    <!-- ════════════════════════════════════════════════════════════
         MODAL 📝  Ver / Editar cliente registrado
    ════════════════════════════════════════════════════════════ -->
    <div id="modal-editar" class="modal-overlay" role="dialog" aria-modal="true">
        <div class="modal-box">
            <button class="modal-close" onclick="cerrarModal('modal-editar')" title="Cerrar">✕</button>
            <h2>📝 Información del Cliente</h2>

            <div class="device-info-bar" id="edit-device-info"></div>

            <form class="modal-form" id="form-editar" onsubmit="submitEditar(event)">
                <input type="hidden" id="edit-uuid" name="uuid">
                <input type="hidden" id="edit-serial" name="serial">

                <label>Nombre Completo *</label>
                <input type="text" id="edit-nombre" name="nombrecompleto" required maxlength="255">

                <div class="form-row col3">
                    <div>
                        <label>CI / Cédula *</label>
                        <input type="text" id="edit-ci" name="ci" required maxlength="50">
                    </div>
                    <div>
                        <label>Celular *</label>
                        <input type="text" id="edit-celular" name="numerocelular" required maxlength="20">
                    </div>
                    <div>
                        <label>Fijo</label>
                        <input type="text" id="edit-fijo" name="numerofijo" maxlength="20">
                    </div>
                </div>

                <div class="form-row col3">
                    <div>
                        <label>Vto. Tarjeta *</label>
                        <input type="text" id="edit-vtotarjeta" name="vtotarjeta" required maxlength="10">
                    </div>
                    <div>
                        <label>Sector(Grupo) *</label>
                        <select id="edit-sector" name="sector" required>
                            <option value="">Seleccionar...</option>
                            <option value="Magisterio">Magisterio</option>
                            <option value="Salud">Salud</option>
                        </select>
                    </div>
                    <div>
                        <label>Código *</label>
                        <input type="text" id="edit-codigo" name="codigo" required maxlength="50">
                    </div>
                </div>

                <div class="form-row col2">
                    <div>
                        <label>Activo</label>
                        <select id="edit-activo" name="isActive">
                            <option value="1">Sí</option>
                            <option value="0">No</option>
                        </select>
                    </div>
                    <div>
                        <label>Fecha Registro</label>
                        <input type="text" id="edit-fecharegistro" readonly style="opacity:.6;cursor:not-allowed;">
                    </div>
                </div>

                <div class="form-section">GARANTE 1</div>

                <div class="form-row col2">
                    <div>
                        <label>Nombre Completo</label>
                        <input type="text" id="edit-garantenombre" name="garantenombre" maxlength="255">
                    </div>
                    <div>
                        <label>Celular</label>
                        <input type="text" id="edit-garantecelular" name="garantecelular" maxlength="20">
                    </div>
                </div>

                <div class="form-section">GARANTE 2</div>

                <div class="form-row col2">
                    <div>
                        <label>Nombre Completo</label>
                        <input type="text" id="edit-garante2nombre" name="garante2nombre" maxlength="255">
                    </div>
                    <div>
                        <label>Celular</label>
                        <input type="text" id="edit-garante2celular" name="garante2celular" maxlength="20">
                    </div>
                </div>

                <label>Observaciones</label>
                <textarea id="edit-observaciones" name="observaciones" maxlength="1000" rows="3"
                    style="width:100%;box-sizing:border-box;padding:7px 10px;border-radius:6px;border:1px solid #33374d;background:#131624;color:#e0e4f0;font-size:.9rem;resize:vertical;"></textarea>

                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="cerrarModal('modal-editar')">Cancelar</button>
                    <button type="submit" class="btn-primary" id="btn-edit-submit">Guardar cambios</button>
                </div>
            </form>
        </div>
    </div>


    <!-- ════════════════════════════════════════════════════════════
         MODAL 📲  Registro / Expediente del cliente  (tab menu)
    ════════════════════════════════════════════════════════════ -->
    <div id="modal-registro" class="modal-overlay" role="dialog" aria-modal="true">
        <div class="modal-box">
            <button class="modal-close" onclick="cerrarModal('modal-registro')" title="Cerrar">✕</button>

            <!-- Cabecera con título dinámico + tabs nav -->
            <div class="registro-header">
                <h2>Registro Cliente &mdash; <span id="reg-tab-nombre"></span></h2>
                <ul class="tab-nav" id="reg-tabs">
                    <li class="active" data-tab="tab-general">GENERAL</li>
                    <li data-tab="tab-prestamos">PRESTAMOS</li>
                    <li data-tab="tab-pagos" class="disabled" title="Deshabilitado">PAGOS</li>
                    <li data-tab="tab-transferencias">TRANSFERENCIAS</li>
                    <li data-tab="tab-banco">BANCO</li>
                </ul>
            </div>

            <!-- Cuerpo con panels -->
            <div class="tab-body">

                <!-- ── GENERAL ──────────────────────────────────── -->
                <div id="tab-general" class="tab-panel active">

                    <!-- Dispositivo -->
                    <div class="gen-section">Dispositivo</div>
                    <div class="gen-grid col3">
                        <div class="gen-cell">
                            <label>Serial</label>
                            <p id="gen-serial"></p>
                        </div>
                        <div class="gen-cell" style="grid-column:span 2">
                            <label>Dispositivo</label>
                            <p id="gen-dispositivo"></p>
                        </div>
                    </div>

                    <!-- Datos personales -->
                    <div class="gen-section">Datos Personales</div>
                    <div class="gen-grid">
                        <div class="gen-cell" style="grid-column:span 2">
                            <label>Nombre Completo</label>
                            <p id="gen-nombre"></p>
                        </div>
                    </div>
                    <div class="gen-grid col3" style="margin-top:10px">
                        <div class="gen-cell">
                            <label>CI / Cédula</label>
                            <p id="gen-ci"></p>
                        </div>
                        <div class="gen-cell">
                            <label>Celular</label>
                            <p id="gen-celular"></p>
                        </div>
                        <div class="gen-cell">
                            <label>Teléfono Fijo</label>
                            <p id="gen-fijo"></p>
                        </div>
                    </div>
                    <div class="gen-grid col3" style="margin-top:10px">
                        <div class="gen-cell">
                            <label>Sector / Grupo</label>
                            <p id="gen-sector"></p>
                        </div>
                        <div class="gen-cell">
                            <label>Código</label>
                            <p id="gen-codigo"></p>
                        </div>
                        <div class="gen-cell">
                            <label>Vto. Tarjeta</label>
                            <p id="gen-vtotarjeta"></p>
                        </div>
                    </div>
                    <div class="gen-grid" style="margin-top:10px">
                        <div class="gen-cell">
                            <label>Estado del Cliente</label>
                            <div class="toggle-wrap">
                                <label class="toggle-switch">
                                    <input type="checkbox" id="gen-activo-chk" onchange="toggleActivo(this)">
                                    <span class="toggle-track"></span>
                                </label>
                                <span class="toggle-label" id="gen-activo-label">—</span>
                                <span class="toggle-saving" id="gen-activo-saving">Guardando…</span>
                            </div>
                        </div>
                        <div class="gen-cell">
                            <label>Fecha de Registro</label>
                            <p id="gen-fecha"></p>
                        </div>
                    </div>

                    <!-- Garantes -->
                    <div class="gen-section">Garante 1</div>
                    <div class="gen-grid">
                        <div class="gen-cell">
                            <label>Nombre Completo</label>
                            <p id="gen-garantenombre"></p>
                        </div>
                        <div class="gen-cell">
                            <label>Celular</label>
                            <p id="gen-garantecelular"></p>
                        </div>
                    </div>
                    <div class="gen-section">Garante 2</div>
                    <div class="gen-grid">
                        <div class="gen-cell">
                            <label>Nombre Completo</label>
                            <p id="gen-garante2nombre"></p>
                        </div>
                        <div class="gen-cell">
                            <label>Celular</label>
                            <p id="gen-garante2celular"></p>
                        </div>
                    </div>

                    <!-- Observaciones -->
                    <div class="gen-section">Observaciones</div>
                    <div class="gen-cell">
                        <p id="gen-observaciones" style="white-space:pre-wrap"></p>
                    </div>

                </div><!-- /tab-general -->

                <!-- ── PRESTAMOS ─────────────────────────────────── -->
                <div id="tab-prestamos" class="tab-panel">
                    <div id="prest-loading" class="tab-placeholder" style="display:none">
                        <span>⏳</span>Cargando préstamos…
                    </div>
                    <div id="prest-content"></div>
                </div>

                <!-- ── PAGOS ─────────────────────────────────────── -->
                <div id="tab-pagos" class="tab-panel">
                    <div id="pagos-loading" class="tab-placeholder" style="display:none">
                        <span>⏳</span>Cargando pagos…
                    </div>
                    <div id="pagos-content"></div>
                </div>

                <!-- ── TRANSFERENCIAS (registro del ATM) ─────────── -->
                <div id="tab-transferencias" class="tab-panel">
                    <div id="transf-loading" class="tab-placeholder" style="display:none">
                        <span>⏳</span>Cargando transferencias…
                    </div>
                    <div id="transf-content"></div>
                </div>

                <!-- ── BANCO ─────────────────────────────────────── -->
                <div id="tab-banco" class="tab-panel">
                    <div class="banco-grid">

                        <?php foreach ([1, 2] as $n): ?>
                        <!-- Cuenta <?= $n ?> -->
                        <div class="banco-card" id="bc<?= $n ?>-card">
                            <!-- Cabecera -->
                            <div class="banco-card-header">
                                <div class="banco-logo-wrap" id="bc<?= $n ?>-logo-wrap" style="display:none">
                                    <img id="bc<?= $n ?>-logo-img" src="" alt="logo">
                                </div>
                                <div class="banco-logo-placeholder" id="bc<?= $n ?>-logo-ph">🏦</div>
                                <span class="banco-card-title" style="flex:1">Cuenta <?= $n ?></span>
                                <!-- Botones modo VISTA -->
                                <div class="bc-btn-view" id="bc<?= $n ?>-btns-view">
                                    <button class="btn-accion" title="Editar cuenta" onclick="bcModoEditar(<?= $n ?>)">✏️</button>
                                </div>
                                <!-- Botones modo EDICIÓN -->
                                <div class="bc-btn-edit" id="bc<?= $n ?>-btns-edit" style="display:none;gap:6px">
                                    <button class="btn-primary" id="bc<?= $n ?>-btn-save" style="padding:3px 10px;font-size:.78rem" onclick="bcGuardarCuenta(<?= $n ?>)">💾 Guardar</button>
                                    <button class="btn-accion" title="Cancelar" onclick="bcModoVista(<?= $n ?>)">✕</button>
                                </div>
                            </div>

                            <!-- MODO VISTA -->
                            <div class="bc-view" id="bc<?= $n ?>-view">
                                <div class="bc-view-grid">
                                    <div class="bc-view-row"><span class="bc-lbl">N° Cuenta</span><span class="bc-val" id="bc<?= $n ?>-v-nocta">—</span></div>
                                    <div class="bc-view-row"><span class="bc-lbl">Titular</span><span class="bc-val" id="bc<?= $n ?>-v-nombre">—</span></div>
                                    <div class="bc-view-row"><span class="bc-lbl">Moneda</span><span class="bc-val" id="bc<?= $n ?>-v-moneda">—</span></div>
                                    <div class="bc-view-row"><span class="bc-lbl">Nickname</span><span class="bc-val" id="bc<?= $n ?>-v-nickname">—</span></div>
                                    <div class="bc-view-row"><span class="bc-lbl">Usuario</span><span class="bc-val" id="bc<?= $n ?>-v-usuario">—</span></div>
                                    <div class="bc-view-row"><span class="bc-lbl">Key</span><span class="bc-val" id="bc<?= $n ?>-v-key">—</span></div>
                                    <div class="bc-view-row full"><span class="bc-lbl">Nota</span><span class="bc-val" id="bc<?= $n ?>-v-nota">—</span></div>
                                </div>
                            </div>

                            <!-- MODO EDICIÓN -->
                            <div class="bc-edit" id="bc<?= $n ?>-edit" style="display:none">
                                <div class="banco-card-body">
                                    <div class="banco-field full">
                                        <label>Banco</label>
                                        <select id="bc<?= $n ?>-banco" onchange="bcLogoUpdate(<?= $n ?>)">
                                            <option value="">— Seleccionar banco —</option>
                                            <option value="Banco Union">Banco Unión</option>
                                            <option value="Banco Nacional de Bolivia">Banco Nacional de Bolivia</option>
                                            <option value="Banco Mercantil Santa Cruz">Banco Mercantil Santa Cruz</option>
                                            <option value="Banco Economico">Banco Económico</option>
                                        </select>
                                    </div>
                                    <div class="banco-field full">
                                        <label>N° Cuenta</label>
                                        <input type="text" id="bc<?= $n ?>-nocta" placeholder="Número de cuenta">
                                    </div>
                                    <div class="banco-field full">
                                        <label>Nombre titular</label>
                                        <input type="text" id="bc<?= $n ?>-nombre" placeholder="Nombre completo">
                                    </div>
                                    <div class="banco-field">
                                        <label>Moneda</label>
                                        <select id="bc<?= $n ?>-moneda">
                                            <option value="BOB">BOB — Bolivianos</option>
                                            <option value="USD">USD — Dólares</option>
                                        </select>
                                    </div>
                                    <div class="banco-field">
                                        <label>Nickname</label>
                                        <select id="bc<?= $n ?>-nickname">
                                            <option value="">— Seleccionar —</option>
                                            <option value="pago">Pago</option>
                                            <option value="devolución">Devolución</option>
                                        </select>
                                    </div>
                                    <div class="banco-field">
                                        <label>Usuario</label>
                                        <input type="text" id="bc<?= $n ?>-usuario" placeholder="Usuario banca en línea">
                                    </div>
                                    <div class="banco-field">
                                        <label>Key / Contraseña</label>
                                        <input type="text" id="bc<?= $n ?>-key" placeholder="Contraseña">
                                    </div>
                                    <div class="banco-field full">
                                        <label>Nota</label>
                                        <textarea id="bc<?= $n ?>-nota" placeholder="Observaciones..."></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>

                    </div><!-- /banco-grid -->
                </div>

            </div><!-- /tab-body -->
        </div>
    </div>


    <!-- ════════════════════════════════════════════════════════════
         MODAL ➕  Nuevo Préstamo
    ════════════════════════════════════════════════════════════ -->
    <div id="modal-nuevo-prest" class="modal-float" role="dialog" aria-modal="true">
        <div class="modal-float-box">
            <button class="mf-close" onclick="cerrarModalFloat('modal-nuevo-prest')">✕</button>
            <h3>➕ Préstamo</h3>

            <ul class="tab-nav" id="prest-modo-tabs" style="margin-bottom:16px">
                <li class="active" data-modo="nuevo">NUEVO</li>
                <li data-modo="migrar">MIGRAR EXISTENTE</li>
            </ul>

            <label class="mf-label">Fecha de Préstamo *</label>
            <input type="date" id="np-fecha" class="mf-input" required>

            <div class="form-row col2">
                <div>
                    <label class="mf-label">Monto Prestado (Bs.) *</label>
                    <input type="number" id="np-monto" class="mf-input" min="0.01" step="0.01" placeholder="0.00" required oninput="recalcCuota()">
                </div>
                <div>
                    <label class="mf-label">Tasa de Interés Mensual (%) *</label>
                    <input type="number" id="np-tasa" class="mf-input" min="0" step="0.01" placeholder="0.00" oninput="recalcCuota()">
                </div>
            </div>
            <label class="mf-label">Meses (plazo total) *</label>
            <input type="number" id="np-meses" class="mf-input" min="1" step="1" placeholder="12" oninput="recalcCuota()">

            <!-- Campos solo para modo MIGRAR EXISTENTE -->
            <div id="prest-migrar-fields" style="display:none">
                <div class="form-row col2">
                    <div>
                        <label class="mf-label">N° de Cuota Actual *</label>
                        <input type="number" id="np-cuota-actual" class="mf-input" min="1" step="1" placeholder="Ej: 3">
                    </div>
                    <div>
                        <label class="mf-label">Saldo Pendiente Actual (Bs.) *</label>
                        <input type="number" id="np-saldo-pendiente" class="mf-input" min="0" step="0.01" placeholder="0.00">
                    </div>
                </div>
                <div class="mf-note">El plan se generará solo desde la cuota actual en adelante; las cuotas anteriores no se registran.</div>
            </div>

            <label class="mf-label">Cuota Mensual (Bs.) — calculado automáticamente</label>
            <input type="text" id="np-cuota" class="mf-input" readonly style="opacity:.6;cursor:not-allowed;">
            <div class="mf-note" id="np-totales"></div>
            <div class="mf-actions">
                <button class="btn-cancel" onclick="cerrarModalFloat('modal-nuevo-prest')">Cancelar</button>
                <button class="btn-primary" id="btn-crear-prest" onclick="crearPrestamo()">Crear Préstamo</button>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════
         MODAL ✏️  Editar Préstamo
    ════════════════════════════════════════════════════════════ -->
    <div id="modal-editar-prest" class="modal-float" role="dialog" aria-modal="true">
        <div class="modal-float-box">
            <button class="mf-close" onclick="cerrarModalFloat('modal-editar-prest')">✕</button>
            <h3>✏️ Editar Préstamo</h3>
            <input type="hidden" id="ep-id">
            <label class="mf-label">Monto Prestado (Bs.) *</label>
            <input type="number" id="ep-monto" class="mf-input" min="0.01" step="0.01" oninput="recalcCuotaEdit()">
            <label class="mf-label">Tasa de Interés Mensual (%) *</label>
            <input type="number" id="ep-tasa" class="mf-input" min="0" step="0.01" oninput="recalcCuotaEdit()">
            <label class="mf-label">Meses *</label>
            <input type="number" id="ep-meses" class="mf-input" min="1" step="1" oninput="recalcCuotaEdit()">
            <label class="mf-label">Cuota Mensual (Bs.) — calculado automáticamente</label>
            <input type="text" id="ep-cuota" class="mf-input" readonly style="opacity:.6;cursor:not-allowed;">
            <div class="mf-note" id="ep-totales"></div>
            <div class="mf-note" style="color:#f87171;margin-top:6px">⚠️ Se regenerarán las cuotas pendientes.</div>
            <div class="mf-actions">
                <button class="btn-cancel" onclick="cerrarModalFloat('modal-editar-prest')">Cancelar</button>
                <button class="btn-primary" id="btn-guardar-prest" onclick="guardarPrestamo()">Guardar Cambios</button>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════
         MODAL 💸  Registrar Pago
    ════════════════════════════════════════════════════════════ -->
    <div id="modal-reg-pago" class="modal-float" role="dialog" aria-modal="true">
        <div class="modal-float-box">
            <button class="mf-close" onclick="cerrarModalFloat('modal-reg-pago')">✕</button>
            <h3>💸 Registrar Pago</h3>
            <input type="hidden" id="rp-id">
            <label class="mf-label">Fecha de Pago *</label>
            <input type="date" id="rp-fecha" class="mf-input">
            <label class="mf-label">Monto Pagado (Bs.) *</label>
            <input type="number" id="rp-monto" class="mf-input" min="0.01" step="0.01">
            <label class="mf-label">Monto Transferencia (Bs.)</label>
            <input type="number" id="rp-transf" class="mf-input" min="0" step="0.01">
            <label class="mf-label">Método de Pago</label>
            <select id="rp-metodo" class="mf-input">
                <option value="">— Seleccionar —</option>
                <option value="Transferencia">Transferencia</option>
                <option value="Efectivo">Efectivo</option>
                <option value="QR">QR</option>
                <option value="Débito">Débito</option>
            </select>
            <label class="mf-label">Estado</label>
            <select id="rp-estado" class="mf-input">
                <option value="pagado">Pagado</option>
                <option value="parcial">Parcial</option>
                <option value="vencido">Vencido</option>
                <option value="pendiente">Pendiente</option>
            </select>
            <div class="mf-actions">
                <button class="btn-cancel" onclick="cerrarModalFloat('modal-reg-pago')">Cancelar</button>
                <button class="btn-primary" id="btn-guardar-pago" onclick="guardarPago()">Guardar Pago</button>
            </div>
        </div>
    </div>

    <!-- Detalle de la devolución de una transferencia (pestaña TRANSFERENCIAS) -->
    <div id="modal-devolucion-detalle" class="modal-float" role="dialog" aria-modal="true">
        <div class="modal-float-box" style="max-width:1100px;width:95%">
            <button class="mf-close" onclick="cerrarModalFloat('modal-devolucion-detalle')">✕</button>
            <h3 id="dd-titulo">↩️ Devolución</h3>
            <div id="dd-tabla"></div>
            <input type="hidden" id="dd-uuid">
            <div class="form-row col3" id="dd-form">
                <div>
                    <label class="mf-label">Modo de devolución</label>
                    <select id="dd-modo" class="mf-input">
                        <option value="Efectivo">Efectivo</option>
                        <option value="Transferencia">Transferencia</option>
                        <option value="QR">QR</option>
                    </select>
                </div>
                <div>
                    <label class="mf-label">Referencia</label>
                    <input type="text" id="dd-referencia" class="mf-input" maxlength="100" placeholder="N° de comprobante o recibo">
                </div>
                <div>
                    <label class="mf-label">Fecha de devolución</label>
                    <input type="date" id="dd-fecha" class="mf-input">
                </div>
            </div>
            <div class="mf-actions">
                <button class="btn-cancel" id="dd-btn-cancelar" onclick="cerrarModalFloat('modal-devolucion-detalle')">Cancelar</button>
                <button class="btn-primary" id="dd-btn-completar" onclick="completarDevolucion()">Completar devolución</button>
            </div>
        </div>
    </div>

    <!-- ── Scripts ───────────────────────────────────────────────── -->
    <script>
    // ── Filtro de búsqueda ───────────────────────────────────────
    function filterRows() {
        const q       = (document.getElementById('q')?.value ?? '').toLowerCase().trim();
        const sector  = document.getElementById('fil-sector').value;
        const estado  = document.getElementById('fil-estado').value;
        const rows    = document.querySelectorAll('#tbl tbody tr');
        rows.forEach(tr => {
            const txt        = tr.innerText.toLowerCase();
            const trSector   = tr.dataset.sector  ?? '';
            const trActivo   = tr.dataset.activo  ?? '';
            const okQ      = q      === '' || txt.includes(q);
            const okSector = sector === '' || trSector === sector;
            const okEstado = estado === '' || trActivo === estado;
            tr.style.display = (okQ && okSector && okEstado) ? '' : 'none';
        });
    }

    // ── Tab Banco ────────────────────────────────────────────────
    const BC_LOGOS = {
        'Banco Union':                'https://bancounion.com.bo/Imagenes/bancounionlarge_transac.png',
        'Banco Nacional de Bolivia':  'http://www.bnb.com.bo/PortalBNB/Images/PNG_BNB_Blanco.png',
        'Banco Mercantil Santa Cruz': 'https://images.seeklogo.com/logo-png/52/1/banco-mercantil-santa-cruz-logo-png_seeklogo-529700.png',
        'Banco Economico':            'https://www.baneco.com.bo/images/logo-baneco.webp',
    };

    // Datos en memoria para poder cancelar edición
    const _bcData = { 1: {}, 2: {} };

    function bcLogoUpdate(n) {
        const banco = document.getElementById(`bc${n}-banco`).value;
        const wrap  = document.getElementById(`bc${n}-logo-wrap`);
        const img   = document.getElementById(`bc${n}-logo-img`);
        const ph    = document.getElementById(`bc${n}-logo-ph`);
        if (banco && BC_LOGOS[banco]) {
            img.src = BC_LOGOS[banco];
            wrap.style.display = ''; ph.style.display = 'none';
        } else {
            wrap.style.display = 'none'; ph.style.display = '';
        }
    }

    function bcModoVista(n) {
        document.getElementById(`bc${n}-view`).style.display = '';
        document.getElementById(`bc${n}-edit`).style.display = 'none';
        document.getElementById(`bc${n}-btns-view`).style.display = '';
        document.getElementById(`bc${n}-btns-edit`).style.display = 'none';
        // Restaurar logo según banco guardado
        const saved = _bcData[n].banco ?? '';
        const wrap  = document.getElementById(`bc${n}-logo-wrap`);
        const img   = document.getElementById(`bc${n}-logo-img`);
        const ph    = document.getElementById(`bc${n}-logo-ph`);
        if (saved && BC_LOGOS[saved]) {
            img.src = BC_LOGOS[saved]; wrap.style.display = ''; ph.style.display = 'none';
        } else {
            wrap.style.display = 'none'; ph.style.display = '';
        }
    }

    function bcModoEditar(n) {
        // Poblar inputs con datos actuales
        const acc = _bcData[n];
        document.getElementById(`bc${n}-banco`).value    = acc.banco    ?? '';
        document.getElementById(`bc${n}-nocta`).value    = acc.nocta    ?? '';
        document.getElementById(`bc${n}-nombre`).value   = acc.nombre   ?? '';
        document.getElementById(`bc${n}-moneda`).value   = acc.moneda   ?? 'BOB';
        document.getElementById(`bc${n}-usuario`).value  = acc.usuario  ?? '';
        document.getElementById(`bc${n}-key`).value      = acc.key      ?? '';
        document.getElementById(`bc${n}-nickname`).value = acc.nickname ?? '';
        document.getElementById(`bc${n}-nota`).value     = acc.nota     ?? '';
        bcLogoUpdate(n);
        document.getElementById(`bc${n}-view`).style.display = 'none';
        document.getElementById(`bc${n}-edit`).style.display = '';
        document.getElementById(`bc${n}-btns-view`).style.display = 'none';
        document.getElementById(`bc${n}-btns-edit`).style.display = 'flex';
    }

    function bcActualizarVista(n, acc) {
        const v = k => acc[k] ?? '—';
        document.getElementById(`bc${n}-v-nocta`).textContent    = v('nocta')    || v('noCta');
        document.getElementById(`bc${n}-v-nombre`).textContent   = v('nombre');
        document.getElementById(`bc${n}-v-moneda`).textContent   = v('moneda');
        document.getElementById(`bc${n}-v-nickname`).textContent = v('nickname');
        document.getElementById(`bc${n}-v-usuario`).textContent  = v('usuario');
        document.getElementById(`bc${n}-v-key`).textContent      = v('key');
        document.getElementById(`bc${n}-v-nota`).textContent     = v('nota');
    }

    async function cargarBanco(uuid) {
        _bcData[1] = {}; _bcData[2] = {};
        bcActualizarVista(1, {}); bcActualizarVista(2, {});
        bcModoVista(1); bcModoVista(2);
        try {
            const res  = await fetch(`/gyrosfe/api/banco_listar.php?uuid=${encodeURIComponent(uuid)}`);
            const data = await res.json();
            if (!data.ok) return;
            [1, 2].forEach((n, i) => {
                if (data.data[i]) {
                    // normalizar clave noCta (PDO devuelve minúsculas)
                    const acc = data.data[i];
                    acc.nocta = acc.nocta ?? acc.noCta ?? '';
                    _bcData[n] = acc;
                    bcActualizarVista(n, acc);
                    bcModoVista(n);
                }
            });
        } catch (e) { console.error('cargarBanco', e); }
    }

    async function bcGuardarCuenta(n) {
        const btn = document.getElementById(`bc${n}-btn-save`);
        if (!btn) { console.error('bcGuardarCuenta: btn no encontrado', n); return; }
        btn.disabled = true; btn.textContent = '…';

        try {
            const g = id => { const el = document.getElementById(id); if (!el) throw new Error(`Elemento no encontrado: ${id}`); return el; };

            const acc = {
                banco:    g(`bc${n}-banco`).value.trim(),
                noCta:    g(`bc${n}-nocta`).value.trim(),
                nombre:   g(`bc${n}-nombre`).value.trim(),
                moneda:   g(`bc${n}-moneda`).value,
                usuario:  g(`bc${n}-usuario`).value.trim(),
                key:      g(`bc${n}-key`).value.trim(),
                nickname: g(`bc${n}-nickname`).value,
                nota:     g(`bc${n}-nota`).value.trim(),
                isActive: true,
            };

            const other    = n === 1 ? 2 : 1;
            const otherAcc = {
                banco:    _bcData[other].banco    ?? '',
                noCta:    _bcData[other].nocta    ?? _bcData[other].noCta ?? '',
                nombre:   _bcData[other].nombre   ?? '',
                moneda:   _bcData[other].moneda   ?? 'BOB',
                usuario:  _bcData[other].usuario  ?? '',
                key:      _bcData[other].key      ?? '',
                nickname: _bcData[other].nickname ?? '',
                nota:     _bcData[other].nota     ?? '',
                isActive: true,
            };

            const accounts = n === 1 ? [acc, otherAcc] : [otherAcc, acc];
            const fd = new FormData();
            fd.append('clienteUuid', _regUuid);
            fd.append('accounts', JSON.stringify(accounts));

            const res  = await fetch('/gyrosfe/api/banco_guardar.php', { method: 'POST', body: fd });
            const text = await res.text();
            let data;
            try { data = JSON.parse(text); } catch { throw new Error('Respuesta inválida: ' + text.substring(0, 200)); }

            if (data.ok) {
                acc.nocta = acc.noCta;
                _bcData[n] = acc;
                btn.disabled = false; btn.textContent = '💾 Guardar';
                bcActualizarVista(n, acc);
                bcModoVista(n);
            } else {
                alert('Error: ' + (data.error ?? 'desconocido'));
                btn.disabled = false; btn.textContent = '💾 Guardar';
            }
        } catch (e) {
            alert('Error: ' + e.message);
            btn.disabled = false; btn.textContent = '💾 Guardar';
        }
    }

    // ── Helpers de modal ─────────────────────────────────────────
    function abrirModal(id) {
        document.getElementById(id).classList.add('active');
    }
    function cerrarModal(id) {
        document.getElementById(id).classList.remove('active');
    }
    // Cerrar al hacer click en el overlay
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', e => {
            if (e.target === overlay) overlay.classList.remove('active');
        });
    });
    // Cerrar con Escape
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.active').forEach(m => m.classList.remove('active'));
        }
    });

    // Tabla de devoluciones: la usan el buscador de la barra y el detalle de la pestaña TRANSFERENCIAS
    function renderTablaDevoluciones(lista) {
        const columnas = [
            ['CÓDIGO',           d => d.codigo_cliente  ?? '—'],
            ['NOMBRE',           d => d.nombre_cliente  ?? '—'],
            ['CI',               d => d.ci_cliente      ?? '—'],
            ['MONTO',            d => fmtMoney(d.monto_a_devolver)],
            ['FECHA COBRO',      d => d.fecha_cobro_debito ? fmtDate(d.fecha_cobro_debito) : '—'],
            ['MODO',             d => d.modo_devolucion ?? '—'],
            ['REFERENCIA',       d => d.referencia || '—'],
            ['ESTADO',           d => d.estado          ?? '—'],
            ['FECHA DEVOLUCIÓN', d => d.fecha_devolucion ? fmtDate(d.fecha_devolucion) : '—'],
        ];
        const wrap  = document.createElement('div');
        wrap.className = 'pago-table-wrap';
        const tabla = document.createElement('table');
        tabla.className = 'pago-table';
        const trHead = tabla.createTHead().insertRow();
        columnas.forEach(([nombre]) => {
            const th = document.createElement('th');
            th.textContent = nombre;
            trHead.appendChild(th);
        });
        const tbody = tabla.createTBody();
        lista.forEach(d => {
            const tr = tbody.insertRow();
            columnas.forEach(([, valor]) => { tr.insertCell().textContent = valor(d); });
        });
        wrap.appendChild(tabla);
        return wrap;
    }

    // ── Buscador de devoluciones por CI o código ─────────────────
    async function buscarDevoluciones() {
        const input = document.getElementById('devol-ci');
        const btn   = document.getElementById('devol-btn');
        const q     = input.value.trim();
        if (q === '') {
            alert('Ingresa el CI o el código del cliente.');
            input.focus();
            return;
        }

        const titulo    = document.getElementById('devol-modal-titulo');
        const contenido = document.getElementById('devol-modal-contenido');
        btn.disabled = true;
        try {
            const res  = await fetch('/gyrosfe/api/devoluciones_buscar.php?q=' + encodeURIComponent(q));
            const data = await res.json();
            if (!data.ok) throw new Error(data.error ?? 'desconocido');

            titulo.textContent = `Devoluciones · ${q}`;
            contenido.replaceChildren();

            if (data.data.length === 0) {
                const p = document.createElement('p');
                p.style.color = '#9aa0b8';
                p.textContent = 'No existe ningún registro de devolución para este CI o código.';
                contenido.appendChild(p);
            } else {
                contenido.appendChild(renderTablaDevoluciones(data.data));
            }
            abrirModal('modal-devoluciones');
        } catch (err) {
            alert('Error al buscar devoluciones: ' + err.message);
        } finally {
            btn.disabled = false;
        }
    }

    // ── Estadísticas generales ──────────────────────────────────
    function fechaISO(d) {
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    }

    function estadAtajo(rango) {
        const hoy = new Date();
        const desde = rango === 'hoy' ? hoy
                    : rango === 'mes' ? new Date(hoy.getFullYear(), hoy.getMonth(), 1)
                    : new Date(hoy.getFullYear(), 0, 1);
        document.getElementById('estad-desde').value   = fechaISO(desde);
        document.getElementById('estad-hasta').value   = fechaISO(hoy);
        document.getElementById('estad-agrupar').value = rango === 'anio' ? 'mes' : 'dia';
        cargarEstadisticas();
    }

    function abrirEstadisticas() {
        abrirModal('modal-estadisticas');
        if (!document.getElementById('estad-desde').value) estadAtajo('mes');
    }

    function fmtPeriodo(periodo, agrupar) {
        const [y, m, d] = periodo.split('-');
        return agrupar === 'anio' ? y : agrupar === 'mes' ? `${m}/${y}` : `${d}/${m}/${y}`;
    }

    async function cargarEstadisticas() {
        const desde     = document.getElementById('estad-desde').value;
        const hasta     = document.getElementById('estad-hasta').value;
        const agrupar   = document.getElementById('estad-agrupar').value;
        const btn       = document.getElementById('estad-btn-consultar');
        const contenido = document.getElementById('estad-contenido');
        if (!desde || !hasta) { alert('Indica el rango de fechas.'); return; }

        btn.disabled = true;
        try {
            const qs   = new URLSearchParams({ desde, hasta, agrupar });
            const res  = await fetch('/gyrosfe/api/estadisticas.php?' + qs);
            const data = await res.json();
            if (!data.ok) throw new Error(data.error ?? 'desconocido');
            contenido.replaceChildren(renderTarjetasEstadisticas(data.totales), renderTablaEstadisticas(data));
        } catch (err) {
            alert('Error al cargar estadísticas: ' + err.message);
        } finally {
            btn.disabled = false;
        }
    }

    function renderTarjetasEstadisticas(t) {
        const tarjetas = [
            ['Débitos',             t.debitos_cant,       `${fmtMoney(t.debitos_monto)} transferido`],
            ['Pagos (aporte)',      fmtMoney(t.aporte),   'Débito − devolución'],
            ['Devoluciones',        t.devol_cant,         `${fmtMoney(t.devol_monto)} · pendientes ${t.devol_pend_cant} (${fmtMoney(t.devol_pend_monto)})`],
            ['Consultas de saldo',  t.consultas,          ''],
        ];
        const wrap = document.createElement('div');
        wrap.className = 'estad-tarjetas';
        tarjetas.forEach(([titulo, valor, sub]) => {
            const card = document.createElement('div');
            card.className = 'estad-tarjeta';
            [['t', titulo], ['v', valor], ['s', sub]].forEach(([cls, texto]) => {
                const el = document.createElement('div');
                el.className = cls;
                el.textContent = texto;
                card.appendChild(el);
            });
            wrap.appendChild(card);
        });
        return wrap;
    }

    function renderTablaEstadisticas(data) {
        const columnas = [
            ['PERÍODO',         s => fmtPeriodo(s.periodo, data.rango.agrupar)],
            ['N° DÉBITOS',      s => s.debitos_cant],
            ['MONTO DEBITADO',  s => fmtMoney(s.debitos_monto)],
            ['PAGOS (APORTE)',  s => fmtMoney(s.aporte)],
            ['N° DEVOLUCIONES', s => s.devol_cant],
            ['MONTO DEVOLUCIÓN', s => fmtMoney(s.devol_monto)],
            ['PENDIENTES',      s => s.devol_pend_cant],
            ['CONSULTAS SALDO', s => s.consultas],
        ];
        const wrap  = document.createElement('div');
        wrap.className = 'pago-table-wrap';
        if (data.series.length === 0) {
            const p = document.createElement('p');
            p.style.color = '#9aa0b8';
            p.textContent = 'No hay movimientos en el rango seleccionado.';
            wrap.appendChild(p);
            return wrap;
        }
        const tabla = document.createElement('table');
        tabla.className = 'pago-table';
        const trHead = tabla.createTHead().insertRow();
        columnas.forEach(([nombre]) => {
            const th = document.createElement('th');
            th.textContent = nombre;
            trHead.appendChild(th);
        });
        const tbody = tabla.createTBody();
        data.series.forEach(s => {
            const tr = tbody.insertRow();
            columnas.forEach(([, valor]) => { tr.insertCell().textContent = valor(s); });
        });
        const trTotal = tabla.createTFoot().insertRow();
        columnas.forEach(([, valor], i) => {
            const td = trTotal.insertCell();
            td.style.fontWeight = '700';
            td.textContent = i === 0 ? 'TOTAL' : valor(data.totales);
        });
        wrap.appendChild(tabla);
        return wrap;
    }

    // ── Modal ➕ Registrar ───────────────────────────────────────
    function abrirModalRegistrar(serial, dispositivo, agentId) {
        document.getElementById('reg-serial').value  = serial;
        document.getElementById('reg-agentId').value = agentId;
        document.getElementById('reg-device-info').innerHTML =
            `Serial: <span>${serial}</span> &nbsp;|&nbsp; Dispositivo: <span>${dispositivo}</span> &nbsp;|&nbsp; Agente: <span>${agentId}</span>`;
        document.getElementById('form-registrar').reset();
        // Restaurar serial e agentId que reset() limpió
        document.getElementById('reg-serial').value  = serial;
        document.getElementById('reg-agentId').value = agentId;
        abrirModal('modal-registrar');
    }

    async function submitRegistrar(e) {
        e.preventDefault();
        const btn = document.getElementById('btn-reg-submit');
        btn.disabled = true;
        btn.textContent = 'Guardando…';

        const data = new FormData(document.getElementById('form-registrar'));
        try {
            const res  = await fetch('/gyrosfe/api/cliente_registrar.php', { method: 'POST', body: data });
            const json = await res.json();
            if (json.ok) {
                cerrarModal('modal-registrar');
                location.reload();
            } else {
                alert('Error: ' + (json.error ?? 'No se pudo registrar'));
            }
        } catch (err) {
            alert('Error de red: ' + err.message);
        } finally {
            btn.disabled = false;
            btn.textContent = 'Registrar';
        }
    }

    // ── Modal 📝 Editar ─────────────────────────────────────────
    function abrirModalEditar(uuid, serial, dispositivo, nombre, ci, celular, fijo, vtotarjeta, codigo, sector, activo, garantenombre, garantecelular, garante2nombre, garante2celular, observaciones, fecharegistro) {
        document.getElementById('edit-uuid').value           = uuid;
        document.getElementById('edit-serial').value         = serial;
        document.getElementById('edit-nombre').value         = nombre;
        document.getElementById('edit-ci').value             = ci;
        document.getElementById('edit-celular').value        = celular;
        document.getElementById('edit-fijo').value           = fijo;
        document.getElementById('edit-vtotarjeta').value     = vtotarjeta;
        document.getElementById('edit-codigo').value         = codigo;
        document.getElementById('edit-sector').value         = sector;
        document.getElementById('edit-activo').value         = activo === 'Sí' ? '1' : '0';
        document.getElementById('edit-garantenombre').value  = garantenombre;
        document.getElementById('edit-garantecelular').value = garantecelular;
        document.getElementById('edit-garante2nombre').value  = garante2nombre;
        document.getElementById('edit-garante2celular').value = garante2celular;
        document.getElementById('edit-observaciones').value  = observaciones;
        document.getElementById('edit-fecharegistro').value  = fecharegistro;
        document.getElementById('edit-device-info').innerHTML =
            `Serial: <span>${serial}</span> &nbsp;|&nbsp; Dispositivo: <span>${dispositivo}</span>`;
        abrirModal('modal-editar');
    }

    async function submitEditar(e) {
        e.preventDefault();
        const btn = document.getElementById('btn-edit-submit');
        btn.disabled = true;
        btn.textContent = 'Guardando…';

        const data = new FormData(document.getElementById('form-editar'));
        try {
            const res  = await fetch('/gyrosfe/api/cliente_editar.php', { method: 'POST', body: data });
            const json = await res.json();
            if (json.ok) {
                cerrarModal('modal-editar');
                location.reload();
            } else {
                alert('Error: ' + (json.error ?? 'No se pudo actualizar'));
            }
        } catch (err) {
            alert('Error de red: ' + err.message);
        } finally {
            btn.disabled = false;
            btn.textContent = 'Guardar cambios';
        }
    }

    // ── Estado del modal Registro ────────────────────────────────
    let _regUuid   = '';   // UUID del cliente actualmente abierto
    let _regCodigo = '';   // Código del cliente

    // ── Modal 📲 Registro / Expediente del cliente ──────────────
    function txt(val) {
        const s = (val ?? '').toString().trim();
        return s !== '' ? s : null;
    }
    function setGen(id, val, emptyLabel) {
        const el = document.getElementById(id);
        if (!el) return;
        const v = txt(val);
        if (v) {
            el.textContent = v;
            el.classList.remove('empty');
        } else {
            el.textContent = emptyLabel ?? '—';
            el.classList.add('empty');
        }
    }

    function abrirModalRegistro(data) {
        // Guardar UUID y código del cliente actual
        _regUuid   = (data.uuid   ?? '').toString().trim();
        _regCodigo = (data.codigo ?? '').toString().trim();

        // Limpiar contenido de tabs dinámicos al abrir
        document.getElementById('prest-content').innerHTML = '';
        document.getElementById('pagos-content').innerHTML = '';
        document.getElementById('prest-loading').style.display = 'none';
        document.getElementById('pagos-loading').style.display = 'none';

        // Título de cabecera
        document.getElementById('reg-tab-nombre').textContent = txt(data.nombre) ?? '(sin nombre)';

        // Dispositivo
        setGen('gen-serial',      data.serial,      '—');
        setGen('gen-dispositivo', data.dispositivo, '—');

        // Datos personales
        setGen('gen-nombre',      data.nombre,      '—');
        setGen('gen-ci',          data.ci,          '—');
        setGen('gen-celular',     data.celular,      '—');
        setGen('gen-fijo',        data.fijo,         '—');
        setGen('gen-sector',      data.sector,       '—');
        setGen('gen-codigo',      data.codigo,       '—');
        setGen('gen-vtotarjeta',  data.vtotarjeta,   '—');
        setGen('gen-fecha',       data.fecharegistro,'—');

        // Toggle activo — guarda UUID para usarlo en toggleActivo()
        const chk = document.getElementById('gen-activo-chk');
        if (chk) {
            chk.checked = !!data.activo;
            chk.dataset.uuid = data.uuid;
            actualizarToggleLabel(!!data.activo);
        }

        // Garantes
        setGen('gen-garantenombre',   data.garantenombre,   '—');
        setGen('gen-garantecelular',  data.garantecelular,  '—');
        setGen('gen-garante2nombre',  data.garante2nombre,  '—');
        setGen('gen-garante2celular', data.garante2celular, '—');

        // Observaciones
        setGen('gen-observaciones', data.observaciones, 'Sin observaciones');

        // Activar primer tab
        activarTab('tab-general');
        abrirModal('modal-registro');
    }

    // ── Tab switching ────────────────────────────────────────────
    function activarTab(tabId) {
        if (document.querySelector(`#reg-tabs [data-tab="${tabId}"]`)?.classList.contains('disabled')) return;
        document.querySelectorAll('#modal-registro .tab-panel').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('#reg-tabs li').forEach(li => li.classList.remove('active'));
        const panel = document.getElementById(tabId);
        if (panel) panel.classList.add('active');
        const navItem = document.querySelector(`#reg-tabs [data-tab="${tabId}"]`);
        if (navItem) navItem.classList.add('active');

        // Cargar datos dinámicos según tab
        if (tabId === 'tab-prestamos' && _regUuid) cargarPrestamos(_regUuid);
        if (tabId === 'tab-pagos'     && _regUuid) cargarPagos(_regUuid);
        if (tabId === 'tab-transferencias' && _regUuid) cargarTransferencias(_regUuid);
        if (tabId === 'tab-banco'     && _regUuid) cargarBanco(_regUuid);
    }

    document.getElementById('reg-tabs').addEventListener('click', function(e) {
        const li = e.target.closest('li[data-tab]');
        if (!li || li.classList.contains('disabled')) return;
        activarTab(li.dataset.tab);
    });

    // ── Toggle activo/inactivo ───────────────────────────────────
    function actualizarToggleLabel(activo) {
        const lbl = document.getElementById('gen-activo-label');
        if (!lbl) return;
        if (activo) {
            lbl.textContent = 'Activo';
            lbl.className = 'toggle-label activo';
        } else {
            lbl.textContent = 'Inactivo';
            lbl.className = 'toggle-label inactivo';
        }
    }

    // ════════════════════════════════════════════════════════════════
    // PRÉSTAMOS
    // ════════════════════════════════════════════════════════════════

    // ── Helpers ─────────────────────────────────────────────────────
    function fmtMoney(v) {
        if (v === null || v === undefined || v === '') return '—';
        return 'Bs ' + parseFloat(v).toFixed(2);
    }
    function fmtDate(d) {
        if (!d) return '—';
        // d puede ser "YYYY-MM-DD" o ISO
        const s = d.toString().substring(0, 10);
        const [y, m, dd] = s.split('-');
        return `${dd}/${m}/${y}`;
    }
    function calcCuota(monto, tasa, meses) {
        if (!meses || !monto) return 0;
        const r = tasa / 100;
        if (r === 0) return monto / meses;
        const pot = Math.pow(1 + r, meses);
        return monto * (r * pot) / (pot - 1);
    }
    function calcTotales(monto, tasa, meses) {
        const cuota = calcCuota(monto, tasa, meses);
        let r = tasa / 100, saldo = monto, totalInt = 0;
        for (let i = 0; i < meses; i++) {
            const int = saldo * r;
            totalInt += int;
            saldo = Math.max(0, saldo - (cuota - int));
        }
        return { cuota, totalPagar: cuota * meses, totalInt };
    }

    // ── Helpers de modal flotante ────────────────────────────────────
    function abrirModalFloat(id) { document.getElementById(id).classList.add('active'); }
    function cerrarModalFloat(id) { document.getElementById(id).classList.remove('active'); }

    // Cerrar flotantes con overlay-click
    document.querySelectorAll('.modal-float').forEach(m => {
        m.addEventListener('click', e => { if (e.target === m) m.classList.remove('active'); });
    });

    // ── Recalcular cuota en tiempo real ──────────────────────────────
    function recalcCuota() {
        const monto = parseFloat(document.getElementById('np-monto').value) || 0;
        const tasa  = parseFloat(document.getElementById('np-tasa').value)  || 0;
        const meses = parseInt(document.getElementById('np-meses').value)   || 0;
        const t     = calcTotales(monto, tasa, meses);
        document.getElementById('np-cuota').value = t.cuota.toFixed(2);
        document.getElementById('np-totales').textContent =
            meses > 0 && monto > 0
            ? `Total a pagar: Bs ${t.totalPagar.toFixed(2)}  |  Total intereses: Bs ${t.totalInt.toFixed(2)}`
            : '';
    }
    function recalcCuotaEdit() {
        const monto = parseFloat(document.getElementById('ep-monto').value) || 0;
        const tasa  = parseFloat(document.getElementById('ep-tasa').value)  || 0;
        const meses = parseInt(document.getElementById('ep-meses').value)   || 0;
        const t     = calcTotales(monto, tasa, meses);
        document.getElementById('ep-cuota').value = t.cuota.toFixed(2);
        document.getElementById('ep-totales').textContent =
            meses > 0 && monto > 0
            ? `Total a pagar: Bs ${t.totalPagar.toFixed(2)}  |  Total intereses: Bs ${t.totalInt.toFixed(2)}`
            : '';
    }

    // ── Cargar y renderizar tab Préstamos ────────────────────────────
    async function cargarPrestamos(uuid) {
        const loading = document.getElementById('prest-loading');
        const content = document.getElementById('prest-content');
        content.innerHTML = '';
        loading.style.display = 'flex';
        try {
            const res  = await fetch(`/gyrosfe/api/prestamos_listar.php?uuid=${encodeURIComponent(uuid)}`);
            const json = await res.json();
            loading.style.display = 'none';
            if (!json.ok) { content.innerHTML = `<p style="color:#f87171;padding:20px">Error: ${json.error}</p>`; return; }
            content.innerHTML = renderPrestamosTabla(json.data);
            // Eventos
            content.querySelectorAll('.btn-edit-prest').forEach(btn => {
                btn.addEventListener('click', () => abrirEditarPrestamo(btn.dataset));
            });
            content.querySelectorAll('.btn-del-prest').forEach(btn => {
                btn.addEventListener('click', () => eliminarPrestamo(btn.dataset.id));
            });
        } catch(err) {
            loading.style.display = 'none';
            content.innerHTML = `<p style="color:#f87171;padding:20px">Error de red: ${err.message}</p>`;
        }
    }

    function renderPrestamosTabla(rows) {
        const hoy = new Date().toISOString().split('T')[0];
        const filas = rows.map((p, i) => {
            const cuota        = calcCuota(parseFloat(p.monto_prestado), parseFloat(p.tasa_interes), parseInt(p.plazo_meses));
            const fechaPrest   = (p.fecha_prestamo || '').toString().substring(0, 10);
            const btnEliminar  = fechaPrest === hoy
                ? `<button class="btn-accion btn-del-prest"
                        data-id="${p.id_prestamo}"
                        title="Eliminar préstamo">❌</button>`
                : '';
            return `<tr>
                <td>${_regCodigo || '—'}</td>
                <td>${fmtDate(p.fecha_prestamo)}</td>
                <td class="td-num">${fmtMoney(p.monto_prestado)}</td>
                <td class="td-num">Bs ${cuota.toFixed(2)}</td>
                <td style="text-align:center">${p.plazo_meses}</td>
                <td class="td-num">${fmtMoney(p.total_a_pagar)}</td>
                <td class="td-num">${fmtMoney(p.total_interes)}</td>
                <td style="text-align:center">
                    <button class="btn-accion btn-edit-prest"
                        data-id="${p.id_prestamo}"
                        data-monto="${p.monto_prestado}"
                        data-tasa="${p.tasa_interes}"
                        data-meses="${p.plazo_meses}"
                        data-fecha="${p.fecha_prestamo}"
                        title="Editar préstamo">✏️</button>
                    ${btnEliminar}
                </td>
            </tr>`;
        }).join('');

        const totalMonto = rows.reduce((sum, p) => sum + parseFloat(p.monto_prestado || 0), 0);

        const filaTotales = `<tr style="border-top:2px solid #2a2f45">
            <td colspan="2" style="text-align:right;font-size:.72rem;font-weight:700;letter-spacing:.06em;color:#7b93ff;text-transform:uppercase;padding-right:12px">Total Préstamos:</td>
            <td class="td-num" style="color:#e0e4f0;font-weight:700">Bs ${totalMonto.toFixed(2)}</td>
            <td colspan="5"></td>
        </tr>`;

        const filaAgregar = `<tr>
            <td colspan="7" class="td-add">Agregar nuevo Préstamo</td>
            <td style="text-align:center">
                <button class="btn-accion" onclick="abrirNuevoPrestamo()" title="Agregar nuevo préstamo">➕</button>
            </td>
        </tr>`;

        return `<div class="prest-table-wrap">
            <table class="prest-table">
                <thead><tr>
                    <th>Código Cliente</th>
                    <th>Fecha Préstamo</th>
                    <th class="th-num">Monto Prestado</th>
                    <th class="th-num">Cuota (Bs.)</th>
                    <th style="text-align:center">Meses</th>
                    <th class="th-num">Total Pagar</th>
                    <th class="th-num">Total Intereses</th>
                    <th style="text-align:center">Acciones</th>
                </tr></thead>
                <tbody>${filas}${rows.length > 0 ? filaTotales : ''}${filaAgregar}</tbody>
            </table>
        </div>`;
    }

    // ── Abrir modal Nuevo Préstamo ────────────────────────────────────
    // ── Modo del modal: 'nuevo' | 'migrar' ───────────────────────────
    let _prestModo = 'nuevo';

    function setPrestModo(modo) {
        _prestModo = modo;
        document.querySelectorAll('#prest-modo-tabs li').forEach(li => {
            li.classList.toggle('active', li.dataset.modo === modo);
        });
        document.getElementById('prest-migrar-fields').style.display = (modo === 'migrar') ? '' : 'none';
    }

    document.getElementById('prest-modo-tabs').addEventListener('click', function(e) {
        const li = e.target.closest('li[data-modo]');
        if (!li) return;
        setPrestModo(li.dataset.modo);
    });

    function abrirNuevoPrestamo() {
        const hoy = new Date().toISOString().split('T')[0];
        document.getElementById('np-fecha').value = hoy;
        document.getElementById('np-monto').value = '';
        document.getElementById('np-tasa').value  = '';
        document.getElementById('np-meses').value = '';
        document.getElementById('np-cuota-actual').value = '';
        document.getElementById('np-saldo-pendiente').value = '';
        document.getElementById('np-cuota').value = '';
        document.getElementById('np-totales').textContent = '';
        setPrestModo('nuevo');
        abrirModalFloat('modal-nuevo-prest');
    }

    async function crearPrestamo() {
        const btn    = document.getElementById('btn-crear-prest');
        const fecha  = document.getElementById('np-fecha').value;
        const monto  = document.getElementById('np-monto').value;
        const tasa   = document.getElementById('np-tasa').value;
        const meses  = document.getElementById('np-meses').value;
        const cuotaActual    = document.getElementById('np-cuota-actual').value;
        const saldoPendiente = document.getElementById('np-saldo-pendiente').value;

        if (!fecha || !monto || !meses) { alert('Completa todos los campos obligatorios'); return; }
        if (_prestModo === 'migrar' && (!cuotaActual || saldoPendiente === '')) {
            alert('Para migrar un préstamo existente, completa N° de Cuota Actual y Saldo Pendiente Actual');
            return;
        }

        btn.disabled = true; btn.textContent = 'Creando…';
        const fd = new FormData();
        fd.append('clienteUuid',    _regUuid);
        fd.append('modo',           _prestModo);
        fd.append('fecha_prestamo', fecha);
        fd.append('monto_prestado', monto);
        fd.append('tasa_interes',   tasa || '0');
        fd.append('plazo_meses',    meses);
        if (_prestModo === 'migrar') {
            fd.append('numero_cuota_actual',    cuotaActual);
            fd.append('saldo_pendiente_actual', saldoPendiente);
        }
        try {
            const res  = await fetch('/gyrosfe/api/prestamo_crear.php', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.ok) {
                cerrarModalFloat('modal-nuevo-prest');
                await cargarPrestamos(_regUuid);
                // Recargar pagos si el tab de pagos ya fue visitado
                if (document.getElementById('tab-pagos').classList.contains('active')) {
                    await cargarPagos(_regUuid);
                } else {
                    // Limpiar cache de pagos para forzar recarga al cambiar
                    document.getElementById('pagos-content').innerHTML = '';
                }
            } else {
                alert('Error: ' + (json.error ?? 'No se pudo crear'));
            }
        } catch(err) { alert('Error de red: ' + err.message); }
        finally { btn.disabled = false; btn.textContent = 'Crear Préstamo'; }
    }

    // ── Eliminar Préstamo ────────────────────────────────────────────
    async function eliminarPrestamo(id) {
        if (!confirm('¿Está seguro que desea eliminar este préstamo?\n\nSe eliminarán también todas las cuotas generadas. Esta acción no se puede deshacer.')) return;
        const fd = new FormData();
        fd.append('id_prestamo', id);
        try {
            const res  = await fetch('/gyrosfe/api/prestamo_eliminar.php', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.ok) {
                await cargarPrestamos(_regUuid);
                if (document.getElementById('tab-pagos').classList.contains('active')) {
                    await cargarPagos(_regUuid);
                } else {
                    document.getElementById('pagos-content').innerHTML = '';
                }
            } else {
                alert('Error: ' + (json.error ?? 'No se pudo eliminar'));
            }
        } catch(err) { alert('Error de red: ' + err.message); }
    }

    // ── Abrir modal Editar Préstamo ──────────────────────────────────
    function abrirEditarPrestamo(ds) {
        document.getElementById('ep-id').value    = ds.id;
        document.getElementById('ep-monto').value = ds.monto;
        document.getElementById('ep-tasa').value  = ds.tasa;
        document.getElementById('ep-meses').value = ds.meses;
        recalcCuotaEdit();
        abrirModalFloat('modal-editar-prest');
    }

    async function guardarPrestamo() {
        const btn   = document.getElementById('btn-guardar-prest');
        const id    = document.getElementById('ep-id').value;
        const monto = document.getElementById('ep-monto').value;
        const tasa  = document.getElementById('ep-tasa').value;
        const meses = document.getElementById('ep-meses').value;
        if (!id || !monto || !meses) { alert('Datos inválidos'); return; }
        btn.disabled = true; btn.textContent = 'Guardando…';
        const fd = new FormData();
        fd.append('id_prestamo',   id);
        fd.append('monto_prestado', monto);
        fd.append('tasa_interes',   tasa || '0');
        fd.append('plazo_meses',    meses);
        try {
            const res  = await fetch('/gyrosfe/api/prestamo_editar.php', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.ok) {
                cerrarModalFloat('modal-editar-prest');
                await cargarPrestamos(_regUuid);
                document.getElementById('pagos-content').innerHTML = '';
            } else {
                alert('Error: ' + (json.error ?? 'No se pudo actualizar'));
            }
        } catch(err) { alert('Error de red: ' + err.message); }
        finally { btn.disabled = false; btn.textContent = 'Guardar Cambios'; }
    }

    // ════════════════════════════════════════════════════════════════
    // PAGOS
    // ════════════════════════════════════════════════════════════════

    async function cargarPagos(uuid) {
        const loading = document.getElementById('pagos-loading');
        const content = document.getElementById('pagos-content');
        content.innerHTML = '';
        loading.style.display = 'flex';
        try {
            const res  = await fetch(`/gyrosfe/api/pagos_listar.php?uuid=${encodeURIComponent(uuid)}`);
            const json = await res.json();
            loading.style.display = 'none';
            if (!json.ok) { content.innerHTML = `<p style="color:#f87171;padding:20px">Error: ${json.error}</p>`; return; }
            if (json.data.length === 0) {
                content.innerHTML = '<div class="tab-placeholder"><span>💵</span>No hay préstamos registrados.</div>';
                return;
            }
            content.innerHTML = json.data.map((item, idx) => renderPagosBloque(item, idx + 1)).join('');
            // Eventos en botones de pago
            content.querySelectorAll('.btn-reg-pago').forEach(btn => {
                btn.addEventListener('click', () => abrirRegPago(btn.dataset));
            });
            // Eventos en inputs de fecha de pago
            content.querySelectorAll('.pago-fecha-input').forEach(inp => {
                inp.addEventListener('change', async function() {
                    const idPago    = this.dataset.id;
                    const fechaPago = this.value;
                    if (!fechaPago) return;
                    this.classList.add('saving');
                    try {
                        const fd = new FormData();
                        fd.append('id_pago',    idPago);
                        fd.append('fecha_pago', fechaPago);
                        const res  = await fetch('/gyrosfe/api/pago_fecha.php', { method: 'POST', body: fd });
                        const data = await res.json();
                        if (data.ok) {
                            await cargarPagos(_regUuid);
                        } else {
                            alert('Error al actualizar fecha: ' + (data.error ?? 'desconocido'));
                            this.classList.remove('saving');
                        }
                    } catch(e) {
                        alert('Error de red: ' + e.message);
                        this.classList.remove('saving');
                    }
                });
            });
            // Aceptar la devolución (DEV) de un débito: crea la fila en "devoluciones"
            content.querySelectorAll('.btn-acepta-dev').forEach(btn => {
                btn.addEventListener('click', async function() {
                    const fila  = this.closest('tr');
                    const dev   = fila?.querySelector('.val-debito')?.textContent ?? 'DEV';
                    if (!confirm(`¿Registrar la devolución (${dev})?`)) return;
                    this.disabled = true;
                    try {
                        const fd = new FormData();
                        fd.append('id_pago', this.dataset.id);
                        const res  = await fetch('/gyrosfe/api/devolucion_crear.php', { method: 'POST', body: fd });
                        const data = await res.json();
                        if (data.ok) {
                            await cargarPagos(_regUuid);
                        } else {
                            alert('Error al registrar la devolución: ' + (data.error ?? 'desconocido'));
                            this.disabled = false;
                        }
                    } catch(e) {
                        alert('Error de red: ' + e.message);
                        this.disabled = false;
                    }
                });
            });
            // Eventos en inputs de cuota editable
            content.querySelectorAll('.pago-cuota-input').forEach(inp => {
                inp.addEventListener('change', async function() {
                    const cuota = parseFloat(this.value);
                    if (isNaN(cuota) || cuota <= 0) {
                        alert('Ingresa una cuota válida.');
                        this.value = this.dataset.original;
                        return;
                    }
                    this.classList.add('saving');
                    try {
                        const fd = new FormData();
                        fd.append('id_pago', this.dataset.id);
                        fd.append('cuota',   cuota.toFixed(2));
                        const res  = await fetch('/gyrosfe/api/pago_cuota.php', { method: 'POST', body: fd });
                        const data = await res.json();
                        if (data.ok) {
                            await cargarPagos(_regUuid);
                        } else {
                            alert('Error al actualizar cuota: ' + (data.error ?? 'desconocido'));
                            this.value = this.dataset.original;
                            this.classList.remove('saving');
                        }
                    } catch(e) {
                        alert('Error de red: ' + e.message);
                        this.value = this.dataset.original;
                        this.classList.remove('saving');
                    }
                });
            });
        } catch(err) {
            loading.style.display = 'none';
            content.innerHTML = `<p style="color:#f87171;padding:20px">Error de red: ${err.message}</p>`;
        }
    }

    function estadoClass(estado) {
        const m = { pagado: 'estado-pagado', pendiente: 'estado-pendiente',
                    vencido: 'estado-vencido', parcial: 'estado-parcial' };
        return m[estado] ?? 'estado-default';
    }
    function estadoLabel(estado) {
        const m = { pagado: 'Pagado', pendiente: 'Pendiente',
                    vencido: 'Vencido', parcial: 'Parcial' };
        return m[estado] ?? estado;
    }
    // Calcula el estado visual: si el pago no está cerrado y fecha_pago < hoy → vencido
    function calcEstado(c) {
        if (c.estado === 'pagado' || c.estado === 'parcial') return c.estado;
        const hoy      = new Date().toISOString().split('T')[0];
        const fechaPago = c.fecha_pago ? c.fecha_pago.substring(0, 10) : null;
        if (fechaPago && fechaPago < hoy) return 'vencido';
        return c.estado;
    }

    let _transferencias = [];

    async function cargarTransferencias(uuid) {
        const loading = document.getElementById('transf-loading');
        const content = document.getElementById('transf-content');
        content.innerHTML = '';
        loading.style.display = 'flex';
        try {
            const res  = await fetch(`/gyrosfe/api/transferencias_listar.php?uuid=${encodeURIComponent(uuid)}`);
            const json = await res.json();
            loading.style.display = 'none';
            if (!json.ok) { content.innerHTML = `<p style="color:#f87171;padding:20px">Error: ${json.error}</p>`; return; }
            _transferencias = json.data;
            if (json.data.length === 0) {
                content.innerHTML = '<div class="tab-placeholder"><span>🏧</span>No hay transferencias registradas.</div>';
                return;
            }
            content.innerHTML = renderTransferencias(json.data);
            content.querySelectorAll('.btn-ver-dev').forEach(btn => {
                btn.addEventListener('click', () => abrirDetalleDevolucion(btn.dataset.nro));
            });
            content.querySelectorAll('.btn-ver-dev-cerrada').forEach(btn => {
                btn.addEventListener('click', () => abrirDetalleDevolucion(btn.dataset.nro, { soloLectura: true }));
            });
            // Débito del flujo anterior: registrar su DEV crea la fila en "devoluciones"
            content.querySelectorAll('.btn-acepta-dev').forEach(btn => {
                btn.addEventListener('click', async function() {
                    const dev = this.closest('tr')?.querySelector('.val-debito')?.textContent ?? 'DEV';
                    if (!confirm(`¿Registrar la devolución (${dev})?`)) return;
                    this.disabled = true;
                    try {
                        const fd = new FormData();
                        fd.append('id_pago', this.dataset.id);
                        const res  = await fetch('/gyrosfe/api/devolucion_crear.php', { method: 'POST', body: fd });
                        const data = await res.json();
                        if (data.ok) {
                            await cargarTransferencias(_regUuid);
                        } else {
                            alert('Error al registrar la devolución: ' + (data.error ?? 'desconocido'));
                            this.disabled = false;
                        }
                    } catch(e) {
                        alert('Error de red: ' + e.message);
                        this.disabled = false;
                    }
                });
            });
        } catch(err) {
            loading.style.display = 'none';
            content.innerHTML = `<p style="color:#f87171;padding:20px">Error de red: ${err.message}</p>`;
        }
    }

    // Pestaña TRANSFERENCIAS: una fila por ATM (# = transferencia.nro_operacion)
    function renderTransferencias(lista) {
        const num = v => parseFloat(v ?? 0);
        const filas = lista.map(t => {
            const dev = num(t.devolucion);
            // Débito del flujo anterior con DEV aún sin registrar en "devoluciones"
            const devSinRegistrar = dev > 0 && t.devolucion_uuid == null && t.id_pago != null;
            let accionHtml;
            if (devSinRegistrar) {
                accionHtml = `<button class="btn-accion btn-acepta-dev" data-id="${t.id_pago}"
                        title="Aceptar y registrar la devolución">✅</button>`;
            } else if (dev > 0 && t.devolucion_estado === 'pendiente') {
                accionHtml = `<button class="btn-accion btn-ver-dev" data-nro="${t.nro_operacion}"
                        title="Devolución pendiente: ver detalle y completarla">↩️</button>`;
            } else if (dev > 0 && t.devolucion_uuid != null) {
                accionHtml = `<span class="pago-cerrado" title="Transferencia y devolución cerradas">🔒</span>
                    <button class="btn-accion btn-ver-dev-cerrada" data-nro="${t.nro_operacion}"
                        title="Ver detalle de la devolución">🔍</button>`;
            } else if (dev > 0) {
                accionHtml = `<span class="pago-cerrado" title="Transferencia y devolución cerradas">🔒</span>`;
            } else {
                accionHtml = `<span class="pago-cerrado" title="Débito cerrado">🔒</span>`;
            }
            return `<tr>
                <td class="mono">${t.nro_operacion}</td>
                <td>${fmtDate(t.fecha)}</td>
                <td class="transf-lineas"><div class="transf-fila">
                    <div><span class="transf-etq">Débito:</span> ${fmtMoney(t.monto_debitado)}</div>
                    <div class="${dev > 0 ? 'val-debito' : ''}"><span class="transf-etq">Devolución:</span> ${fmtMoney(t.devolucion)}</div>
                    <div class="val-real"><span class="transf-etq">Aporte:</span> ${fmtMoney(t.aporte)}</div>
                </div></td>
                <td class="mono">${t.nro_envio ?? '—'}</td>
                <td style="text-align:center">${accionHtml}</td>
            </tr>`;
        }).join('');

        const total = campo => lista.reduce((s, t) => s + num(t[campo]), 0);
        const filaTotales = `<tr class="transf-totales">
            <td colspan="2"></td>
            <td class="transf-lineas"><div class="transf-fila">
                <div><span class="transf-etq">Transferencia:</span> Bs ${total('monto_debitado').toFixed(2)}</div>
                <div><span class="transf-etq">Devoluciones:</span> Bs ${total('devolucion').toFixed(2)}</div>
                <div><span class="transf-etq">Pagos:</span> Bs ${total('aporte').toFixed(2)}</div>
            </div></td>
            <td colspan="2"></td>
        </tr>`;

        return `<div class="pago-table-wrap">
            <table class="pago-table">
                <thead><tr>
                    <th>#</th>
                    <th>FECHA</th>
                    <th>DETALLE TRANSFERENCIA</th>
                    <th>N° ENVÍO</th>
                    <th>ACCIONES</th>
                </tr></thead>
                <tbody>${filas}${filaTotales}</tbody>
            </table>
        </div>`;
    }

    function abrirDetalleDevolucion(nro, { soloLectura = false } = {}) {
        const t = _transferencias.find(x => String(x.nro_operacion) === String(nro));
        if (!t) return;
        document.getElementById('dd-titulo').textContent = `${soloLectura ? '🔍' : '↩️'} Devolución · transferencia #${t.nro_operacion}`;
        // El modal es compartido: lo que oculta la vista de solo lectura hay que restaurarlo al completar
        document.getElementById('dd-form').style.display          = soloLectura ? 'none' : '';
        document.getElementById('dd-btn-completar').style.display = soloLectura ? 'none' : '';
        document.getElementById('dd-btn-cancelar').textContent    = soloLectura ? 'Cerrar' : 'Cancelar';
        document.getElementById('dd-tabla').replaceChildren(renderTablaDevoluciones([{
            codigo_cliente:     t.codigo_cliente,
            nombre_cliente:     t.nombre_cliente,
            ci_cliente:         t.ci_cliente,
            monto_a_devolver:   t.monto_a_devolver,
            fecha_cobro_debito: t.fecha_cobro_debito,
            modo_devolucion:    t.modo_devolucion,
            referencia:         t.referencia,
            estado:             t.devolucion_estado,
            fecha_devolucion:   t.fecha_devolucion,
        }]));
        document.getElementById('dd-uuid').value  = t.devolucion_uuid;
        document.getElementById('dd-modo').value  = ['Transferencia', 'QR'].includes(t.modo_devolucion) ? t.modo_devolucion : 'Efectivo';
        document.getElementById('dd-referencia').value = t.referencia ?? '';
        document.getElementById('dd-fecha').value = new Date().toLocaleDateString('en-CA', { timeZone: 'America/La_Paz' });
        abrirModalFloat('modal-devolucion-detalle');
    }

    async function completarDevolucion() {
        const uuid  = document.getElementById('dd-uuid').value;
        const modo  = document.getElementById('dd-modo').value;
        const fecha = document.getElementById('dd-fecha').value;
        const referencia = document.getElementById('dd-referencia').value.trim();
        if (!referencia) {
            alert('Ingresa la referencia de la devolución.');
            return;
        }
        if (!fecha) {
            alert('Ingresa la fecha de devolución.');
            return;
        }
        if (!confirm(`¿Confirmar que la devolución se entregó (${modo}, ref. ${referencia}, ${fmtDate(fecha)})?`)) return;
        const btn = document.getElementById('dd-btn-completar');
        btn.disabled = true;
        try {
            const fd = new FormData();
            fd.append('uuid',  uuid);
            fd.append('modo',  modo);
            fd.append('fecha', fecha);
            fd.append('referencia', referencia);
            const res  = await fetch('/gyrosfe/api/devolucion_completar.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (!data.ok) {
                alert('Error al completar la devolución: ' + (data.error ?? 'desconocido'));
                return;
            }
            cerrarModalFloat('modal-devolucion-detalle');
            await cargarTransferencias(_regUuid);
        } catch (e) {
            alert('Error de red: ' + e.message);
        } finally {
            btn.disabled = false;
        }
    }

    function renderPagosBloque(item, num) {
        const p = item.prestamo;
        const cuotas = item.cuotas;
        const header = `<div class="pago-bloque-header">
            <span><strong>PRÉSTAMO #${num}</strong></span>
            <span>Fecha: <strong>${fmtDate(p.fecha_prestamo)}</strong></span>
            <span>Monto: <strong>${fmtMoney(p.monto_prestado)}</strong></span>
            <span>Plazo: <strong>${p.plazo_meses} meses</strong></span>
            <span>Tasa: <strong>${parseFloat(p.tasa_interes).toFixed(2)}%</strong></span>
        </div>`;

        if (!cuotas || cuotas.length === 0) {
            return `<div class="pago-bloque">${header}<p style="color:#555c7a;padding:10px">Sin cuotas generadas.</p></div>`;
        }

        const filas = cuotas.map((c) => {
            const diasProg = c.dias_programado != null ? parseInt(c.dias_programado) : 30;
            const diasReal = c.dias_real       != null ? parseInt(c.dias_real)       : null;
            const isLate   = diasReal !== null && diasReal > diasProg;

            // DÍAS: programado arriba (gris), real abajo (coloreado si existe)
            const diasHtml = diasReal !== null
                ? `<span class="val-prog">${diasProg}d</span><br><span class="val-real${isLate ? ' late' : ''}">${diasReal}d</span>`
                : `${diasProg}d`;

            // INTERÉS, CAPITAL, SALDO: si hay valor real, poner prog en gris + real coloreado
            const hasReal = c.interes_real != null;
            const interesHtml = hasReal
                ? `<span class="val-prog">${fmtMoney(c.monto_a_interes)}</span><br><span class="val-real${isLate ? ' late' : ''}">${fmtMoney(c.interes_real)}</span>`
                : fmtMoney(c.monto_a_interes);
            const capitalHtml = hasReal
                ? `<span class="val-prog">${fmtMoney(c.monto_a_devolucion_kapital)}</span><br><span class="val-real${isLate ? ' late' : ''}">${fmtMoney(c.capital_real)}</span>`
                : fmtMoney(c.monto_a_devolucion_kapital);
            const saldoHtml = hasReal
                ? `<span class="val-prog">${fmtMoney(c.saldo_deudor)}</span><br><span class="val-real${isLate ? ' late' : ''}">${fmtMoney(c.saldo_deudor_real)}</span>`
                : fmtMoney(c.saldo_deudor);

            // TRANSF.: monto cobrado y, si hubo débito, tres filas: monto debitado, cuota a
            // restar y DEV = débito − cuota. El ATM ya fija la cuota (= APORTE) y registra la
            // devolución al transferir; la edición y el ✅ quedan para débitos del flujo anterior.
            const cerrado      = !!c.nro_envio_transferencia;
            const hayDebito    = c.debito != null;
            const devAceptada  = c.devolucion_uuid != null;
            const cuotaFijada  = devAceptada || cerrado;
            const hayDev       = parseFloat(c.dev ?? 0) > 0;
            const cuotaVigente = parseFloat(c.cuota_editada ?? c.cuota_fija).toFixed(2);
            const debitoTitle  = hayDebito
                ? `Débito · N° ${c.nro_envio_debito ?? '—'} · ${c.fecha_debito_extra ? fmtDate(c.fecha_debito_extra) : '—'}`
                : '';
            const cobroHtml = c.transferencia != null ? fmtMoney(c.transferencia) : '';
            const debitoHtml = hayDebito
                ? `<span title="${debitoTitle}">${fmtMoney(c.debito)}</span>` +
                  `<br><input type="number" class="pago-cuota-input mono" min="0.01" step="0.01"` +
                  ` title="${cuotaFijada ? 'Cuota no editable' : 'Cuota a restar del débito'}"${cuotaFijada ? ' disabled' : ''}` +
                  ` data-id="${c.id_pago}" data-original="${cuotaVigente}" value="${cuotaVigente}">` +
                  `<br><span class="val-debito">DEV ${fmtMoney(c.dev)}</span>`
                : '';
            const transfHtml = [cobroHtml, debitoHtml].filter(Boolean).join('<br>') || '—';

            let accionHtml;
            if (hayDebito && hayDev && !devAceptada) {
                accionHtml = `<button class="btn-accion btn-acepta-dev" data-id="${c.id_pago}"
                        title="Aceptar y registrar la devolución">✅</button>`;
            } else if (devAceptada) {
                accionHtml = `<span class="pago-cerrado" title="Devolución registrada">🔒</span>`;
            } else if (cerrado) {
                accionHtml = `<span class="pago-cerrado" title="Mes cobrado y cerrado">🔒</span>`;
            } else {
                accionHtml = `<button class="btn-accion btn-reg-pago"
                        data-id="${c.id_pago}"
                        data-fecha="${c.fecha_pago ?? ''}"
                        data-estado="${calcEstado(c)}"
                        title="Registrar pago">💸</button>`;
            }

            const saldoFin   = parseFloat(c.saldo_deudor ?? 0).toFixed(2);
            const fechaVal   = c.fecha_pago      ? c.fecha_pago.substring(0, 10)      : '';
            const fechaDebito = c.fecha_de_debito ? c.fecha_de_debito.substring(0, 10) : null;
            const fechaDebitoHtml = fechaDebito
                ? `<br><span class="val-real${isLate ? ' late' : ''}">${fmtDate(fechaDebito)}</span>`
                : '';
            return `<tr data-id-pago="${c.id_pago}">
                <td>${c.mes}</td>
                <td>
                    <input type="date" class="pago-fecha-input" data-id="${c.id_pago}" value="${fechaVal}">
                    ${fechaDebitoHtml}
                </td>
                <td>${fmtMoney(c.cuota_fija)}</td>
                <td>${interesHtml}</td>
                <td>${capitalHtml}</td>
                <td>${saldoHtml}</td>
                <td style="text-align:center">${diasHtml}</td>
                <td>${fmtMoney(c.saldo_inicial)} / Bs ${saldoFin}</td>
                <td>${transfHtml}</td>
                <td class="mono">${c.nro_envio_transferencia ?? '—'}</td>
                <td class="${estadoClass(calcEstado(c))}">${estadoLabel(calcEstado(c))}</td>
                <td style="text-align:center">${accionHtml}</td>
            </tr>`;
        }).join('');

        const totalInteres     = cuotas.reduce((s, c) => s + parseFloat(c.monto_a_interes             || 0), 0);
        const totalCapital     = cuotas.reduce((s, c) => s + parseFloat(c.monto_a_devolucion_kapital  || 0), 0);
        const totalInteresReal = cuotas.reduce((s, c) => s + parseFloat(c.interes_real                || 0), 0);
        const totalCapitalReal = cuotas.reduce((s, c) => s + parseFloat(c.capital_real                || 0), 0);
        const anyReal          = cuotas.some(c => c.interes_real != null);

        const filaTotalesPago = `<tr style="border-top:2px solid #2a2f45">
            <td colspan="3" style="text-align:right;font-size:.68rem;font-weight:700;letter-spacing:.05em;color:#7b93ff;text-transform:uppercase;padding-right:10px">Totales:</td>
            <td style="text-align:center">
                <span class="${anyReal ? 'val-prog' : ''}" style="font-weight:700;color:#e0e4f0">Bs ${totalInteres.toFixed(2)}</span>
                ${anyReal ? `<br><span class="val-real">Bs ${totalInteresReal.toFixed(2)}</span>` : ''}
            </td>
            <td style="text-align:center">
                <span class="${anyReal ? 'val-prog' : ''}" style="font-weight:700;color:#e0e4f0">Bs ${totalCapital.toFixed(2)}</span>
                ${anyReal ? `<br><span class="val-real">Bs ${totalCapitalReal.toFixed(2)}</span>` : ''}
            </td>
            <td colspan="7"></td>
        </tr>`;

        return `<div class="pago-bloque">
            ${header}
            <div class="pago-table-wrap">
                <table class="pago-table">
                    <thead><tr>
                        <th>MES</th>
                        <th>FECHA PAGO <span class="th-pr">P/R</span></th>
                        <th>CUOTA FIJA</th>
                        <th>INTERÉS <span class="th-pr">P/R</span></th>
                        <th>CAPITAL <span class="th-pr">P/R</span></th>
                        <th>SALDO <span class="th-pr">P/R</span></th>
                        <th>DÍAS <span class="th-pr">P/R</span></th>
                        <th>INICIAL/FINAL</th>
                        <th>TRANSF.</th>
                        <th>N° ENVÍO</th>
                        <th>ESTADO</th>
                        <th>ACCIONES</th>
                    </tr></thead>
                    <tbody>${filas}${filaTotalesPago}</tbody>
                </table>
            </div>
        </div>`;
    }

    // ── Abrir modal Registrar Pago ───────────────────────────────────
    function abrirRegPago(ds) {
        document.getElementById('rp-id').value     = ds.id;
        document.getElementById('rp-fecha').value  = ds.fecha || new Date().toISOString().split('T')[0];
        document.getElementById('rp-monto').value  = '';
        document.getElementById('rp-transf').value = '';
        document.getElementById('rp-metodo').value = '';
        document.getElementById('rp-estado').value = ds.estado === 'pagado' ? 'pagado' : 'pagado';
        abrirModalFloat('modal-reg-pago');
    }

    async function guardarPago() {
        const btn    = document.getElementById('btn-guardar-pago');
        const id     = document.getElementById('rp-id').value;
        const fecha  = document.getElementById('rp-fecha').value;
        const monto  = document.getElementById('rp-monto').value;
        const transf = document.getElementById('rp-transf').value;
        const metodo = document.getElementById('rp-metodo').value;
        const estado = document.getElementById('rp-estado').value;
        if (!id || !fecha) { alert('Completa todos los campos obligatorios'); return; }
        btn.disabled = true; btn.textContent = 'Guardando…';
        const fd = new FormData();
        fd.append('id_pago',     id);
        fd.append('fecha_pago',  fecha);
        fd.append('estado',      estado);
        if (monto)  fd.append('monto_pago',    monto);
        if (transf) fd.append('transferencia', transf);
        if (metodo) fd.append('metodo_pago',   metodo);
        try {
            const res  = await fetch('/gyrosfe/api/pago_actualizar.php', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.ok) {
                cerrarModalFloat('modal-reg-pago');
                await cargarPagos(_regUuid);
            } else {
                alert('Error: ' + (json.error ?? 'No se pudo guardar'));
            }
        } catch(err) { alert('Error de red: ' + err.message); }
        finally { btn.disabled = false; btn.textContent = 'Guardar Pago'; }
    }

    // ── Consulta de Saldo ────────────────────────────────────────
    async function consultaSaldo(uuid) {
        const btn  = document.querySelector(`button[onclick*="consultaSaldo('${uuid}')"]`);
        const cell = document.getElementById(`saldo-cell-${uuid}`);

        // Contador de segundos mientras espera (la automatización tarda ~60-120s)
        let secs = 0;
        if (btn)  { btn.disabled = true; btn.style.opacity = '0.4'; }
        if (cell) { cell.innerHTML = '<span style="color:#9aa0b8;font-size:.8rem">Consultando… 0s</span>'; }
        const timer = setInterval(() => {
            secs++;
            if (cell) cell.innerHTML = `<span style="color:#9aa0b8;font-size:.8rem">Consultando… ${secs}s</span>`;
        }, 1000);

        try {
            const fd = new FormData();
            fd.append('uuid', uuid);
            const ctrl = new AbortController();
            const tout = setTimeout(() => ctrl.abort(), 185000);
            const res  = await fetch('/gyrosfe/api/consulta_saldo.php', { method: 'POST', body: fd, signal: ctrl.signal });
            clearTimeout(tout);
            const data = await res.json();

            if (data.ok) {
                const saldoFmt = parseFloat(data.saldo).toFixed(2);
                const fechaFmt = data.fecha_hora
                    ? new Date(data.fecha_hora).toLocaleString('es-BO', {
                        day: '2-digit', month: '2-digit', year: '2-digit',
                        hour: '2-digit', minute: '2-digit'
                      })
                    : '';
                if (cell) {
                    cell.innerHTML =
                        `<span style="color:#34d399;font-weight:700">Bs. ${saldoFmt}</span>` +
                        (fechaFmt ? `<div class="small" style="color:#9aa0b8;margin-top:2px">${fechaFmt}</div>` : '');
                }
            } else {
                const etiqueta = data.codigo === 'cambio_clave' ? 'Cambiar clave' : 'Error';
                if (cell) { cell.innerHTML = `<span style="color:#f87171;font-size:.78rem">${etiqueta}</span>`; }
                alert('Error al consultar saldo: ' + (data.error ?? 'desconocido'));
            }
        } catch (err) {
            if (cell) { cell.innerHTML = '<span style="color:#f87171;font-size:.78rem">Error red</span>'; }
            alert('Error de red: ' + err.message);
        } finally {
            clearInterval(timer);
            if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
        }
    }

    // ── ATM: DEBITAR − APORTE = DEVOLUCIÓN (Transferencia a Terceros a cuentaOficina) ──
    // nroOperacion liga el saldo "después" a la transferencia; el "antes" lo toma debitar.php
    async function consultaSaldoLigada(uuid, nroOperacion) {
        const fd = new FormData();
        fd.append('uuid', uuid);
        if (nroOperacion) {
            fd.append('nro_operacion', nroOperacion);
            fd.append('tipo', 'despues');
        }
        const ctrl = new AbortController();
        const tout = setTimeout(() => ctrl.abort(), 185000);
        const res  = await fetch('/gyrosfe/api/consulta_saldo.php', { method: 'POST', body: fd, signal: ctrl.signal });
        clearTimeout(tout);
        return res.json();
    }

    async function postDebitar(uuid, monto, devolucion) {
        const fd = new FormData();
        fd.append('uuid', uuid);
        fd.append('monto', monto.toFixed(2));
        fd.append('devolucion', devolucion.toFixed(2));
        const ctrl = new AbortController();
        const tout = setTimeout(() => ctrl.abort(), 300000);
        try {
            const res = await fetch('/gyrosfe/api/debitar.php', { method: 'POST', body: fd, signal: ctrl.signal });
            return await res.json();
        } catch (err) {
            // Sin respuesta del servidor no se sabe si el agente llegó a transferir
            return { ok: false, transferencia_incierta: true, error: 'sin respuesta del servidor (' + err.message + ')' };
        } finally {
            clearTimeout(tout);
        }
    }

    function leerCentavos(input) {
        const v = parseFloat(input?.value ?? '');
        return isNaN(v) ? NaN : Math.round(v * 100);
    }

    function recalcDevolucion(uuid) {
        const debitarCts = leerCentavos(document.getElementById(`debitar-monto-${uuid}`));
        const aporteCts  = leerCentavos(document.getElementById(`aporte-monto-${uuid}`));
        const out = document.getElementById(`devolucion-calc-${uuid}`);
        if (!out) return;
        const devolucionCts = (isNaN(debitarCts) ? 0 : debitarCts) - (isNaN(aporteCts) ? 0 : aporteCts);
        out.textContent = (devolucionCts / 100).toFixed(2);
        out.classList.toggle('negativo', devolucionCts < 0);
    }

    // Mientras el operador no toque APORTE, todo el débito es aporte (DEVOLUCION = 0)
    function onDebitarInput(uuid) {
        const debitarInput = document.getElementById(`debitar-monto-${uuid}`);
        const aporteInput  = document.getElementById(`aporte-monto-${uuid}`);
        if (aporteInput && !aporteInput.dataset.editado) aporteInput.value = debitarInput.value;
        recalcDevolucion(uuid);
    }

    // Safari y Firefox en macOS saltan los botones con Tab (salvo "Navegación con teclado"
    // del sistema): sin esto el foco pasa de APORTE a la fila del siguiente cliente
    function tabAporteAlAtm(event, uuid) {
        if (event.key !== 'Tab' || event.shiftKey) return;
        const btn = document.getElementById(`atm-btn-${uuid}`);
        if (!btn) return;
        event.preventDefault();
        btn.focus();
    }

    function aporteResultadoHtml(data) {
        const partes = [];
        if (parseFloat(data.aporte) > 0)     partes.push(`Aporte ${parseFloat(data.aporte).toFixed(2)}`);
        if (parseFloat(data.devolucion) > 0) partes.push(`<span class="val-debito">DEV ${parseFloat(data.devolucion).toFixed(2)}</span>`);
        return `<span style="color:#34d399;font-weight:700">Debitado ${parseFloat(data.monto).toFixed(2)}</span>` +
            (partes.length ? ` · ${partes.join(' · ')}` : '') +
            `<div class="small mono" style="color:#9aa0b8"># ${data.nro_operacion ?? '—'} · N° ${data.numero_envio}</div>`;
    }

    async function ejecutarAporte(uuid) {
        const cell            = document.getElementById(`debitar-cell-${uuid}`);
        const saldoCell       = document.getElementById(`saldo-cell-${uuid}`);
        const debitarInput    = document.getElementById(`debitar-monto-${uuid}`);
        const aporteInput     = document.getElementById(`aporte-monto-${uuid}`);
        const resultado       = document.getElementById(`aporte-resultado-${uuid}`);
        const debitarCts      = leerCentavos(debitarInput);
        const aporteCts       = leerCentavos(aporteInput);

        if (isNaN(debitarCts) || debitarCts <= 0) {
            alert('El monto a DEBITAR debe ser mayor a cero.');
            debitarInput?.focus();
            return;
        }
        if (isNaN(aporteCts) || aporteCts < 0) {
            alert('El APORTE no puede ser negativo.');
            aporteInput?.focus();
            return;
        }
        const devolucionCts = debitarCts - aporteCts;
        if (devolucionCts < 0) {
            alert('El APORTE no puede superar el monto a DEBITAR.');
            aporteInput?.focus();
            return;
        }
        const monto      = debitarCts / 100;
        const devolucion = devolucionCts / 100;
        const aporte     = aporteCts / 100;
        if (!confirm(
            `¿Está seguro de continuar con la transferencia?\n\n` +
            `Debitar: Bs. ${monto.toFixed(2)}\n` +
            `Devolución: Bs. ${devolucion.toFixed(2)}\n` +
            `Aporte: Bs. ${aporte.toFixed(2)}`
        )) {
            return;
        }

        let secs = 0;
        let fase = 'Consultando saldo (antes)';
        const originalSaldo = saldoCell ? saldoCell.innerHTML : '';
        const renderProgreso = () => {
            const html = `<span style="color:#9aa0b8;font-size:.78rem">${fase}… ${secs}s</span>`;
            if (resultado) resultado.innerHTML = html;
            if (saldoCell) saldoCell.innerHTML = html;
        };
        cell?.classList.add('aporte-ocupado');
        renderProgreso();
        const timer = setInterval(() => { secs++; renderProgreso(); }, 1000);

        let transferido = false;
        let data = null;
        try {
            // 1) Saldo ANTES
            const antes = await consultaSaldoLigada(uuid, null);
            if (!antes.ok) throw new Error('Saldo (antes): ' + (antes.error ?? 'desconocido'));

            // 2) Transferencia por el monto total a DEBITAR
            fase = 'Debitando';
            data = await postDebitar(uuid, monto, devolucion);
            if (!data.ok) {
                transferido = !!(data.transferencia_incierta || data.transferencia_realizada);
                throw new Error(data.error ?? 'desconocido');
            }
            transferido = true;
            if (debitarInput) debitarInput.value = '0.00';
            if (aporteInput) {
                aporteInput.value = '0.00';
                delete aporteInput.dataset.editado;
            }
            recalcDevolucion(uuid);

            // 3) Saldo DESPUÉS
            fase = 'Consultando saldo (después)';
            const despues = await consultaSaldoLigada(uuid, data.nro_operacion);
            if (!despues.ok) throw new Error('Saldo (después): ' + (despues.error ?? 'desconocido'));

            if (saldoCell) {
                const fechaFmt = despues.fecha_hora
                    ? new Date(despues.fecha_hora).toLocaleString('es-BO', {
                        day: '2-digit', month: '2-digit', year: '2-digit',
                        hour: '2-digit', minute: '2-digit'
                    })
                    : '—';
                saldoCell.innerHTML =
                    `<span style="color:#34d399;font-weight:700">Bs. ${parseFloat(despues.saldo).toFixed(2)}</span>` +
                    `<div class="small" style="color:#9aa0b8;margin-top:2px">${fechaFmt}</div>` +
                    `<div class="small mono" style="color:#7b93ff;margin-top:2px">${parseFloat(antes.saldo).toFixed(2)} → ${parseFloat(despues.saldo).toFixed(2)}</div>`;
            }
            // El contador escribe en el mismo div: detenerlo antes de dejar el resultado
            clearInterval(timer);
            if (resultado) resultado.innerHTML = aporteResultadoHtml(data);
        } catch (err) {
            clearInterval(timer);
            if (saldoCell) saldoCell.innerHTML = originalSaldo;
            if (transferido) {
                if (resultado) {
                    resultado.innerHTML = data?.ok
                        ? aporteResultadoHtml(data)
                        : `<span style="color:#f87171">Verificar en el banco</span>`;
                }
                alert('ATENCIÓN: la transferencia se realizó o pudo haberse realizado. ' +
                      'NO reintentar sin verificar en el banco y en el journal del agente.\n\n' + err.message);
            } else {
                if (resultado) resultado.innerHTML = '';
                alert('Error al debitar (no se transfirió): ' + err.message);
            }
        } finally {
            clearInterval(timer);
            cell?.classList.remove('aporte-ocupado');
        }
    }

    async function toggleActivo(chk) {
        const uuid    = chk.dataset.uuid ?? '';
        const activo  = chk.checked;
        const saving  = document.getElementById('gen-activo-saving');

        if (!uuid) { chk.checked = !activo; return; }

        chk.disabled = true;
        if (saving) saving.style.display = 'inline';
        actualizarToggleLabel(activo);

        const fd = new FormData();
        fd.append('uuid',     uuid);
        fd.append('isActive', activo ? '1' : '0');

        try {
            const res  = await fetch('/gyrosfe/api/cliente_toggle_activo.php', { method: 'POST', body: fd });
            const json = await res.json();
            if (!json.ok) {
                // Revertir si falló
                chk.checked = !activo;
                actualizarToggleLabel(!activo);
                alert('Error al cambiar estado: ' + (json.error ?? 'Desconocido'));
            } else {
                // Actualizar data-activo en la fila de la tabla principal
                const row = document.querySelector(`#tbl tbody tr[data-uuid="${uuid}"]`);
                if (row) row.dataset.activo = activo ? '1' : '0';
            }
        } catch (err) {
            chk.checked = !activo;
            actualizarToggleLabel(!activo);
            alert('Error de red: ' + err.message);
        } finally {
            chk.disabled = false;
            if (saving) saving.style.display = 'none';
        }
    }
    </script>

</body>
</html>
