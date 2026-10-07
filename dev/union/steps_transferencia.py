"""
Pasos 11-18 del flujo de transferencia interbancaria ACH (Banco Union -> cuentaOficina).

Reutiliza el login (pasos 1-7 de union.steps) y termina con logout/cierre de app
(pasos 9-10 de union.steps). Este modulo solo cubre la navegacion y el formulario
de Transferencia ACH dentro del dashboard ya logueado.
"""

import re
import time
from datetime import datetime
from zoneinfo import ZoneInfo

import uiautomator2 as u2

MENU_TRANSFERENCIAS_INTERBANCARIAS = "Transferencias Interbancarias"
MENU_TRANSFERENCIA_ACH = "Transferencia ACH"

_TZ_BOLIVIA = ZoneInfo("America/La_Paz")


class FueraDeHorarioACH(Exception):
    """La ventana de horario ACH mostrada en pantalla no incluye la hora actual."""


class DestinatarioNoCoincide(Exception):
    """Los datos autocompletados del destinatario no coinciden con BU_OFICINA_*."""


# ---------------------------------------------------------------------------
# Paso 11 - Abrir menu y navegar a Transferencia ACH
# ---------------------------------------------------------------------------

def paso11_abrir_menu_transferencia_ach(d: u2.Device) -> None:
    print("[PASO 11] Abriendo menu...")
    menu_btn = d(className="android.widget.ImageButton", description="Open navigation drawer")
    if not menu_btn.exists:
        d.click(44, 96)
    else:
        menu_btn.click()
    time.sleep(1)

    item = d(textContains=MENU_TRANSFERENCIAS_INTERBANCARIAS)
    if not item.wait(timeout=8):
        raise RuntimeError("[PASO 11] No se encontro 'Transferencias Interbancarias' en el menu.")
    item.click()
    time.sleep(1)

    ach = d(textContains=MENU_TRANSFERENCIA_ACH)
    if not ach.wait(timeout=8):
        raise RuntimeError("[PASO 11] No se encontro 'Transferencia ACH' tras expandir el submenu.")
    ach.click()
    time.sleep(2)
    print("[PASO 11] En formulario de Transferencia ACH.")


# ---------------------------------------------------------------------------
# Paso 12 - Validar ventana de horario ACH mostrada en pantalla
# ---------------------------------------------------------------------------

def paso12_validar_horario_ach(d: u2.Device) -> None:
    print("[PASO 12] Validando horario ACH...")
    hierarchy = d.dump_hierarchy()
    match = re.search(
        r"desde\s+Hrs\.?\s*(\d{1,2}:\d{2}).{0,20}hasta\s+Hrs\.?\s*(\d{1,2}:\d{2})",
        hierarchy,
        re.IGNORECASE | re.DOTALL,
    )
    if not match:
        print("[PASO 12] ADVERTENCIA: no se encontro el texto de horario ACH. Continuando sin validar.")
        return

    inicio_str, fin_str = match.group(1), match.group(2)
    ahora = datetime.now(_TZ_BOLIVIA)
    inicio = ahora.replace(hour=int(inicio_str.split(":")[0]), minute=int(inicio_str.split(":")[1]), second=0, microsecond=0)
    fin = ahora.replace(hour=int(fin_str.split(":")[0]), minute=int(fin_str.split(":")[1]), second=0, microsecond=0)

    if not (inicio <= ahora <= fin):
        raise FueraDeHorarioACH(
            f"Fuera de horario ACH: disponible de {inicio_str} a {fin_str}, hora actual {ahora.strftime('%H:%M')}."
        )
    print(f"[PASO 12] Dentro de horario ACH ({inicio_str} - {fin_str}).")


# ---------------------------------------------------------------------------
# Paso 13 - Buscar destinatario por alias Y seleccionar banco destino
#
# El formulario tiene DOS secciones que se completan juntas:
#   1. "Buscar Destinatario" -> alias -> datos del beneficiario auto-llenan
#   2. "Banco:" dropdown     -> seleccionar banco destino
# Tras ambas acciones "Cuenta Destino" queda visible con el numero de cuenta.
# ---------------------------------------------------------------------------

