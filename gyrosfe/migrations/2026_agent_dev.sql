-- Migración: alta del agente de desarrollo "dev"
-- Contexto: tercer agente, usado para probar features del agente antes de
-- promoverlos a cbb01 y scz01. Reporta a este mismo gyrosfe, así que un
-- teléfono conectado a ese host se rutea ahí igual que en producción.
-- Su túnel inverso usa el puerto remoto 8087. Ejecutado manualmente via psql
-- (ver 2026_agent_tunnel_port.sql para el porqué).
--
-- Aplicado: pendiente.

-- UP
INSERT INTO "Agent" ("agentId","token","hostname","tunnelPort","updatedAt")
VALUES ('dev', '<token generado con openssl rand -hex 32, no versionado>', 'dev', 8087, now());

-- DOWN (rollback manual, no probado en produccion)
-- DELETE FROM "Agent" WHERE "agentId" = 'dev';
