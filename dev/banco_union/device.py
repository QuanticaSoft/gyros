import os
import subprocess
import uiautomator2 as u2


def get_device():
    """Verifica conexion ADB y retorna el dispositivo uiautomator2."""
    serial = os.getenv("BU_DISPOSITIVO")

    result = subprocess.run(
        ["adb", "devices"],
        capture_output=True,
        text=True
    )

    lines = [l.strip() for l in result.stdout.splitlines() if l.strip()]
    connected = [l for l in lines[1:] if l.endswith("\tdevice") or l.endswith(" device")]

    if not connected:
        raise RuntimeError(
            "No hay dispositivo Android conectado por USB.\n"
            "Verifica que:\n"
            "  1. El cable USB este conectado\n"
            "  2. La Depuracion USB este habilitada en el celular\n"
            "  3. Hayas aceptado el dialogo de confianza en el celular\n"
            "Ejecuta 'adb devices' para diagnosticar."
        )

    serials_conectados = [l.split()[0] for l in connected]

    if serial and serial in serials_conectados:
        print(f"Dispositivo conectado: {serial}")
    elif serial:
        raise RuntimeError(
            f"El dispositivo {serial!r} (BU_DISPOSITIVO) no esta conectado.\n"
            f"Dispositivos disponibles: {serials_conectados}"
        )
    else:
        serial = serials_conectados[0]
        print(f"Dispositivo conectado: {serial}")

    d = u2.connect(serial)

    return d
