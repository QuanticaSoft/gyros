-- Migración: rastro de la transferencia en "devoluciones" (botón ATM de la columna APORTE)
-- Contexto: el ATM del dashboard hace una sola transferencia por DEBITAR y la reparte en
-- APORTE (pago a la cuota pendiente) y DEVOLUCION (fila en "devoluciones"). Cuando
-- APORTE = 0 no se toca ninguna cuota, así que el N° de envío y el monto debitado solo
-- quedan aquí. Se llenan en toda devolución creada desde el ATM.
-- Debe aplicarse ANTES de desplegar el código que lo usa: api/debitar.php inserta estas columnas.
-- Ejecutar manualmente via psql (ver 2026_agent_tunnel_port.sql).
--
-- Aplicado: (pendiente).

-- UP
ALTER TABLE devoluciones
    ADD COLUMN IF NOT EXISTS nro_envio      TEXT,
    ADD COLUMN IF NOT EXISTS monto_debitado NUMERIC(15,2);

-- DOWN (rollback manual, no probado en produccion)
-- ALTER TABLE devoluciones
--     DROP COLUMN IF EXISTS nro_envio,
--     DROP COLUMN IF EXISTS monto_debitado;
