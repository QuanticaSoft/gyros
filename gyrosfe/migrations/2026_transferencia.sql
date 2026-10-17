-- Migración: tabla "transferencia" (registro de cada ATM) y cierre de devoluciones
-- Contexto: el ATM del dashboard ya no paga cuotas ni exige préstamo. Cada transferencia
-- (DEBITAR = APORTE + DEVOLUCION) queda en "transferencia"; su nro_operacion es el # único
-- global que muestra la pestaña TRANSFERENCIAS del modal Registro Cliente. "devoluciones.fecha_devolucion"
-- se llena al completar la devolución (estado 'pendiente' → 'completado').
-- id_pago solo lo llenan las filas migradas del flujo anterior: permite registrar todavía
-- la devolución de un débito viejo con api/devolucion_crear.php.
-- Debe aplicarse ANTES de desplegar el código que lo usa: api/debitar.php inserta en "transferencia".
-- Ejecutar manualmente via psql (ver 2026_agent_tunnel_port.sql), dentro de BEGIN/COMMIT,
-- revisando antes el resultado del SELECT de verificación del final.
--
-- Aplicado: (pendiente)

-- UP
-- cliente_id e id_pago toman el tipo real de "Cliente".uuid y "pago".id_pago
DO $$
DECLARE
    tipo_cliente TEXT;
    tipo_pago    TEXT;
BEGIN
    SELECT format_type(atttypid, atttypmod) INTO tipo_cliente
    FROM pg_attribute WHERE attrelid = '"Cliente"'::regclass AND attname = 'uuid';
    SELECT format_type(atttypid, atttypmod) INTO tipo_pago
    FROM pg_attribute WHERE attrelid = '"pago"'::regclass AND attname = 'id_pago';

    EXECUTE format('
        CREATE TABLE IF NOT EXISTS transferencia (
            nro_operacion   BIGSERIAL     PRIMARY KEY,
            cliente_id      %s            NOT NULL,
            fecha           DATE          NOT NULL DEFAULT CURRENT_DATE,
            monto_debitado  NUMERIC(15,2) NOT NULL,
            devolucion      NUMERIC(15,2) NOT NULL DEFAULT 0,
            aporte          NUMERIC(15,2) NOT NULL,
            nro_envio       TEXT,
            devolucion_uuid UUID,
            id_pago         %s,
            saldo_antes     NUMERIC(15,2),
            saldo_despues   NUMERIC(15,2),
            created_at      TIMESTAMPTZ   NOT NULL DEFAULT NOW()
        )', tipo_cliente, tipo_pago);
END $$;

CREATE INDEX IF NOT EXISTS transferencia_cliente_idx ON transferencia (cliente_id, nro_operacion);

ALTER TABLE devoluciones ADD COLUMN IF NOT EXISTS fecha_devolucion DATE;

-- Backfill: una fila por N° de envío ya cobrado, numeradas en orden cronológico
INSERT INTO transferencia
    (cliente_id, fecha, monto_debitado, devolucion, aporte, nro_envio, devolucion_uuid, id_pago,
     saldo_antes, saldo_despues)
SELECT t.cliente_id, t.fecha, t.monto, t.dev, t.aporte, t.nro, t.duuid, t.id_pago,
       (SELECT s.saldo FROM saldo s WHERE s.id_pago = t.id_pago_saldo AND s.tipo = 'antes'
         ORDER BY s.fecha_hora DESC LIMIT 1),
       (SELECT s.saldo FROM saldo s WHERE s.id_pago = t.id_pago_saldo AND s.tipo = 'despues'
         ORDER BY s.fecha_hora DESC LIMIT 1)
FROM (
    -- ATM actual: un solo débito reparte APORTE (cuota) y DEVOLUCION
    SELECT pr."clienteIdCliente" AS cliente_id, COALESCE(pg."fecha_de_debito", pg."fecha_debito_extra") AS fecha,
           pg."debito" AS monto, COALESCE(pg."dev", 0) AS dev, pg."transferencia" AS aporte,
           pg."nro_envio_transferencia" AS nro, pg."devolucion_uuid" AS duuid,
           pg."id_pago" AS id_pago_saldo,
           CASE WHEN false THEN pg."id_pago" END AS id_pago   -- NULL con el tipo de id_pago para el UNION
    FROM "pago" pg
    JOIN "prestamo" pr ON pr."id_prestamo" = pg."prestamoIdPrestamo"
    WHERE pg."nro_envio_transferencia" IS NOT NULL
      AND pg."debito" IS NOT NULL
      AND pg."nro_envio_debito" = pg."nro_envio_transferencia"

    UNION ALL
    -- Flujo anterior: cobro de la cuota por transferencia
    SELECT pr."clienteIdCliente", pg."fecha_de_debito",
           pg."transferencia", 0, pg."transferencia",
           pg."nro_envio_transferencia", NULL,
           pg."id_pago", NULL
    FROM "pago" pg
    JOIN "prestamo" pr ON pr."id_prestamo" = pg."prestamoIdPrestamo"
    WHERE pg."nro_envio_transferencia" IS NOT NULL
      AND pg."nro_envio_debito" IS DISTINCT FROM pg."nro_envio_transferencia"

    UNION ALL
    -- Flujo anterior: débito adicional (DEV = débito − cuota)
    SELECT pr."clienteIdCliente", pg."fecha_debito_extra",
           pg."debito", COALESCE(pg."dev", 0), pg."debito" - COALESCE(pg."dev", 0),
           pg."nro_envio_debito", pg."devolucion_uuid",
           NULL, pg."id_pago"
    FROM "pago" pg
    JOIN "prestamo" pr ON pr."id_prestamo" = pg."prestamoIdPrestamo"
    WHERE pg."debito" IS NOT NULL
      AND pg."nro_envio_debito" IS DISTINCT FROM pg."nro_envio_transferencia"

    UNION ALL
    -- ATM con APORTE = 0: la transferencia solo quedó en "devoluciones"
    SELECT cl.uuid, d.fecha_cobro_debito,
           d.monto_debitado, d.monto_a_devolver, d.monto_debitado - d.monto_a_devolver,
           d.nro_envio, d.uuid,
           NULL, NULL
    FROM devoluciones d
    JOIN "Cliente" cl ON lower(trim(cl.codigo)) = lower(trim(d.codigo_cliente))
    WHERE d.nro_envio IS NOT NULL
      AND d.monto_debitado IS NOT NULL
      AND NOT EXISTS (
          SELECT 1 FROM "pago" pg
          WHERE pg."nro_envio_transferencia" = d.nro_envio OR pg."nro_envio_debito" = d.nro_envio
      )
) t
ORDER BY t.fecha NULLS FIRST, t.nro;

-- Verificación (antes de COMMIT): cada N° de envío una sola vez y montos cuadrados
-- SELECT nro_operacion, cliente_id, fecha, monto_debitado, devolucion, aporte, nro_envio,
--        devolucion_uuid IS NOT NULL AS con_devolucion, id_pago IS NOT NULL AS flujo_anterior,
--        saldo_antes, saldo_despues
-- FROM transferencia ORDER BY nro_operacion;
-- SELECT nro_envio, count(*) FROM transferencia GROUP BY 1 HAVING count(*) > 1;

-- DOWN (rollback manual, no probado en produccion)
-- DROP TABLE IF EXISTS transferencia;
-- ALTER TABLE devoluciones DROP COLUMN IF EXISTS fecha_devolucion;
