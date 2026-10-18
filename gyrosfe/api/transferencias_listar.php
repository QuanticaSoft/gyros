<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
auth_require_login();
require_once __DIR__ . '/../lib/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

$uuid = trim((string) ($_GET['uuid'] ?? ''));
if ($uuid === '') {
    echo json_encode(['ok' => false, 'error' => 'uuid requerido']);
    exit;
}

// Registro de transferencias del ATM para la pestaña TRANSFERENCIAS del modal Registro Cliente
try {
    $pdo  = db_connect();
    $stmt = $pdo->prepare('
        SELECT t.nro_operacion, t.fecha, t.monto_debitado, t.devolucion, t.aporte, t.nro_envio,
               t.devolucion_uuid, t.id_pago,
               d.estado           AS devolucion_estado,
               d.codigo_cliente, d.nombre_cliente, d.ci_cliente,
               d.monto_a_devolver, d.fecha_cobro_debito, d.modo_devolucion, d.fecha_devolucion, d.referencia
        FROM transferencia t
        LEFT JOIN devoluciones d ON d.uuid = t.devolucion_uuid
        WHERE t.cliente_id = :uuid
        ORDER BY t.nro_operacion ASC
    ');
    $stmt->execute([':uuid' => $uuid]);
    echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
