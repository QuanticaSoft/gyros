"""
HTTP server - consulta de saldo y debito (transferencia ACH) Banco Union (UNImovil Plus)

POST /consultar-saldo
  Body JSON: {"usuario": "...", "password": "...", "nombre_titular": "...", "dispositivo": "..."}
  Response:  {"ok": true,  "saldo": "1250.50"}
             {"ok": false, "error": "motivo"}

POST /debitar
  Body JSON: {"usuario": "...", "password": "...", "nombre_titular": "...", "dispositivo": "...",
              "monto": "132.70", "fecha_pago": "2026-03-15"}
  Response:  {"ok": true,  "numero_envio": "1014202606152043...", "monto": "132.70"}
             {"ok": false, "error": "motivo"}

Concurrencia: un lock por serial ADB - dispositivos distintos corren en paralelo;
el mismo dispositivo no puede atender dos solicitudes simultaneas (saldo o debito).
"""

import threading
from datetime import datetime

import uiautomator2 as u2
from flask import Flask, jsonify, request

from union.config import load_oficina_config
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
from union.steps_transferencia import (
    DestinatarioNoCoincide,
    FueraDeHorarioACH,
    generar_glosa,
    paso11_abrir_menu_transferencia_ach,
    paso12_validar_horario_ach,
    paso13_buscar_destinatario,
    paso14_verificar_destinatario,
    paso15_seleccionar_cuenta_origen,
    paso16_ingresar_monto_moneda_glosa,
    paso17_continuar_y_confirmar,
    paso18_leer_numero_envio,
)

app = Flask(__name__)

# Lock por serial ADB: permite operaciones paralelas en dispositivos distintos
_device_locks: dict[str, threading.Lock] = {}
_locks_mutex = threading.Lock()


def _get_device_lock(serial: str) -> threading.Lock:
    with _locks_mutex:
        if serial not in _device_locks:
            _device_locks[serial] = threading.Lock()
        return _device_locks[serial]


@app.route("/consultar-saldo", methods=["POST"])
def consultar_saldo():
    data           = request.get_json(force=True, silent=True) or {}
    usuario        = (data.get("usuario")        or "").strip()
    password       = (data.get("password")       or "").strip()
    nombre_titular = (data.get("nombre_titular") or "").strip()
    dispositivo    = (data.get("dispositivo")    or "").strip()

    if not usuario or not password or not dispositivo:
        return jsonify({"ok": False, "error": "Faltan campos: usuario, password, dispositivo"}), 400

    lock = _get_device_lock(dispositivo)
    if not lock.acquire(blocking=False):
        return jsonify({"ok": False, "error": f"Dispositivo {dispositivo} ocupado, reintente en unos segundos"}), 503

    try:
        d = u2.connect(dispositivo)

        paso1_lanzar_app(d)
        paso2_esperar_login(d)
        paso3_ingresar_usuario(d, usuario)
        paso4_clic_iniciar_sesion(d)
        paso5_esperar_pantalla_password(d, nombre_titular)
        paso6_confirmar_imagen_seguridad(d)
        paso7_ingresar_password(d, password)
        saldo = paso8_leer_saldo(d)
        paso9_cerrar_sesion(d)
        paso10_cerrar_app(d)

        return jsonify({"ok": True, "saldo": saldo})

    except Exception as exc:
        return jsonify({"ok": False, "error": str(exc)}), 500

    finally:
        lock.release()


@app.route("/debitar", methods=["POST"])
def debitar():
    data           = request.get_json(force=True, silent=True) or {}
    usuario        = (data.get("usuario")        or "").strip()
    password       = (data.get("password")       or "").strip()
    nombre_titular = (data.get("nombre_titular") or "").strip()
    dispositivo    = (data.get("dispositivo")    or "").strip()
    monto          = (data.get("monto")          or "").strip()
    fecha_pago_str = (data.get("fecha_pago")     or "").strip()

    if not usuario or not password or not dispositivo or not monto or not fecha_pago_str:
        return jsonify({
            "ok": False,
            "error": "Faltan campos: usuario, password, dispositivo, monto, fecha_pago",
        }), 400

    try:
        fecha_pago = datetime.strptime(fecha_pago_str, "%Y-%m-%d").date()
    except ValueError:
        return jsonify({"ok": False, "error": "fecha_pago debe tener formato YYYY-MM-DD"}), 400

    oficina = load_oficina_config()
    glosa = generar_glosa(fecha_pago)

    lock = _get_device_lock(dispositivo)
    if not lock.acquire(blocking=False):
        return jsonify({"ok": False, "error": f"Dispositivo {dispositivo} ocupado, reintente en unos segundos"}), 503

    try:
        d = u2.connect(dispositivo)

        paso1_lanzar_app(d)
        paso2_esperar_login(d)
        paso3_ingresar_usuario(d, usuario)
        paso4_clic_iniciar_sesion(d)
        paso5_esperar_pantalla_password(d, nombre_titular)
        paso6_confirmar_imagen_seguridad(d)
        paso7_ingresar_password(d, password)

        paso11_abrir_menu_transferencia_ach(d)
        paso12_validar_horario_ach(d)
        paso13_buscar_destinatario(d, oficina.alias, oficina.banco)
        paso14_verificar_destinatario(d, oficina.cuenta, oficina.banco)
        paso15_seleccionar_cuenta_origen(d)
        paso16_ingresar_monto_moneda_glosa(d, monto, glosa, oficina.moneda)
        paso17_continuar_y_confirmar(d, oficina.cuenta, oficina.banco)
        resultado = paso18_leer_numero_envio(d)

        paso9_cerrar_sesion(d)
        paso10_cerrar_app(d)

        return jsonify({"ok": True, "numero_envio": resultado["numero_envio"], "monto": monto})

    except (FueraDeHorarioACH, DestinatarioNoCoincide) as exc:
        return jsonify({"ok": False, "error": str(exc)}), 409

    except Exception as exc:
        return jsonify({"ok": False, "error": str(exc)}), 500

    finally:
        lock.release()


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=8080, threaded=True)
