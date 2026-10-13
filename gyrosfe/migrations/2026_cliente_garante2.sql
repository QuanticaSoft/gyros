-- Migración: garantes opcionales y segundo garante en "Cliente"
-- Contexto: el registro de cliente ya no exige garante y admite hasta dos.
-- El primero sigue en garantenombre / garantecelular (dejan de ser NOT NULL,
-- si lo eran); el segundo va en garante2nombre / garante2celular. Un garante
-- sin cargar se guarda como NULL.
-- Debe aplicarse ANTES de desplegar el código que lo usa: la consulta
-- principal de ui/main.php lee las columnas nuevas.
-- Ejecutar manualmente via psql (ver 2026_agent_tunnel_port.sql).
--
-- Aplicado: 2026-10-13.

-- UP
ALTER TABLE "Cliente"
    ALTER COLUMN garantenombre  DROP NOT NULL,
    ALTER COLUMN garantecelular DROP NOT NULL,
    ADD COLUMN IF NOT EXISTS garante2nombre  VARCHAR(255),
    ADD COLUMN IF NOT EXISTS garante2celular VARCHAR(20);

-- DOWN (rollback manual, no probado en produccion)
-- No se restaura el NOT NULL de garantenombre / garantecelular: fallaria con
-- los clientes ya registrados sin garante.
-- ALTER TABLE "Cliente"
--     DROP COLUMN IF EXISTS garante2nombre,
--     DROP COLUMN IF EXISTS garante2celular;
