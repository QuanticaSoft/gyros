<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
auth_require_login();
require_once __DIR__ . '/../lib/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

if (!auth_puede_ver_estadisticas()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Sin permiso']);
    exit;
}

function fecha_valida(string $s): bool
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
    return $d !== false && $d->format('Y-m-d') === $s;
}

$desde   = trim((string) ($_GET['desde'] ?? date('Y-m-01')));
$hasta   = trim((string) ($_GET['hasta'] ?? date('Y-m-d')));
$agrupar = (string) ($_GET['agrupar'] ?? 'dia');

// Lista blanca: el valor va interpolado en date_trunc()
$truncs = ['dia' => 'day', 'mes' => 'month', 'anio' => 'year'];
if (!isset($truncs[$agrupar])) {
    echo json_encode(['ok' => false, 'error' => 'agrupar inválido']);
    exit;
}
if (!fecha_valida($desde) || !fecha_valida($hasta) || $desde > $hasta) {
    echo json_encode(['ok' => false, 'error' => 'Rango de fechas inválido']);
    exit;
}
$trunc = $truncs[$agrupar];

try {
    $pdo    = db_connect();
    $params = [':desde' => $desde, ':hasta' => $hasta];

    $stmt = $pdo->prepare("
        SELECT to_char(date_trunc('$trunc', fecha), 'YYYY-MM-DD') AS periodo,
               count(*)            AS debitos_cant,
               sum(monto_debitado) AS debitos_monto,
               sum(aporte)         AS aporte
        FROM transferencia
        WHERE fecha BETWEEN :desde AND :hasta
        GROUP BY 1
    ");
    $stmt->execute($params);
    $transferencias = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT to_char(date_trunc('$trunc', fecha_cobro_debito), 'YYYY-MM-DD') AS periodo,
               count(*)                                                 AS devol_cant,
               sum(monto_a_devolver)                                    AS devol_monto,
               count(*)              FILTER (WHERE estado = 'pendiente') AS devol_pend_cant,
               sum(monto_a_devolver) FILTER (WHERE estado = 'pendiente') AS devol_pend_monto
        FROM devoluciones
        WHERE fecha_cobro_debito BETWEEN :desde AND :hasta
        GROUP BY 1
    ");
    $stmt->execute($params);
    $devoluciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT to_char(date_trunc('$trunc', fecha_hora::date), 'YYYY-MM-DD') AS periodo,
               count(*) AS consultas
        FROM saldo
        WHERE fecha_hora::date BETWEEN :desde AND :hasta
        GROUP BY 1
    ");
    $stmt->execute($params);
    $consultas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $vacio = [
        'debitos_cant' => 0, 'debitos_monto' => 0.0, 'aporte' => 0.0,
        'devol_cant' => 0, 'devol_monto' => 0.0, 'devol_pend_cant' => 0, 'devol_pend_monto' => 0.0,
        'consultas' => 0,
    ];
    $series = [];
    foreach ([$transferencias, $devoluciones, $consultas] as $filas) {
        foreach ($filas as $fila) {
            $periodo = $fila['periodo'];
            $series[$periodo] ??= ['periodo' => $periodo] + $vacio;
            foreach ($fila as $campo => $valor) {
                if ($campo !== 'periodo') {
                    $series[$periodo][$campo] = is_int($vacio[$campo]) ? (int) $valor : (float) ($valor ?? 0);
                }
            }
        }
    }
    ksort($series);
    $series = array_values($series);

    $totales = $vacio;
    foreach ($series as $s) {
        foreach ($vacio as $campo => $_) {
            $totales[$campo] += $s[$campo];
        }
    }

    echo json_encode([
        'ok'      => true,
        'rango'   => ['desde' => $desde, 'hasta' => $hasta, 'agrupar' => $agrupar],
        'totales' => $totales,
        'series'  => $series,
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
