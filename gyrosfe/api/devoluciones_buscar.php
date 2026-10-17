<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
auth_require_login();
require_once __DIR__ . '/../lib/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

$q = trim((string) ($_GET['q'] ?? ''));
if ($q === '') {
    echo json_encode(['ok' => false, 'error' => 'CI o código requerido']);
    exit;
}

try {
    $pdo  = db_connect();
    $stmt = $pdo->prepare(
        'SELECT uuid, codigo_cliente, nombre_cliente, ci_cliente,
                monto_a_devolver, fecha_cobro_debito, modo_devolucion, estado, fecha_devolucion
         FROM devoluciones
         WHERE trim(ci_cliente) = :ci
            OR lower(trim(codigo_cliente)) = lower(:codigo)
         ORDER BY fecha_cobro_debito DESC NULLS LAST'
    );
    $stmt->execute([':ci' => $q, ':codigo' => $q]);
    echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
