"""
UNImóvil Plus — Flujo completo pasos 1 a 10
Uso: python3 -m union.main
"""

from union.config import load_config
from union.device import get_device
from union.steps import (
    paso1_lanzar_app,
    paso2_esperar_login,
    paso3_ingresar_usuario,
    paso4_clic_iniciar_sesion,
    paso5_esperar_pantalla_password,
    paso6_confirmar_imagen_seguridad,
    paso7_ingresar_password,
    paso8_leer_saldo,
    paso9_cerrar_sesion,
    paso10_cerrar_app,
)


def main() -> None:
    cfg = load_config()
    d = get_device()

    paso1_lanzar_app(d)
    paso2_esperar_login(d)
    paso3_ingresar_usuario(d, cfg.usuario)
    paso4_clic_iniciar_sesion(d)
    paso5_esperar_pantalla_password(d, cfg.nombre_titular)
    paso6_confirmar_imagen_seguridad(d)
    paso7_ingresar_password(d, cfg.password)
    saldo = paso8_leer_saldo(d)

    print(f"\n{'='*40}")
    print(f"  Saldo disponible: Bs. {saldo}")
    print(f"{'='*40}\n")

    paso9_cerrar_sesion(d)
    paso10_cerrar_app(d)


if __name__ == "__main__":
    main()
