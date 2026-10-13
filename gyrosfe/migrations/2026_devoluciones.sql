-- Migración: tabla "devoluciones"
-- Contexto: registra los montos a devolver a un cliente (p. ej. el DEV que
-- resulta de un débito mayor a la cuota). Los datos del cliente se guardan
-- copiados (código, nombre, CI), no como referencia a "Cliente".
-- Ejecutar manualmente via psql (ver 2026_agent_tunnel_port.sql).
--
-- Aplicado: 2026-10-12.

-- UP
CREATE TABLE IF NOT EXISTS devoluciones (
    uuid               UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
    codigo_cliente     TEXT,
    nombre_cliente     TEXT,
    ci_cliente         TEXT,
    monto_a_devolver   NUMERIC(15,2) NOT NULL,
    fecha_cobro_debito DATE,
    modo_devolucion    TEXT,
    estado             TEXT          NOT NULL DEFAULT 'pendiente'
);

-- DOWN (rollback manual, no probado en produccion)
-- DROP TABLE IF EXISTS devoluciones;
