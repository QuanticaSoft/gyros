"""
Pasos 21-24 del flujo de transferencia a terceros (Banco Union -> cuentaOficina
en el mismo Banco Union).

Reemplaza a los pasos 11-14 y 17-18 de union.steps_transferencia cuando la
cuentaOficina es del Banco Union: la app no ofrece al propio banco como destino
de una Transferencia ACH. La cuenta de origen, el monto, la moneda y la glosa
se cargan con los mismos pasos 15 y 16 de ese modulo, porque el formulario usa
los mismos campos.

La app solo transfiere a cuentas de terceros previamente agregadas: el paso 22
agrega la cuentaOficina la primera vez que se debita desde un telefono.
"""

import re
import time

import uiautomator2 as u2

from union.steps import aceptar_dialogo_permiso
from union.steps_transferencia import (
    DestinatarioNoCoincide,
    _esperar_pantalla_estable,
    _scroll_hasta_encontrar,
    _valor_tras_etiqueta,
)

MENU_TRANSFERENCIAS_TERCEROS = "Transferencias a Terceros"
MENU_TRANSFERENCIA_TERCEROS = "Transferencia a Terceros"
MENU_ADMINISTRACION_CUENTAS = "Administración de Cuentas"

TEXTO_CLAVE_TRANSACCIONAL = "Clave Transaccional"

# La app muestra al titular con los apellidos primero y recorta el nombre (26
# caracteres vistos), asi que se compara palabra por palabra, sin orden y por
# prefijo, y se exige un minimo de palabras para que la coincidencia signifique algo.
_MIN_PALABRAS_TITULAR = 2

_ETIQUETAS_COMPROBANTE = ("envio", "envío", "transaccion", "transacción", "operacion", "operación", "comprobante")


def _textos(hierarchy: str) -> list[str]:
    return [t.strip() for t in re.findall(r'text="([^"]*)"', hierarchy) if t.strip()]


def _abrir_opcion_terceros(d: u2.Device, opcion: str, paso: int) -> None:
    """Abre el menu lateral y entra a una opcion del submenu Transferencias a Terceros."""
    menu_btn = d(className="android.widget.ImageButton", description="Open navigation drawer")
    if menu_btn.exists:
        menu_btn.click()
    else:
        d.click(44, 96)
    time.sleep(1)

    # El submenu queda desplegado si ya se uso en esta sesion: tocar de nuevo
    # el titulo lo colapsaria.
    item = d(text=opcion)
    if not item.exists:
        submenu = d(text=MENU_TRANSFERENCIAS_TERCEROS)
        if not submenu.wait(timeout=8):
            raise RuntimeError(f"[PASO {paso}] No se encontro '{MENU_TRANSFERENCIAS_TERCEROS}' en el menu.")
        submenu.click()
        time.sleep(1)
    if not item.wait(timeout=8):
        raise RuntimeError(f"[PASO {paso}] No se encontro '{opcion}' en el submenu.")
    item.click()
    time.sleep(2)


def _cuenta_destino_en_formulario(d: u2.Device) -> str:
    """Numero de la Cuenta Destino que muestra el formulario; vacio si no hay ninguna."""
    etiqueta = d(resourceId="CuentaDestinoLabel")
    if not etiqueta.exists:
        return ""
    texto = (etiqueta.get_text() or "").strip()
    return texto.split()[0] if texto else ""


def _titular_coincide(mostrado: str, esperado: str) -> bool:
    palabras_mostradas = mostrado.upper().split()
    palabras_esperadas = esperado.upper().split()
    if len(palabras_mostradas) < _MIN_PALABRAS_TITULAR:
        return False
    return all(any(e.startswith(m) for e in palabras_esperadas) for m in palabras_mostradas)


# ---------------------------------------------------------------------------
# Paso 21 - Abrir el formulario Transferencia a Terceros
# ---------------------------------------------------------------------------

def paso21_abrir_transferencia_terceros(d: u2.Device) -> None:
    print("[PASO 21] Abriendo Transferencia a Terceros...")
    _abrir_opcion_terceros(d, MENU_TRANSFERENCIA_TERCEROS, paso=21)
    if not d(textContains="Transferir a Cuentas de Terceros").wait(timeout=8):
        raise RuntimeError("[PASO 21] No se llego al formulario de Transferencia a Terceros.")
    print("[PASO 21] En formulario de Transferencia a Terceros.")


