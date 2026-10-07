"""
Pasos 1-8 del flujo de login en UNImóvil Plus (Banco Unión Bolivia).

Tiempos medidos de las capturas de pantalla:
  - Lanzar app → splash screen 1 : ~1 s
  - Splash 1   → splash 2 (logo circular) : ~2 s
  - Splash 2   → pantalla login (EditText visible) : ~3-4 s  → wait(timeout=15)
  - Clic "INICIAR SESIÓN" → pantalla contraseña : ~2-3 s   → sleep(2) + wait(timeout=10)
  - Clic "INICIAR SESIÓN" (contraseña) → dashboard : ~3-5 s → sleep(3) + wait(timeout=15)
"""

import time
import uiautomator2 as u2

APP_PACKAGE = "com.bancounion.unimovilplus"
BOTON_INICIAR = "INICIAR SESIÓN"


# ---------------------------------------------------------------------------
# Paso 1 — Encender pantalla y lanzar la app
# ---------------------------------------------------------------------------

def paso1_lanzar_app(d: u2.Device) -> None:
    """Enciende la pantalla y arranca UNImóvil Plus desde cero."""
    print("[PASO 1] Encendiendo pantalla y lanzando app...")
    d.screen_on()
    d.unlock()
    time.sleep(1)
    # Cerrar todo antes de lanzar: home -> force-stop -> kill background
    d.press("home")
    time.sleep(0.5)
    d.shell("am force-stop " + APP_PACKAGE)
    time.sleep(0.5)
    d.shell("am kill-all")
    time.sleep(1)
    d.app_start(APP_PACKAGE)
    time.sleep(2)
    print("[PASO 1] App lanzada.")


# ---------------------------------------------------------------------------
# Paso 2 — Esperar que los splash screens terminen y aparezca el login
# ---------------------------------------------------------------------------

def paso2_esperar_login(d: u2.Device) -> None:
    """
    Espera hasta que desaparezcan los dos splash screens y aparezca
    el campo de usuario (EditText) de la pantalla de login.

    Splash 1 (logo + fondo azul)  ~2 s
    Splash 2 (logo circular)       ~3 s
    Total estimado: 5 s → timeout conservador de 15 s
    """
    print("[PASO 2] Esperando pantalla de login...")
    campo = d(className="android.widget.EditText")
    if not campo.wait(timeout=30):
        raise RuntimeError(
            "[PASO 2] El campo de usuario no apareció en 15 s.\n"
            "La app puede estar colgada en el splash o no se instaló correctamente."
        )
    print("[PASO 2] Pantalla de login lista.")


# ---------------------------------------------------------------------------
# Paso 3 — Escribir el nombre de usuario
# ---------------------------------------------------------------------------

def paso3_ingresar_usuario(d: u2.Device, usuario: str) -> None:
    """Limpia el campo de usuario y escribe el valor configurado."""
    print(f"[PASO 3] Ingresando usuario: {usuario}")
    campo = d(className="android.widget.EditText")
    campo.clear_text()
    campo.set_text(usuario)
    print("[PASO 3] Usuario ingresado.")


# ---------------------------------------------------------------------------
# Paso 4 — Clic en el botón "INICIAR SESIÓN" (primera pantalla)
# ---------------------------------------------------------------------------

def paso4_clic_iniciar_sesion(d: u2.Device) -> None:
    """
    Hace clic en el botón INICIAR SESIÓN de la pantalla de usuario.
    Usa texto exacto; si no lo encuentra prueba variantes.
    """
    print("[PASO 4] Haciendo clic en INICIAR SESIÓN...")
    btn = d(text=BOTON_INICIAR)
    if btn.exists:
        btn.click()
        print("[PASO 4] Clic realizado.")
        return

    for variante in ["Iniciar sesión", "Ingresar", "Continuar", "Siguiente"]:
        btn = d(text=variante)
        if btn.exists:
            btn.click()
            print(f"[PASO 4] Clic realizado (variante: '{variante}').")
            return

    raise RuntimeError(
        "[PASO 4] No se encontró el botón INICIAR SESIÓN.\n"
        "Verifica que el campo de usuario esté lleno y la app esté en la pantalla correcta."
    )


# ---------------------------------------------------------------------------
# Paso 5 — Esperar la pantalla de contraseña
# ---------------------------------------------------------------------------

