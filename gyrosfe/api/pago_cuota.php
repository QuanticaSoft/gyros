<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
auth_require_login();
require_once __DIR__ . '/../lib/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

$idPago = trim((string) ($_POST['id_pago'] ?? ''));
$cuota  = trim((string) ($_POST['cuota']   ?? ''));

if ($idPago === '' || $cuota === '') {
    echo json_encode(['ok' => false, 'error' => 'Parámetros requeridos']);
    exit;
}
if (!is_numeric($cuota) || (float) $cuota <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Cuota inválida']);
    exit;
}
$cuota = number_format((float) $cuota, 2, '.', '');

try {
    $pdo = db_connect();

    // La cuota programada (cuota_fija) no se toca: el plan de amortización depende de ella.
    // DEV se recalcula porque es débito − cuota vigente.
    $stmt = $pdo->prepare('
        UPDATE "pago"
        SET "cuota_editada" = :cuota,
            "dev"           = CASE WHEN "debito" IS NOT NULL THEN "debito" - :cuota2 ELSE "dev" END,
            "updated_at"    = NOW()
        WHERE "id_pago" = :id
        RETURNING "cuota_editada", "dev"
    ');
    $stmt->execute([':cuota' => $cuota, ':cuota2' => $cuota, ':id' => $idPago]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['ok' => false, 'error' => 'Pago no encontrado']);
        exit;
    }

    echo json_encode(['ok' => true, 'cuota' => $row['cuota_editada'], 'dev' => $row['dev']]);

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
