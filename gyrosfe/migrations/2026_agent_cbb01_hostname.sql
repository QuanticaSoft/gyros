-- Migración: nombre visible del agente de Cochabamba
-- Contexto: ui/main.php, ui/dashboard.php y agent/usb_events.php muestran
-- "Agent".hostname. Esa columna se escribe una sola vez, al dar de alta la
-- fila: ni heartbeat.php ni usb_event.php la actualizan. La fila de cbb01 se
-- creó con el hostname del sistema operativo de esa máquina (agent-01), por
-- lo que la UI mostraba "agent-01" donde scz01 muestra "scz01".
-- Solo cambia la etiqueta: la autenticación y el ruteo de débitos usan
-- "agentId" y "tunnelPort", no hostname. Ejecutado manualmente via psql
-- (ver 2026_agent_tunnel_port.sql para el porqué).
--
-- Aplicado: 2026-10-08.

-- UP
UPDATE "Agent" SET hostname = 'cbb01', "updatedAt" = now() WHERE "agentId" = 'cbb01';

-- DOWN (rollback manual, no probado en produccion)
-- UPDATE "Agent" SET hostname = 'agent-01', "updatedAt" = now() WHERE "agentId" = 'cbb01';