def paso5_esperar_pantalla_password(d: u2.Device, nombre_titular: str = "") -> None:
    """
    Espera que aparezca la pantalla de contraseña.
    Tiempo estimado tras el clic: ~2-3 s.

    Verifica por:
      1. El texto "INGRESAR CONTRASEÑA" (header de la pantalla)
      2. El nombre del titular si se proporcionó en BU_NOMBRE_TITULAR
    """
    print("[PASO 5] Esperando pantalla de contraseña...")
    time.sleep(2)  # pausa base antes del wait

    # Verificar por el header de la pantalla
    header = d(textContains="INGRESAR CONTRASEÑA")
    if header.wait(timeout=10):
        print("[PASO 5] Pantalla de contraseña detectada (header).")
        _verificar_titular(d, nombre_titular)
        return

    # Fallback: verificar que apareció un nuevo EditText (campo contraseña)
    campo_pwd = d(className="android.widget.EditText")
    if campo_pwd.wait(timeout=5):
        print("[PASO 5] Pantalla de contraseña detectada (EditText).")
        _verificar_titular(d, nombre_titular)
        return

    raise RuntimeError(
        "[PASO 5] La pantalla de contraseña no apareció en el tiempo esperado.\n"
        "Posibles causas: usuario incorrecto, timeout de red, o la app mostró un error."
    )


def _verificar_titular(d: u2.Device, nombre_titular: str) -> None:
    """Log de verificación: confirma que el nombre del titular coincide."""
    if not nombre_titular:
        return
    partes = nombre_titular.upper().split()
    # Busca al menos la primera palabra del nombre para no depender del orden exacto
    if partes and d(textContains=partes[0]).exists:
        print(f"[PASO 5] Titular verificado: {nombre_titular}")
    else:
        print(f"[PASO 5] ADVERTENCIA: no se encontró '{partes[0]}' en pantalla. "
              "Verifica BU_NOMBRE_TITULAR o que el usuario sea el correcto.")


# ---------------------------------------------------------------------------
# Paso 6 — Confirmar imagen de seguridad (checkbox)
# ---------------------------------------------------------------------------

def paso6_confirmar_imagen_seguridad(d: u2.Device) -> None:
    """
    Hace clic en el checkbox que confirma que la imagen/frase de seguridad
    mostrada es la que el usuario configuró, habilitando el botón de login.
    """
    print("[PASO 6] Confirmando imagen de seguridad...")

    checkbox = d(className="android.widget.CheckBox")
    if not checkbox.wait(timeout=5):
        raise RuntimeError(
            "[PASO 6] No se encontró el checkbox de imagen de seguridad.\n"
            "Verifica que la pantalla de contraseña esté visible."
        )

    if checkbox.info.get("checked", False):
        print("[PASO 6] Checkbox ya estaba marcado.")
    else:
        checkbox.click()
        time.sleep(0.5)
        if not d(className="android.widget.CheckBox", checked=True).exists:
            raise RuntimeError("[PASO 6] El checkbox no quedó marcado tras el clic.")
        print("[PASO 6] Checkbox marcado. Imagen de seguridad confirmada.")


# ---------------------------------------------------------------------------
# Paso 7 — Ingresar contraseña y hacer login final
# ---------------------------------------------------------------------------

def paso7_ingresar_password(d: u2.Device, password: str) -> None:
    """
    Escribe la contraseña, cierra el teclado emergente y hace clic en INICIAR SESIÓN.
    Tiempo estimado hasta el dashboard: ~3-5 s.
    """
    print("[PASO 7] Ingresando contraseña...")
    campo = d(className="android.widget.EditText")
    if not campo.wait(timeout=8):
        raise RuntimeError(
            "[PASO 7] No se encontró el campo de contraseña.\n"
            "Verifica que el paso 6 (checkbox) se completó correctamente."
        )
    campo.clear_text()
    campo.set_text(password)
    print("[PASO 7] Contraseña ingresada.")

    # Cerrar el teclado emergente para que el botón INICIAR SESIÓN quede visible
    d.hide_keyboard()
    time.sleep(0.5)
    print("[PASO 7] Teclado cerrado.")

    # Clic en INICIAR SESIÓN (segunda pantalla)
    btn = d(text=BOTON_INICIAR)
    if not btn.wait(timeout=5):
        raise RuntimeError("[PASO 7] No se encontró el botón INICIAR SESIÓN.")
    btn.click()
    print("[PASO 7] Clic en INICIAR SESIÓN. Esperando dashboard...")

    # Esperar que aparezca el dashboard
    time.sleep(3)
    indicadores = ["Consolidado", "Cuentas", "Bienvenido", "Saldo", "CAJA"]
    for texto in indicadores:
        if d(textContains=texto).wait(timeout=12):
            print(f"[PASO 7] Dashboard detectado ('{texto}'). Login exitoso.")
            return

    # Verificar si hay un dialogo de alerta post-login
    for txt in ["CONTINUAR", "Continuar", "Aceptar", "OK"]:
        btn_alerta = d(text=txt)
        if btn_alerta.exists:
            print(f"[PASO 7] Dialogo post-login cerrado: '{txt}'")
            btn_alerta.click()
            time.sleep(3)
            return

    raise RuntimeError(
        "[PASO 7] No se pudo confirmar el ingreso al dashboard.\n"
        "Posibles causas: contraseña incorrecta, sesión bloqueada o timeout de red."
    )


