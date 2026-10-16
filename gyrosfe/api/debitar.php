<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
auth_require_login();

header('Content-Type: application/json; charset=utf-8');

function jsonErr(string $msg, int $code = 400, array $extra = []): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg] + $extra);
    exit;
}

function aCentavos(string $valor): ?int
{
    if (!is_numeric($valor)) {
        return null;
    }
    return (int) round((float) $valor * 100);
}

function deCentavos(int $centavos): string
{
    return number_format($centavos / 100, 2, '.', '');
}

// Misma regla que es_banco_union del agente (union/config.py)
function esBancoUnion(string $banco): bool
{
    return str_contains(strtoupper(strtr($banco, ['ó' => 'o', 'Ó' => 'O'])), 'UNION');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonErr('Método no permitido', 405);
}

$uuid = trim((string) ($_POST['uuid'] ?? ''));
if ($uuid === '') {
    jsonErr('Parámetro requerido: uuid');
}

// En centavos enteros: DEBITAR − DEVOLUCIÓN no debe arrastrar errores de coma flotante
$montoCts      = aCentavos(trim((string) ($_POST['monto']      ?? '')));
$devolucionCts = aCentavos(trim((string) ($_POST['devolucion'] ?? '0')));

if ($montoCts === null || $montoCts <= 0) {
    jsonErr('El monto a debitar debe ser mayor a cero');
}
if ($devolucionCts === null || $devolucionCts < 0) {
    jsonErr('La devolución no puede ser negativa');
}
$aporteCts = $montoCts - $devolucionCts;
if ($aporteCts < 0) {
    jsonErr('La devolución no puede superar el monto a debitar');
}

$monto      = deCentavos($montoCts);
$devolucion = deCentavos($devolucionCts);
$aporte     = deCentavos($aporteCts);

require_once __DIR__ . '/../lib/db_connect.php';

try {
    $pdo = db_connect();
} catch (Throwable $e) {
    jsonErr('Error de conexión a BD', 500);
}

