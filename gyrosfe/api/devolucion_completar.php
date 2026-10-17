<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
auth_require_login();
require_once __DIR__ . '/../lib/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

const MODOS_DEVOLUCION = ['Efectivo', 'Transferencia'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

$uuid  = trim((string) ($_POST['uuid']  ?? ''));
$modo  = trim((string) ($_POST['modo']  ?? ''));
$fecha = trim((string) ($_POST['fecha'] ?? ''));

$error = null;
if ($uuid === '') {
    $error = 'uuid requerido';
} elseif (!in_array($modo, MODOS_DEVOLUCION, true)) {
    $error = 'Modo de devolución inválido';
} elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !checkdate((int) substr($fecha, 5, 2), (int) substr($fecha, 8, 2), (int) substr($fecha, 0, 4))) {
    $error = 'Fecha de devolución inválida';
}
if ($error !== null) {
    echo json_encode(['ok' => false, 'error' => $error]);
    exit;
}

try {
    $pdo = db_connect();
    $pdo->beginTransaction();

    // FOR UPDATE: dos operadores no deben cerrar la misma devolución con datos distintos
    $stmtDev = $pdo->prepare('SELECT estado FROM devoluciones WHERE uuid = :uuid FOR UPDATE');
    $stmtDev->execute([':uuid' => $uuid]);
    $estado = $stmtDev->fetchColumn();

    if ($estado === false) {
        $error = 'Devolución no encontrada';
    } elseif ($estado !== 'pendiente') {
        $error = 'La devolución ya está ' . $estado;
    }
    if ($error !== null) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => $error]);
        exit;
    }

    $stmtUpd = $pdo->prepare('
        UPDATE devoluciones
        SET estado = \'completado\', modo_devolucion = :modo, fecha_devolucion = :fecha
        WHERE uuid = :uuid
    ');
    $stmtUpd->execute([':modo' => $modo, ':fecha' => $fecha, ':uuid' => $uuid]);

    $pdo->commit();
    echo json_encode(['ok' => true]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