# ---------------------------------------------------------------------------
# Paso 8 — Leer el saldo disponible desde el dashboard
# ---------------------------------------------------------------------------

def paso8_leer_saldo(d: u2.Device) -> str:
    """
    Extrae el saldo de la cuenta de la pantalla de Consolidado de Cuentas.

    La app muestra el saldo como un número (ej: "5.11") dentro de un
    TextView justo debajo del número de cuenta y encima de "ÚLTIMOS 12 MOVIMIENTOS".
    """
    import re
    print("[PASO 8] Leyendo saldo disponible...")
    time.sleep(1)

    # Intentar por resourceId conocidos de la app
    selectores_id = [
        {"resourceIdMatches": ".*saldo.*"},
        {"resourceIdMatches": ".*balance.*"},
        {"resourceIdMatches": ".*amount.*"},
        {"resourceIdMatches": ".*monto.*"},
    ]
    for sel in selectores_id:
        elem = d(**sel)
        if elem.exists:
            texto = elem.get_text().strip()
            if texto and re.match(r"[\d,\.]+", texto):
                print(f"[PASO 8] Saldo encontrado (resourceId): {texto}")
                return texto

    # Buscar el TextView con formato numérico monetario en toda la jerarquía
    hierarchy = d.dump_hierarchy()
    montos = re.findall(r'text="(\d{1,3}(?:[,\.]\d{3})*(?:[,\.]\d{1,2})?)"', hierarchy)
    # Filtrar cadenas que sean claramente un monto (tienen punto o coma decimal)
    montos_validos = [m for m in montos if re.search(r'[,\.]\d{1,2}$', m)]
    if montos_validos:
        saldo = montos_validos[0]
        print(f"[PASO 8] Saldo encontrado (jerarquía XML): {saldo}")
        return saldo

    # Último recurso: cualquier número en la jerarquía
    todos = re.findall(r'text="(\d[\d,\.]+)"', hierarchy)
    if todos:
        saldo = todos[0]
        print(f"[PASO 8] Saldo encontrado (patrón numérico): {saldo}")
        return saldo

    raise RuntimeError(
        "[PASO 8] No se pudo extraer el saldo de la pantalla.\n"
        "Ejecuta: adb shell uiautomator dump y revisa el XML para encontrar el resourceId correcto."
    )


# ---------------------------------------------------------------------------
# Paso 9 — Cerrar sesión (botón off en la barra superior)
# ---------------------------------------------------------------------------

def paso9_cerrar_sesion(d: u2.Device) -> None:
    """
    Hace clic en el botón de logout (ícono ⏻) ubicado en la barra superior.
    Coordenadas identificadas en la jerarquía: bounds="[536,78][624,166]" → centro (580, 122).
    Si aparece un diálogo de confirmación, acepta.
    """
    print("[PASO 9] Cerrando sesión...")

    # Clic en el botón off por coordenadas (más confiable que selector sin content-desc)
    d.click(580, 122)
    time.sleep(1)

    # Manejar diálogo de confirmación si aparece
    for txt in ["Aceptar", "ACEPTAR", "Sí", "SI", "Salir", "SALIR", "OK", "Confirmar"]:
        btn = d(text=txt)
        if btn.exists:
            btn.click()
            print(f"[PASO 9] Confirmación de cierre de sesión: '{txt}'")
            time.sleep(2)
            break

    # Verificar que volvió a la pantalla de login
    if d(textContains="INICIAR SESIÓN CON").wait(timeout=8):
        print("[PASO 9] Sesión cerrada. Pantalla de login visible.")
    else:
        print("[PASO 9] ADVERTENCIA: no se pudo confirmar el cierre de sesión.")


# ---------------------------------------------------------------------------
# Paso 10 — Cerrar la aplicación completamente
# ---------------------------------------------------------------------------

def paso10_cerrar_app(d: u2.Device) -> None:
    """Detiene el proceso de UNImóvil Plus en el dispositivo."""
    print("[PASO 10] Cerrando la aplicación...")
    d.app_stop(APP_PACKAGE)
    time.sleep(1)
    print("[PASO 10] Aplicación cerrada.")