try {
    // 1. Cliente y su dispositivo
    $stmtCl = $pdo->prepare('
        SELECT uuid, codigo, nombrecompleto, ci, dispositivo
        FROM "Cliente"
        WHERE uuid = :uuid
        LIMIT 1
    ');
    $stmtCl->execute([':uuid' => $uuid]);
    $cliente = $stmtCl->fetch(PDO::FETCH_ASSOC);

    if (!$cliente || empty($cliente['dispositivo'])) {
        jsonErr('Cliente no encontrado o sin dispositivo asignado', 404);
    }

    // 2. Cuota pendiente más próxima (misma regla que la columna Aporte de ui/main.php)
    $stmtPago = $pdo->prepare('
        SELECT pg."id_pago", pg."fecha_pago", pg."nro_envio_transferencia",
               pg."debito", pg."devolucion_uuid",
               pg."mes", pg."saldo_inicial",
               pg."prestamoIdPrestamo",
               pr."tasa_interes", pr."fecha_prestamo"
        FROM "pago" pg
        JOIN "prestamo" pr ON pr."id_prestamo" = pg."prestamoIdPrestamo"
        WHERE pr."clienteIdCliente" = :uuid
          AND pr."isActive" = true
          AND pg."isActive" = true
          AND pg.estado <> \'pagado\'
        ORDER BY pg.fecha_pago ASC
        LIMIT 1
    ');
    $stmtPago->execute([':uuid' => $uuid]);
    $pago = $stmtPago->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($aporteCts > 0) {
        if ($pago === null) {
            jsonErr('El cliente no tiene cuotas pendientes donde registrar el aporte', 409);
        }
        if (!empty($pago['nro_envio_transferencia'])) {
            jsonErr('Esta cuota ya fue cobrada. N° envío: ' . $pago['nro_envio_transferencia'], 409);
        }
    }

    // 3. Cuenta bancaria con nickname = 'pago'
    $stmtBc = $pdo->prepare("
        SELECT usuario, key, nombre, banco
        FROM banco_cliente
        WHERE \"clienteIdCliente\" = :uuid
          AND lower(nickname) = 'pago'
          AND \"isActive\" = true
        LIMIT 1
    ");
    $stmtBc->execute([':uuid' => $uuid]);
    $cuenta = $stmtBc->fetch(PDO::FETCH_ASSOC);

    if (!$cuenta) {
        jsonErr('No se encontró cuenta bancaria con nickname "pago"', 404);
    }
    // La cuenta oficina es del Banco Unión y el agente solo sabe debitar desde UNImóvil Plus:
    // un cliente de otro banco no se puede debitar todavía (haría falta la app de su banco).
    if (!esBancoUnion((string) ($cuenta['banco'] ?? ''))) {
        jsonErr('Débito desde ' . ($cuenta['banco'] ?: 'banco sin nombre') . ' aún no soportado: solo cuentas del Banco Unión', 409);
    }

    // 4. Agente activo para este dispositivo (cada agente tunelado a un puerto local distinto)
    $stmtPort = $pdo->prepare('
        SELECT a."tunnelPort"
        FROM "UsbDeviceState" uds
        JOIN "Agent" a ON a.id = uds."agentId"
        WHERE uds.serial = :serial AND uds.status = \'connected\'
        ORDER BY uds."lastChangeAt" DESC
        LIMIT 1
    ');
    $stmtPort->execute([':serial' => $cliente['dispositivo']]);
    $agentRow = $stmtPort->fetch(PDO::FETCH_ASSOC);

    if (!$agentRow || empty($agentRow['tunnelPort'])) {
        jsonErr('Dispositivo no está conectado a ningún agente activo', 502);
    }
    $agentPort = (int) $agentRow['tunnelPort'];

    // 5. Transferencia a Terceros (Unión → Unión) por el monto total a debitar
    $payload = json_encode([
        'usuario'        => $cuenta['usuario']  ?? '',
        'password'       => $cuenta['key']      ?? '',
        'nombre_titular' => $cuenta['nombre']   ?? '',
        'dispositivo'    => $cliente['dispositivo'],
        'monto'          => $monto,
        'fecha_pago'     => $pago['fecha_pago'] ?? date('Y-m-d'),
    ]);

    $ch = curl_init("http://127.0.0.1:{$agentPort}/debitar");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 280,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $resp      = curl_exec($ch);
    $curlErr   = curl_error($ch);
    $curlErrNo = curl_errno($ch);
    curl_close($ch);

    if ($resp === false || $resp === '') {
        // Un timeout de lectura puede ocurrir con la transferencia ya confirmada en el teléfono
        $incierta = $curlErrNo === CURLE_OPERATION_TIMEDOUT;
        jsonErr(
            ($incierta
                ? 'El agente no respondió a tiempo: la transferencia pudo haberse realizado. NO reintentar sin verificar en el banco. '
                : 'No se pudo contactar al agente: ') . $curlErr,
            502,
            $incierta ? ['transferencia_incierta' => true] : []
        );
    }

    $agentData = json_decode($resp, true);
    if (!($agentData['ok'] ?? false)) {
        $extra = !empty($agentData['transferencia_incierta']) ? ['transferencia_incierta' => true] : [];
        jsonErr($agentData['error'] ?? 'Error desconocido en agente', 500, $extra);
    }

    $numeroEnvio = (string) ($agentData['numero_envio'] ?? '');
} catch (Throwable $e) {
    jsonErr('Error interno: ' . $e->getMessage(), 500);
}

// 6. A partir de aquí el dinero ya se movió: cualquier error debe decirlo para que nadie reintente
try {
    $pdo->beginTransaction();

    $devolucionUuid = null;
    if ($devolucionCts > 0) {
        $stmtIns = $pdo->prepare('
            INSERT INTO devoluciones
                (codigo_cliente, nombre_cliente, ci_cliente, monto_a_devolver, fecha_cobro_debito,
                 modo_devolucion, nro_envio, monto_debitado)
            VALUES
                (:codigo, :nombre, :ci, :monto, CURRENT_DATE, :modo, :nro, :debitado)
            RETURNING uuid
        ');
        $stmtIns->execute([
            ':codigo'   => $cliente['codigo'],
            ':nombre'   => $cliente['nombrecompleto'],
            ':ci'       => $cliente['ci'],
            ':monto'    => $devolucion,
            ':modo'     => 'Efectivo',
            ':nro'      => $numeroEnvio,
            ':debitado' => $monto,
        ]);
        $devolucionUuid = $stmtIns->fetchColumn();
    }

    // Con APORTE = 0 no hay pago al préstamo: la transferencia queda solo en "devoluciones"
    if ($aporteCts > 0) {
        $today = new DateTimeImmutable('today');

        if ((int) $pago['mes'] === 1) {
            $refDate = new DateTimeImmutable($pago['fecha_prestamo']);
        } else {
            $stmtPrev = $pdo->prepare('
                SELECT "fecha_de_debito" FROM "pago"
                WHERE "prestamoIdPrestamo" = :pid AND mes = :prevmes
                LIMIT 1
            ');
            $stmtPrev->execute([
                ':pid'     => $pago['prestamoIdPrestamo'],
                ':prevmes' => (int) $pago['mes'] - 1,
            ]);
            $prev    = $stmtPrev->fetch(PDO::FETCH_ASSOC);
            $refDate = new DateTimeImmutable(
                (!empty($prev['fecha_de_debito'])) ? $prev['fecha_de_debito'] : $pago['fecha_prestamo']
            );
        }

        $diasReal    = (int) $today->diff($refDate)->days;
        $tasaDiaria  = (float) $pago['tasa_interes'] / 100.0 / 30.0;
        $saldoIni    = (float) $pago['saldo_inicial'];
        $interesReal = round($saldoIni * $tasaDiaria * $diasReal, 2);
        $capitalReal = round(max(0.0, (float) $aporte - $interesReal), 2);
        $saldoReal   = round(max(0.0, $saldoIni - $capitalReal), 2);

        $sets = [
            '"nro_envio_transferencia" = :nro',
            '"transferencia"           = :aporte',
            '"estado"                  = \'pagado\'',
            '"fecha_de_debito"         = CURRENT_DATE',
            '"dias_real"               = :diasreal',
            '"interes_real"            = :interesreal',
            '"capital_real"            = :capitalreal',
            '"saldo_deudor_real"       = :saldoreal',
            '"updated_at"              = NOW()',
        ];
        $params = [
            ':nro'         => $numeroEnvio,
            ':aporte'      => $aporte,
            ':diasreal'    => $diasReal,
            ':interesreal' => $interesReal,
            ':capitalreal' => $capitalReal,
            ':saldoreal'   => $saldoReal,
            ':id'          => $pago['id_pago'],
        ];

        // Un débito anterior (flujo DEBITAR viejo) en esta cuota se conserva: la devolución
        // nueva igual queda en "devoluciones" con su N° de envío.
        if ($pago['debito'] === null) {
            // cuota_editada = APORTE para que el modal Pagos muestre débito − aporte = DEV
            $sets[] = '"debito"             = :debito';
            $sets[] = '"dev"                = :dev';
            $sets[] = '"cuota_editada"      = :cuotaed';
            $sets[] = '"nro_envio_debito"   = :nrodeb';
            $sets[] = '"fecha_debito_extra" = CURRENT_DATE';
            $params[':debito']  = $monto;
            $params[':dev']     = $devolucion;
            $params[':cuotaed'] = $aporte;
            $params[':nrodeb']  = $numeroEnvio;
            if ($devolucionUuid !== null) {
                $sets[] = '"devolucion_uuid" = :duuid';
                $params[':duuid'] = $devolucionUuid;
            }
        }

        $stmtUpd = $pdo->prepare('UPDATE "pago" SET ' . implode(', ', $sets) . ' WHERE "id_pago" = :id');
        $stmtUpd->execute($params);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonErr(
        "La transferencia de Bs. {$monto} SÍ se realizó (N° {$numeroEnvio}), pero no se pudo registrar: "
            . $e->getMessage() . '. NO reintentar: registrar a mano.',
        500,
        ['transferencia_realizada' => true, 'numero_envio' => $numeroEnvio]
    );
}

echo json_encode([
    'ok'              => true,
    'numero_envio'    => $numeroEnvio,
    'monto'           => $monto,
    'devolucion'      => $devolucion,
    'aporte'          => $aporte,
    'id_pago'         => $aporteCts > 0 ? $pago['id_pago'] : null,
    'devolucion_uuid' => $devolucionUuid,
]);
