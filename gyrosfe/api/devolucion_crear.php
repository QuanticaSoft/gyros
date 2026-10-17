<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
auth_require_login();
require_once __DIR__ . '/../lib/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

$idPago = trim((string) ($_POST['id_pago'] ?? ''));
if ($idPago === '') {
    echo json_encode(['ok' => false, 'error' => 'id_pago requerido']);
    exit;
}

try {
    $pdo = db_connect();
    $pdo->beginTransaction();

    // FOR UPDATE: dos clics simultáneos no deben crear dos devoluciones del mismo débito
    $stmtPago = $pdo->prepare('
        SELECT pg."debito", pg."cuota_fija", pg."cuota_editada",
               pg."fecha_debito_extra", pg."devolucion_uuid",
               cl.codigo, cl.nombrecompleto, cl.ci
        FROM "pago" pg
        JOIN "prestamo" pr ON pr."id_prestamo" = pg."prestamoIdPrestamo"
        JOIN "Cliente" cl  ON cl.uuid = pr."clienteIdCliente"
        WHERE pg."id_pago" = :id
        FOR UPDATE OF pg
    ');
    $stmtPago->execute([':id' => $idPago]);
    $pago = $stmtPago->fetch(PDO::FETCH_ASSOC);

    $error = null;
    if (!$pago) {
        $error = 'Pago no encontrado';
    } elseif ($pago['debito'] === null) {
        $error = 'La cuota no tiene débito registrado';
    } elseif ($pago['devolucion_uuid'] !== null) {
        $error = 'La devolución de este débito ya fue registrada';
    }

    if ($error === null) {
        $cuota = (float) ($pago['cuota_editada'] ?? $pago['cuota_fija']);
        $dev   = number_format((float) $pago['debito'] - $cuota, 2, '.', '');
        if ((float) $dev <= 0) {
            $error = 'No hay monto a devolver (DEV = Bs. ' . $dev . ')';
        }
    }

    if ($error !== null) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => $error]);
        exit;
    }

    $stmtIns = $pdo->prepare('
        INSERT INTO devoluciones
            (codigo_cliente, nombre_cliente, ci_cliente, monto_a_devolver, fecha_cobro_debito, modo_devolucion)
        VALUES
            (:codigo, :nombre, :ci, :monto, :fecha, :modo)
        RETURNING uuid
    ');
    $stmtIns->execute([
        ':codigo' => $pago['codigo'],
        ':nombre' => $pago['nombrecompleto'],
        ':ci'     => $pago['ci'],
        ':monto'  => $dev,
        ':fecha'  => $pago['fecha_debito_extra'],
        ':modo'   => 'Efectivo',
    ]);
    $devolucionUuid = $stmtIns->fetchColumn();

    $stmtUpd = $pdo->prepare('
        UPDATE "pago"
        SET "devolucion_uuid" = :duuid,
            "dev"             = :dev,
            "updated_at"      = NOW()
        WHERE "id_pago" = :id
    ');
    $stmtUpd->execute([':duuid' => $devolucionUuid, ':dev' => $dev, ':id' => $idPago]);

    // Débito del flujo anterior migrado a "transferencia": la pestaña TRANSFERENCIAS lee de ahí
    $stmtTr = $pdo->prepare('
        UPDATE transferencia
        SET devolucion_uuid = :duuid, devolucion = :dev, aporte = monto_debitado - :dev2
        WHERE id_pago = :id AND devolucion_uuid IS NULL AND devolucion > 0
    ');
    $stmtTr->execute([':duuid' => $devolucionUuid, ':dev' => $dev, ':dev2' => $dev, ':id' => $idPago]);

    $pdo->commit();
    echo json_encode(['ok' => true, 'uuid' => $devolucionUuid, 'monto_a_devolver' => $dev]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
