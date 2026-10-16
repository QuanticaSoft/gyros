"""
Captura de pantalla y jerarquia de UI cuando falla un paso.

Un "[PASO N] ..." en el journal dice donde se atoro la automatizacion, pero no
que mostraba la app (un aviso del banco, un dialogo inesperado). Antes de cerrar
la app se guarda un PNG y el XML de la jerarquia para poder verlo despues.

Las capturas muestran saldos y numeros de cuenta: el directorio se crea con
permisos 0700 y solo se conservan las MAX_CAPTURAS mas recientes. Vive fuera de
/opt/gyros/agent porque deploy.sh sincroniza esa ruta con rsync --delete.
"""

import os
import re
import traceback
from datetime import datetime
from pathlib import Path

DIRECTORIO_CAPTURAS = Path(os.getenv("BU_CAPTURAS_DIR", "/var/tmp/gyros-capturas"))
MAX_CAPTURAS = 30


def guardar_captura(d, operacion: str, dispositivo: str) -> None:
    """Nunca lanza: un fallo al capturar no debe cambiar la respuesta a gyrosfe."""
    if d is None:
        return
    try:
        DIRECTORIO_CAPTURAS.mkdir(mode=0o700, parents=True, exist_ok=True)
        serial = re.sub(r"[^A-Za-z0-9]", "", dispositivo) or "sin-serial"
        base = DIRECTORIO_CAPTURAS / f"{datetime.now():%Y%m%d-%H%M%S}_{serial}_{operacion}"

        png = base.with_suffix(".png")
        d.screenshot(str(png))
        base.with_suffix(".xml").write_text(d.dump_hierarchy(), encoding="utf-8")
        print(f"[CAPTURA] Pantalla del fallo guardada en {png} (+ .xml)")

        _podar_capturas_viejas()
    except Exception:
        print("[CAPTURA] No se pudo guardar la pantalla del fallo:")
        traceback.print_exc()


def _podar_capturas_viejas() -> None:
    # Una captura son dos archivos (.png y .xml) con el mismo nombre base
    bases = sorted({p.with_suffix("") for p in DIRECTORIO_CAPTURAS.glob("*_*_*.*")})
    for base in bases[:-MAX_CAPTURAS]:
        for sufijo in (".png", ".xml"):
            base.with_suffix(sufijo).unlink(missing_ok=True)