# ---------------------------------------------------------------------------
# Paso 22 - Dejar la cuentaOficina como destinatario del formulario
#
# Con una sola cuenta de terceros agregada el formulario ya la trae elegida.
# Si el telefono no la tiene, se agrega en Administracion de Cuentas (numero de
# cuenta + vencimiento Indefinido + Clave Transaccional, que la app rellena
# sola) y se vuelve al formulario.
# ---------------------------------------------------------------------------

def _agregar_cuenta_tercero(d: u2.Device, cuenta: str) -> None:
    print("[PASO 22] La cuenta oficina no esta agregada en este telefono. Agregandola...")
    _abrir_opcion_terceros(d, MENU_ADMINISTRACION_CUENTAS, paso=22)
    if not d(textContains="transferencias a terceros").wait(timeout=8):
        raise RuntimeError("[PASO 22] No se llego a Administracion de cuentas de terceros.")

    if cuenta in d.dump_hierarchy():
        print("[PASO 22] La cuenta ya figuraba en Administracion de cuentas.")
        return

    agregar = d(resourceId="btn-accion")
    if not agregar.wait(timeout=6):
        raise RuntimeError("[PASO 22] No se encontro el boton 'Agregar Cuenta'.")
    agregar.click()

    campo = d(resourceId="NumeroCuenta")
    if not campo.wait(timeout=8):
        raise RuntimeError("[PASO 22] No se encontro el campo 'Numero de cuenta a adicionar'.")
    campo.click()
    campo.set_text(cuenta)
    time.sleep(0.5)
    if (campo.get_text() or "").strip() != cuenta:
        raise RuntimeError("[PASO 22] El numero de cuenta no quedo bien escrito. Abortando el alta.")
    d.hide_keyboard()
    time.sleep(0.5)

    # El WebView no expone si la casilla quedo marcada: se marca una vez (el
    # formulario abre desmarcado) y se comprueba en la pantalla de confirmacion.
    indefinido = d(resourceId="Indefinido")
    if not indefinido.wait(timeout=6):
        raise RuntimeError("[PASO 22] No se encontro la casilla 'Indefinido'.")
    indefinido.click()
    time.sleep(0.5)

    d(resourceId="btn-continuar").click()
    if not d(textContains="Confirmación de cuenta").wait(timeout=10):
        raise RuntimeError("[PASO 22] No se llego a la confirmacion del alta de la cuenta.")

    hierarchy = d.dump_hierarchy()
    cuenta_en_pantalla = _valor_tras_etiqueta(hierarchy, "Número de cuenta a adicionar")
    indefinido_en_pantalla = _valor_tras_etiqueta(hierarchy, "Indefinido")
    if cuenta_en_pantalla != cuenta or indefinido_en_pantalla.upper() != "SI":
        d(resourceId="btn-cancelar").click()
        raise DestinatarioNoCoincide(
            "[PASO 22] La confirmacion del alta no coincide con la cuentaOficina. Alta cancelada."
        )

    d(resourceId="btn-confirmar").click()
    if not d(textContains="Detalle de cuentas habilitadas").wait(timeout=15) or cuenta not in d.dump_hierarchy():
        raise RuntimeError("[PASO 22] No se pudo confirmar que la cuenta oficina quedo agregada.")
    print("[PASO 22] Cuenta oficina agregada.")


def _elegir_tercero_por_titular(d: u2.Device, titular: str) -> None:
    """Con varias cuentas de terceros, elige en el desplegable la del titular de la oficina."""
    desplegable = d(resourceId="CuentaTerceros_CuentaTercero")
    if not titular or not desplegable.exists:
        return
    desplegable.click()
    time.sleep(1.5)
    opciones = d(className="android.widget.CheckedTextView")
    for i in range(opciones.count):
        if _titular_coincide(opciones[i].get_text() or "", titular):
            opciones[i].click()
            time.sleep(1.5)
            return
    d.press("back")
    time.sleep(1)


