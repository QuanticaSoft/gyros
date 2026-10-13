-- Migración: débito adicional y cuota editable por cuota (columna APORTE y modal Pagos)
-- Contexto: además del cobro de la cuota (transferencia / nro_envio_transferencia),
-- el operador puede lanzar un débito ACH extra, opcional y único por cuota.
-- Se guarda en la misma fila de "pago" y no altera estado ni valores reales
-- de amortización. "dev" = débito − cuota vigente al momento de debitar (la
-- cuota es editable en el dashboard, por eso se guarda el resultado).
-- "cuota_editada" es la cuota que el operador corrige en el modal Pagos; la
-- vigente es COALESCE(cuota_editada, cuota_fija) y cuota_fija sigue siendo la
-- programada del plan de amortización.
-- Debe aplicarse ANTES de desplegar el código que lo usa: la consulta
-- principal de ui/main.php lee "debito".
-- Ejecutar manualmente via psql (ver 2026_agent_tunnel_port.sql).
--
-- Aplicado: (pendiente)

-- UP
ALTER TABLE "pago"
    ADD COLUMN IF NOT EXISTS "debito"             NUMERIC(15,2),
    ADD COLUMN IF NOT EXISTS "dev"                NUMERIC(15,2),
    ADD COLUMN IF NOT EXISTS "cuota_editada"      NUMERIC(15,2),
    ADD COLUMN IF NOT EXISTS "nro_envio_debito"   TEXT,
    ADD COLUMN IF NOT EXISTS "fecha_debito_extra" DATE;

-- DOWN (rollback manual, no probado en produccion)
-- ALTER TABLE "pago"
--     DROP COLUMN IF EXISTS "debito",
--     DROP COLUMN IF EXISTS "dev",
--     DROP COLUMN IF EXISTS "cuota_editada",
--     DROP COLUMN IF EXISTS "nro_envio_debito",
--     DROP COLUMN IF EXISTS "fecha_debito_extra";
