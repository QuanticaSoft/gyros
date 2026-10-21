-- Migración: baja del agente de desarrollo "dev"
-- Contexto: el host dev se quedó sin hardware (2026-10). Quedan cbb01 y
-- scz01, y cbb01 pasa a ser la fuente del código del agente. Reemplaza a
-- 2026_agent_dev.sql. Ejecutado manualmente via psql (ver
-- 2026_agent_tunnel_port.sql para el porqué).
--
-- "UsbDeviceState", "UsbEvent" y "Heartbeat" guardan "Agent".id en su
-- columna "agentId". Se borran las filas de dev de las tres: el estado USB
-- porque alimenta el ruteo de debitar.php / consulta_saldo.php, y el
-- historial porque es de un host que ya no existe y una FK hacia "Agent"
-- impediría borrar la fila del agente.
--
-- Aplicado: pendiente.

-- PREVIO (solo lectura): revisar antes de aplicar
-- SELECT conrelid::regclass AS tabla, conname, pg_get_constraintdef(oid)
--   FROM pg_constraint WHERE contype = 'f' AND confrelid = '"Agent"'::regclass;
-- SELECT a.id, a."tunnelPort",
--        (SELECT count(*) FROM "UsbDeviceState" s WHERE s."agentId" = a.id) AS estado_usb,
--        (SELECT count(*) FROM "UsbDeviceState" s WHERE s."agentId" = a.id AND s.status = 'connected') AS conectados,
--        (SELECT count(*) FROM "UsbEvent"       e WHERE e."agentId" = a.id) AS eventos,
--        (SELECT count(*) FROM "Heartbeat"      h WHERE h."agentId" = a.id) AS heartbeats
--   FROM "Agent" a WHERE a."agentId" = 'dev';

-- UP
BEGIN;
DELETE FROM "UsbDeviceState" WHERE "agentId" = (SELECT id FROM "Agent" WHERE "agentId" = 'dev');
DELETE FROM "UsbEvent"       WHERE "agentId" = (SELECT id FROM "Agent" WHERE "agentId" = 'dev');
DELETE FROM "Heartbeat"      WHERE "agentId" = (SELECT id FROM "Agent" WHERE "agentId" = 'dev');
DELETE FROM "Agent"          WHERE "agentId" = 'dev';
COMMIT;

-- DOWN (rollback manual, no probado en produccion; el historial borrado no se recupera)
-- INSERT INTO "Agent" ("agentId","token","hostname","tunnelPort","updatedAt")
-- VALUES ('dev', '<token generado con openssl rand -hex 32, no versionado>', 'dev', 8087, now());