def paso13_buscar_destinatario(d: u2.Device, alias: str, banco: str) -> None:
    print(f"[PASO 13] Ingresando alias '{alias}' en Buscar Destinatario...")

    campo_busqueda = d(className="android.widget.EditText")
    if not campo_busqueda.wait(timeout=8):
        raise RuntimeError("[PASO 13] No se encontro el campo 'Buscar Destinatario'.")
    campo_busqueda.click()
    campo_busqueda.set_text(alias)
    time.sleep(1.5)

    # Si aparece sugerencia/resultado, seleccionarlo
    resultado = d(textContains=alias)
    if resultado.wait(timeout=5):
        resultado.click()
        time.sleep(1.5)
        print(f"[PASO 13] Alias '{alias}' seleccionado del resultado.")

    # Seleccionar Banco destino desde el dropdown "Seleccionar"
    print(f"[PASO 13] Seleccionando banco: {banco}")
    spinner_banco = d(text="Seleccionar")
    if not spinner_banco.wait(timeout=6):
        spinner_banco = d(className="android.widget.Spinner")
    if not spinner_banco.wait(timeout=6):
        raise RuntimeError("[PASO 13] No se encontro el selector de Banco destino.")

    spinner_banco.click()
    time.sleep(1.5)

    opcion_banco = d(textContains=banco)
    if not opcion_banco.wait(timeout=8):
        raise RuntimeError(f"[PASO 13] No aparecio la opcion '{banco}' en el desplegable.")
    opcion_banco.click()
    time.sleep(2)
    print(f"[PASO 13] Banco '{banco}' seleccionado. Datos del beneficiario auto-completados.")


# ---------------------------------------------------------------------------
# Paso 14 - Verificar datos del beneficiario
# Tras alias + banco la pantalla muestra Cuenta Destino con el numero real.
# ---------------------------------------------------------------------------

def _cuenta_visible_en_hierarchy(cuenta: str, hierarchy: str) -> bool:
    """Busca cuenta completa o ultimos 6 digitos."""
    if cuenta in hierarchy:
        return True
    return cuenta[-6:] in hierarchy


def paso14_verificar_destinatario(d: u2.Device, cuenta_esperada: str, banco_esperado: str) -> None:
    """
    La app carga los datos del beneficiario de forma asincrona tras seleccionar el banco.
    Reintenta hasta 6 veces (6 s) para darle tiempo a renderizar la cuenta destino.
    """
    print("[PASO 14] Verificando datos del beneficiario en pantalla...")
    for intento in range(6):
        time.sleep(1)
        hierarchy = d.dump_hierarchy()
        cuenta_ok = _cuenta_visible_en_hierarchy(cuenta_esperada, hierarchy)
        banco_ok = banco_esperado.upper() in hierarchy.upper()
        if cuenta_ok and banco_ok:
            print(f"[PASO 14] Cuenta '{cuenta_esperada}' y banco '{banco_esperado}' verificados (intento {intento + 1}).")
            return
        print(f"[PASO 14] Intento {intento + 1}/6 — cuenta_ok={cuenta_ok}, banco_ok={banco_ok}. Reintentando...")

    raise DestinatarioNoCoincide(
        f"[PASO 14] La cuenta destino '{cuenta_esperada}' no aparece tras 6 intentos. Abortando por seguridad."
    )


# ---------------------------------------------------------------------------
# Paso 15 - Seleccionar cuenta de debito (origen)
#
# Hay un boton "Selecciona la Cuenta de Debito" debajo de los datos del
# beneficiario. Se hace scroll, se clickea el boton, y el dialog resultante
# muestra las cuentas propias. Se elige la primera cuenta real.
# ---------------------------------------------------------------------------

def _seleccionar_primera_cuenta_real(d: u2.Device) -> bool:
    """
    En el dialog de seleccion de cuenta debito:
    - La opcion placeholder tiene checked=True  (radio lleno  ●)
    - La cuenta real tiene     checked=False (radio vacio ○)
    Se busca el CheckedTextView con checked=False y se clickea.
    """
    # Metodo principal: el radio no seleccionado es la cuenta real
    no_seleccionado = d(className="android.widget.CheckedTextView", checked=False)
    if no_seleccionado.wait(timeout=4):
        try:
            texto = no_seleccionado.get_text() or "cuenta"
        except Exception:
            texto = "cuenta"
        no_seleccionado.click()
        time.sleep(1)
        print(f"[PASO 15] Cuenta seleccionada: {texto.strip()}")
        return True

    # Fallback: cualquier TextView con numero de cuenta (10-16 digitos)
    for elem in d(className="android.widget.TextView"):
        try:
            texto = elem.get_text() or ""
        except Exception:
            texto = ""
        if re.match(r"^\d{10,16}$", texto.strip()):
            elem.click()
            time.sleep(1)
            print(f"[PASO 15] Cuenta seleccionada (fallback): {texto.strip()}")
            return True
    return False


