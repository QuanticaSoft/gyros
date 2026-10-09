import os
import subprocess
import uiautomator2 as u2


def get_device() -> u2.Device:
    """Verifica conexión ADB y retorna el dispositivo uiautomator2."""
    serial = os.getenv("BU_DISPOSITIVO")

    result = subprocess.run(
        ["adb", "devices"],
        capture_output=True,
        text=True,
    )

    lines = [l.strip() for l in result.stdout.splitlines() if l.strip()]
    connected = [l for l in lines[1:] if l.endswith("\tdevice") or l.endswith(" device")]

    if not connected:
        raise RuntimeError(
            "No hay dispositivo Android conectado por USB.\n"
            "Verifica:\n"
            "  1. Cable USB conectado\n"
            "  2. Depuración USB habilitada en el celular\n"
            "  3. Dialogo de confianza aceptado en el celular\n"
            "Ejecuta 'adb devices' para diagnosticar."
        )

    serials_conectados = [l.split()[0] for l in connected]

    if serial and serial in serials_conectados:
        print(f"[DEVICE] Conectado: {serial}")
    elif serial:
        raise RuntimeError(
            f"El dispositivo {serial!r} (BU_DISPOSITIVO) no está conectado.\n"
            f"Dispositivos disponibles: {serials_conectados}"
        )
    else:
        serial = serials_conectados[0]
        print(f"[DEVICE] Conectado (auto): {serial}")

    return u2.connect(serial)
