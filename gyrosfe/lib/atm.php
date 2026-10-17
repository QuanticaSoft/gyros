<?php
declare(strict_types=1);

// Misma regla que es_banco_union del agente (union/config.py)
function esBancoUnion(string $banco): bool
{
    return str_contains(strtoupper(strtr($banco, ['ó' => 'o', 'Ó' => 'O'])), 'UNION');
}

/**
 * Motivo por el que el ATM no puede debitar a un cliente, o null si está listo.
 * La usan el dashboard (para deshabilitar el 🏧) y api/debitar.php (para no confiar en la UI).
 *
 * $cliente: columnas de "Cliente" (nombrecompleto, ci, numerocelular, sector, codigo, vtotarjeta,
 *           garantenombre, garantecelular, isActive).
 * $cuentaPago: null si no hay cuenta 'pago' activa; si no, ['banco' => string, 'credenciales' => bool].
 */
function atmMotivoBloqueo(array $cliente, ?array $cuentaPago): ?string
{
    if (!filter_var($cliente['isActive'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        return 'El cliente está inactivo';
    }

    $requeridos = [
        'nombrecompleto' => 'nombre',
        'ci'             => 'CI',
        'numerocelular'  => 'celular',
        'sector'         => 'sector',
        'codigo'         => 'código',
        'vtotarjeta'     => 'vto. tarjeta',
        'garantenombre'  => 'nombre del garante',
        'garantecelular' => 'celular del garante',
    ];
    $faltan = [];
    foreach ($requeridos as $campo => $etiqueta) {
        if (trim((string) ($cliente[$campo] ?? '')) === '') {
            $faltan[] = $etiqueta;
        }
    }
    if ($faltan) {
        return 'Faltan datos en GENERAL: ' . implode(', ', $faltan);
    }

    if ($cuentaPago === null) {
        return 'El cliente no tiene cuenta bancaria "pago" activa';
    }
    $banco = (string) ($cuentaPago['banco'] ?? '');
    if (!esBancoUnion($banco)) {
        return 'Débito desde ' . ($banco !== '' ? $banco : 'banco sin nombre') . ' aún no soportado: solo cuentas del Banco Unión';
    }
    if (!$cuentaPago['credenciales']) {
        return 'La cuenta "pago" no tiene USUARIO/KEY configurados';
    }
    return null;
}