def paso15_seleccionar_cuenta_origen(d: u2.Device) -> None:
    print("[PASO 15] Seleccionando cuenta de debito (origen)...")

    # Hacer scrolls para traer el boton a pantalla (puede estar en hierarchy
    # pero fuera de la vista — click sobre elemento invisible no abre el dialog).
    TEXTOS_BTN = ("Selecciona la Cuenta", "Cuenta de D", "Cuenta para el D")

    def _btn_visible():
        """Retorna el boton solo si esta realmente visible en pantalla."""
        for texto in TEXTOS_BTN:
            elem = d(textContains=texto)
            if elem.exists:
                try:
                    info = elem.info
                    if info.get("visibleBounds") or info.get("bounds"):
                        return elem
                except Exception:
                    pass
        return None

    # Scroll incremental hasta que el boton sea visible
    btn = _btn_visible()
    for _ in range(5):
        if btn is not None:
            break
        d.swipe(540, 1200, 540, 900, steps=10)
        time.sleep(0.8)
        btn = _btn_visible()

    if btn is None:
        raise RuntimeError("[PASO 15] No se encontro el boton 'Selecciona la Cuenta de Debito'.")

    # Scroll adicional para centrar el boton en pantalla antes de clickear
    d.swipe(540, 1100, 540, 950, steps=8)
    time.sleep(0.5)
    btn.click()
    time.sleep(2)  # dar tiempo al dialog para abrirse

    # Verificar que el dialog aparecio antes de seleccionar
    if not d(className="android.widget.CheckedTextView").wait(timeout=5):
        # Intentar click de nuevo
        print("[PASO 15] Dialog no aparecio, reintentando click...")
        btn = _btn_visible()
        if btn:
            btn.click()
            time.sleep(2)

    # Seleccionar primera cuenta real del dialog
    if not _seleccionar_primera_cuenta_real(d):
        raise RuntimeError("[PASO 15] No se pudo seleccionar cuenta de debito del dialog.")
    time.sleep(0.5)


# ---------------------------------------------------------------------------
# Paso 16 - Ingresar monto, moneda y glosa
#
# Tras paso15 los campos Monto/Moneda/Glosa y CONTINUAR ya son visibles.
# El primer EditText visible es Monto; el ultimo EditText es Glosa.
# ---------------------------------------------------------------------------

def generar_glosa(fecha_pago) -> str:
    """Glosa sin caracteres especiales: 'DDMMYYYY' (ej. 17062026)."""
    return fecha_pago.strftime("%d%m%Y")


def _scroll_hasta_encontrar(d: u2.Device, selector, max_intentos: int = 5, y_inicio: int = 1100, y_fin: int = 800, steps: int = 8, pausa: float = 0.6):
    """Hace scroll (swipe y_inicio -> y_fin) hasta que el selector sea visible. Retorna el elemento o None."""
    if selector.exists:
        return selector
    for _ in range(max_intentos):
        d.swipe(540, y_inicio, 540, y_fin, steps=steps)
        time.sleep(pausa)
        if selector.exists:
            return selector
    return None


_RE_RELOJ = re.compile(r"\d{1,2}:\d{2}")


def _esperar_pantalla_estable(d: u2.Device, intentos: int = 6, pausa: float = 0.5) -> None:
    """
    Espera a que el WebView termine de renderizar tras una transicion de pantalla
    (p.ej. tras tocar CONTINUAR, la pantalla de Verificacion de Datos tarda en
    cargar). Compara dumps de jerarquia sucesivos hasta que dejen de cambiar.

    La pantalla de Verificacion de Datos incluye un contador "MM:SS Min. por
    cambiar" de la Clave Transaccional que se actualiza cada segundo, por lo
    que el dump nunca es identico al anterior si se compara literal. Se quita
    ese patron antes de comparar para detectar la verdadera estabilidad.
    """
    anterior = None
    for _ in range(intentos):
        actual = _RE_RELOJ.sub("", d.dump_hierarchy())
        if actual == anterior:
            return
        anterior = actual
        time.sleep(pausa)


