-- Migración: referencia de la devolución (modal Devolución de la pestaña TRANSFERENCIAS)
-- Contexto: al completar una devolución el operador registra, además del modo
-- (Efectivo / Transferencia / QR) y la fecha, una referencia obligatoria (N° de
-- comprobante o recibo). Nullable: las devoluciones anteriores no la tienen; la
-- obligatoriedad la exige api/devolucion_completar.php.
-- Debe aplicarse ANTES de desplegar el código que lo usa: api/devolucion_completar.php actualiza esta columna.
-- Ejecutar manualmente via psql (ver 2026_agent_tunnel_port.sql).
--
-- Aplicado: pendiente.

-- UP
ALTER TABLE devoluciones ADD COLUMN IF NOT EXISTS referencia TEXT;

-- DOWN (rollback manual, no probado en produccion)
-- ALTER TABLE devoluciones DROP COLUMN IF EXISTS referencia;