def paso22_asegurar_destinatario(d: u2.Device, cuenta: str, titular: str = "") -> None:
    print("[PASO 22] Verificando la cuenta destino del formulario...")
    if _cuenta_destino_en_formulario(d) != cuenta:
        _elegir_tercero_por_titular(d, titular)

    if _cuenta_destino_en_formulario(d) != cuenta:
        _agregar_cuenta_tercero(d, cuenta)
        paso21_abrir_transferencia_terceros(d)
        if _cuenta_destino_en_formulario(d) != cuenta:
            _elegir_tercero_por_titular(d, titular)

    if _cuenta_destino_en_formulario(d) != cuenta:
        raise DestinatarioNoCoincide(
            f"[PASO 22] La cuenta destino del formulario no es la cuentaOficina '{cuenta}'. Abortando por seguridad."
        )

    if titular:
        mostrado = d(resourceId="CuentaTerceros_CuentaTercero").get_text() or ""
        if not _titular_coincide(mostrado, titular):
            raise DestinatarioNoCoincide(
                f"[PASO 22] El titular de la cuenta destino ('{mostrado.strip()}') no coincide con BU_OFICINA_TITULAR."
            )
    print(f"[PASO 22] Cuenta destino '{cuenta}' verificada.")


# ---------------------------------------------------------------------------
# Paso 23 - Continuar -> pantalla de confirmacion -> Confirmar
# ---------------------------------------------------------------------------

def paso23_continuar_y_confirmar(d: u2.Device, cuenta_esperada: str) -> None:
    print("[PASO 23] Tocando CONTINUAR...")
    d.hide_keyboard()
    time.sleep(0.3)
    btn_continuar = _scroll_hasta_encontrar(d, d(resourceId="btn-continuar"), max_intentos=6)
    if btn_continuar is None:
        raise RuntimeError("[PASO 23] No se encontro el boton CONTINUAR.")
    btn_continuar.click()
    time.sleep(2)

    # La pantalla de confirmacion es la unica que pide la Clave Transaccional.
    clave = d(textContains=TEXTO_CLAVE_TRANSACCIONAL)
    if not clave.exists and aceptar_dialogo_permiso(d):
        clave.wait(timeout=8)
    if not clave.wait(timeout=10):
        raise RuntimeError("[PASO 23] No se llego a la pantalla de confirmacion de la transferencia.")

    _esperar_pantalla_estable(d)

    # Segunda verificacion antes de mover dinero: la cuentaOficina completa debe
    # figurar en la pantalla de confirmacion.
    if not any(cuenta_esperada in texto for texto in _textos(d.dump_hierarchy())):
        d.press("back")
        raise DestinatarioNoCoincide(
            "[PASO 23] La pantalla de confirmacion no muestra la cuentaOficina esperada. Abortando sin confirmar."
        )

    print("[PASO 23] Datos verificados. Tocando CONFIRMAR...")
    btn_confirmar = _scroll_hasta_encontrar(
        d, d(resourceId="btn-confirmar"), max_intentos=15, y_inicio=1300, y_fin=700, steps=10, pausa=1.0,
    )
    if btn_confirmar is None or not btn_confirmar.wait(timeout=12):
        # No se ha tocado CONFIRMAR en ningun momento, asi que es seguro retroceder.
        d.press("back")
        raise RuntimeError("[PASO 23] No se encontro el boton CONFIRMAR.")
    btn_confirmar.click()
    time.sleep(3)
    print("[PASO 23] Transferencia confirmada.")


# ---------------------------------------------------------------------------
# Paso 24 - Leer el numero de operacion del comprobante
# ---------------------------------------------------------------------------

def _numero_de_comprobante(textos: list[str]) -> str:
    for i, texto in enumerate(textos[:-1]):
        etiqueta = texto.lower()
        if "transaccional" in etiqueta or not any(e in etiqueta for e in _ETIQUETAS_COMPROBANTE):
            continue
        valor = textos[i + 1]
        if valor.isdigit() and len(valor) >= 4:
            return valor
    return ""


def paso24_leer_comprobante(d: u2.Device) -> dict:
    print("[PASO 24] Leyendo comprobante...")
    textos: list[str] = []
    for _ in range(25):
        textos = _textos(d.dump_hierarchy())
        en_confirmacion = any(TEXTO_CLAVE_TRANSACCIONAL in t for t in textos)
        numero = "" if en_confirmacion else _numero_de_comprobante(textos)
        if numero:
            print(f"[PASO 24] Numero de operacion: {numero}")
            return {"numero_envio": numero}
        time.sleep(1)

    # Lo que quedo en pantalla es la unica pista de si la transferencia se hizo.
    print("[PASO 24] Textos en pantalla al no hallar el numero de operacion:")
    for texto in textos:
        print(f"[PASO 24]   {texto}")
    raise RuntimeError("[PASO 24] No se pudo extraer el numero de operacion del comprobante.")