def paso16_ingresar_monto_moneda_glosa(d: u2.Device, monto: str, glosa: str, moneda: str = "BOLIVIANOS") -> None:
    print(f"[PASO 16] Ingresando monto={monto}, moneda={moneda}, glosa='{glosa}'")

    # --- Campo Monto a Transferir ---
    # Scrolls incrementales hasta encontrar el label "Monto"
    label_monto = _scroll_hasta_encontrar(d, d(textContains="Monto"), max_intentos=6)
    if label_monto is None:
        raise RuntimeError("[PASO 16] No se encontro el campo Monto a Transferir.")

    # El EditText de Monto esta debajo del label — usar el primero vacio visible
    campos = d(className="android.widget.EditText")
    campo_monto = None
    for i in range(campos.count):
        try:
            texto = campos[i].get_text() or ""
        except Exception:
            texto = ""
        if not texto.strip():
            campo_monto = campos[i]
            break

    if campo_monto is None:
        raise RuntimeError("[PASO 16] No se encontro EditText vacio para Monto.")

    campo_monto.click()
    campo_monto.clear_text()
    campo_monto.set_text(str(monto))
    d.hide_keyboard()
    time.sleep(0.5)
    print(f"[PASO 16] Monto '{monto}' ingresado.")

    # --- Selector Moneda ---
    label_moneda = _scroll_hasta_encontrar(d, d(textContains="Seleccione la Moneda"), max_intentos=4)
    if label_moneda is not None:
        label_moneda.click()
        time.sleep(1)
        opcion_moneda = d(text=moneda.upper())
        if not opcion_moneda.wait(timeout=5):
            raise RuntimeError(f"[PASO 16] No se encontro la opcion de moneda '{moneda}'.")
        opcion_moneda.click()
        time.sleep(0.5)
        print(f"[PASO 16] Moneda '{moneda}' seleccionada.")

    # --- Campo Glosa ---
    # Después de Moneda, el foco queda en el spinner. Usamos TAB para pasarlo a Glosa.
    d.shell("input keyevent KEYCODE_TAB")
    time.sleep(0.5)

    # Buscar el EditText de Glosa: iterar todos los EditText y tomar el último vacío.
    # Monto ya tiene "1.50", Destinatario tiene "MARK", Glosa debe estar vacío.
    _scroll_hasta_encontrar(d, d(textContains="Glosa"), max_intentos=4)
    time.sleep(0.3)

    campos = d(className="android.widget.EditText")
    campo_glosa = None
    for i in range(campos.count):
        try:
            texto = campos[i].get_text() or ""
        except Exception:
            texto = ""
        if not texto.strip():
            campo_glosa = campos[i]  # no hacemos break: queremos el último vacío

    if campo_glosa is not None:
        campo_glosa.click()
        time.sleep(0.8)
        d.shell(f"input text {glosa}")
        time.sleep(0.3)
        d.hide_keyboard()
        time.sleep(0.3)
        print(f"[PASO 16] Glosa ingresada: {glosa}")
    else:
        # Fallback: si TAB ya movió el foco a Glosa, escribir directamente
        d.shell(f"input text {glosa}")
        time.sleep(0.3)
        d.hide_keyboard()
        time.sleep(0.3)
        print(f"[PASO 16] Glosa ingresada (foco TAB): {glosa}")

    print("[PASO 16] Formulario completo.")


# ---------------------------------------------------------------------------
# Paso 17 - Continuar -> Verificacion de datos -> Confirmar
# ---------------------------------------------------------------------------

