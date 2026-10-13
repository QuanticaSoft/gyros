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

    $stmtPago = $pdo->prepare('SELECT "debito", "devolucion_uuid" FROM "pago" WHERE "id_pago" = :id');
    $stmtPago->execute([':id' => $idPago]);
    $pago = $stmtPago->fetch(PDO::FETCH_ASSOC);

    if (!$pago) {
        echo json_encode(['ok' => false, 'error' => 'Pago no encontrado']);
        exit;
    }
    // La cuota solo es editable entre el DEBITAR y la aceptación de su devolución
    if ($pago['debito'] === null) {
        echo json_encode(['ok' => false, 'error' => 'La cuota no tiene débito registrado']);
        exit;
    }
    if ($pago['devolucion_uuid'] !== null) {
        echo json_encode(['ok' => false, 'error' => 'La devolución ya fue registrada: no se puede editar']);
        exit;
    }

    // La cuota programada (cuota_fija) no se toca: el plan de amortización depende de ella.
    // DEV se recalcula porque es débito − cuota vigente.
    $stmt = $pdo->prepare('
        UPDATE "pago"
        SET "cuota_editada" = :cuota,
            "dev"           = "debito" - :cuota2,
            "updated_at"    = NOW()
        WHERE "id_pago" = :id
        RETURNING "cuota_editada", "dev"
    ');
    $stmt->execute([':cuota' => $cuota, ':cuota2' => $cuota, ':id' => $idPago]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'cuota' => $row['cuota_editada'], 'dev' => $row['dev']]);

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