def paso17_continuar_y_confirmar(d: u2.Device, cuenta_esperada: str, banco_esperado: str) -> None:
    print("[PASO 17] Tocando CONTINUAR...")
    # El foco queda en el campo Glosa tras paso 16 — moverlo con TAB antes de buscar el boton
    d.hide_keyboard()
    time.sleep(0.3)
    d.shell("input keyevent KEYCODE_TAB")
    time.sleep(0.5)

    # El boton CONTINUAR ya queda visible y con el foco (recuadro naranja) tras el TAB,
    # sin necesidad de scroll. Al estar enfocado por navegacion de teclado, se activa
    # con KEYCODE_DPAD_CENTER/ENTER en lugar de un tap por coordenadas.
    d.shell("input keyevent KEYCODE_DPAD_CENTER")
    time.sleep(2)

    header_verif = d(textContains="Verificacion de Datos")
    if not header_verif.exists:
        header_verif = d(textContains="Verificación de Datos")
    if not header_verif.exists:
        print("[PASO 17] DPAD_CENTER no avanzo la pantalla, reintentando con tap directo...")
        d(textContains="CONTINUAR").click()
        time.sleep(2)
        header_verif = d(textContains="Verificacion de Datos")
        if not header_verif.exists:
            header_verif = d(textContains="Verificación de Datos")

    if not header_verif.wait(timeout=8):
        raise RuntimeError("[PASO 17] No se llego a la pantalla de Verificacion de Datos.")

    # Segunda verificacion: en esta pantalla aparece el numero de cuenta completo
    hierarchy = d.dump_hierarchy()
    if not _cuenta_visible_en_hierarchy(cuenta_esperada, hierarchy) or banco_esperado.upper() not in hierarchy.upper():
        raise DestinatarioNoCoincide(
            "[PASO 17] La pantalla de Verificacion de Datos no coincide con la cuentaOficina esperada. "
            "Abortando sin confirmar."
        )

    # La pantalla de Verificacion de Datos tarda en terminar de renderizar
    # (es un WebView con Comision, Total a debitar y Clave Transaccional que
    # cargan tras el cambio de pantalla) — esperar a que se estabilice antes
    # de buscar el boton, si no el scroll arranca sobre contenido a medio cargar.
    _esperar_pantalla_estable(d)

    print("[PASO 17] Datos verificados. Tocando CONFIRMAR...")
    # Esta pantalla es mas larga que las anteriores (incluye Comision, Total a
    # debitar, Glosa y Clave Transaccional antes del boton), por lo que el
    # swipe corto por defecto de _scroll_hasta_encontrar no alcanza a revelarlo.
    # En produccion la app/red puede tardar mas que en pruebas interactivas en
    # terminar de renderizar el WebView, por lo que se dan mas intentos/tiempo.
    btn_confirmar = _scroll_hasta_encontrar(
        d, d(textContains="CONFIRMAR"), max_intentos=15, y_inicio=1300, y_fin=700, steps=10, pausa=1.0,
    )
    if btn_confirmar is None or not btn_confirmar.wait(timeout=12):
        # No se pudo confirmar: retroceder en vez de dejar el dispositivo a
        # medias en la pantalla de Verificacion de Datos (afectaria la
        # siguiente solicitud sobre el mismo dispositivo). No se ha tocado
        # CONFIRMAR en ningun momento, asi que es seguro retroceder.
        d.press("back")
        raise RuntimeError("[PASO 17] No se encontro el boton CONFIRMAR.")
    btn_confirmar.click()
    time.sleep(3)
    print("[PASO 17] Transferencia confirmada.")


# ---------------------------------------------------------------------------
# Paso 18 - Leer numero de envio del comprobante
# ---------------------------------------------------------------------------

def paso18_leer_numero_envio(d: u2.Device) -> dict:
    print("[PASO 18] Leyendo comprobante de confirmacion...")
    # "PENDIENTE DE CONFIRMACI" es prefijo comun a "CONFIRMACION"/"CONFIRMACIÓN",
    # evita depender de si la app acentua o no. La confirmacion ACH real puede
    # tardar varios segundos en responder, por eso se da un solo timeout largo
    # en vez de partirlo en dos intentos cortos.
    if not d(textContains="PENDIENTE DE CONFIRMACI").wait(timeout=20):
        raise RuntimeError("[PASO 18] No se confirmo la operacion (no aparecio el mensaje esperado).")

    hierarchy = d.dump_hierarchy()

    # Las etiquetas y sus valores aparecen como nodos de texto consecutivos
    # (igual que "Cuenta Destino:" -> "3501216118" en pantallas anteriores).
    # Buscamos el texto de la etiqueta "Envio"/"Envío" y tomamos el valor
    # numerico inmediatamente siguiente, en vez de tomar el primer numero
    # largo de todo el comprobante (eso capturaba la cuenta de origen).
    textos = [t.strip() for t in re.findall(r'text="([^"]*)"', hierarchy) if t.strip()]
    numero_envio = None
    for i, texto in enumerate(textos):
        if "envio" in texto.lower() or "envío" in texto.lower():
            if i + 1 < len(textos) and textos[i + 1].isdigit():
                numero_envio = textos[i + 1]
                break

    if not numero_envio:
        raise RuntimeError("[PASO 18] No se pudo extraer el numero de envio del comprobante.")

    print(f"[PASO 18] Numero de envio: {numero_envio}")
    return {"numero_envio": numero_envio}
