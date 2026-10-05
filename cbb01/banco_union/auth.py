import time
import uiautomator2 as u2

APP_PACKAGE = "com.bancounion.unimovilplus"
BOTON_TEXT  = "INICIAR SESIÓN"    # texto exacto del boton


def login(d: u2.Device, usuario: str, password: str) -> bool:
    """
    Inicia sesion en UNImovil Plus.
    Flujo de dos pasos con un solo EditText:
      1. Ingresar usuario -> INICIAR SESION
      2. Ingresar contrasena -> INICIAR SESION
    Maneja el dialogo ALERTA post-login si aparece.
    """
    d.screen_on()
    time.sleep(1)

    print("Iniciando app UNImovil Plus...")
    d.app_start(APP_PACKAGE, stop=True)

    # PASO 1: usuario
    campo = d(className="android.widget.EditText")
    campo.wait(timeout=20)
    campo.clear_text()
    campo.set_text(usuario)
    print("Usuario ingresado.")
    _click_boton(d)
    time.sleep(3)

    # PASO 2: contrasena (misma pantalla, mismo campo)
    campo2 = d(className="android.widget.EditText")
    if not campo2.wait(timeout=12):
        raise RuntimeError("No aparecio el campo de contrasena.")
    campo2.clear_text()
    campo2.set_text(password)
    print("Contrasena ingresada.")
    _click_boton(d)
    time.sleep(6)

    # Cerrar dialogo ALERTA si aparece (ej: "No existe sesion activa en Uninet")
    for txt in ["CONTINUAR", "Continuar", "Aceptar", "OK"]:
        btn = d(text=txt)
        if btn.exists:
            print(f"Dialogo cerrado: '{txt}'")
            btn.click()
            time.sleep(3)
            break

    # Verificar 2FA
    if d(textContains="Token").exists or d(textContains="SMS").exists:
        codigo = input(">>> 2FA requerido. Ingresa el codigo: ").strip()
        campo_2fa = d(className="android.widget.EditText")
        if campo_2fa.exists:
            campo_2fa.set_text(codigo)
        d(textContains="Confirmar").click()
        time.sleep(4)

    # Verificar dashboard
    if (d(textContains="Bienvenido").exists or d(textContains="Cuentas").exists
            or d(textContains="Saldo").exists or d(textContains="Inicio").exists):
        print("Login exitoso.")
        return True

    for msg in ["error", "incorrecto", "invalido", "bloqueado"]:
        elem = d(textContains=msg)
        if elem.exists:
            raise RuntimeError(f"Login fallido: {elem.get_text()}")

    print("Advertencia: no se pudo confirmar login. Continuando...")
    return True


def _click_boton(d: u2.Device):
    """Click exacto en el boton INICIAR SESION (text= exacto para no confundir con el label)."""
    # Texto exacto primero
    btn = d(text=BOTON_TEXT)
    if btn.exists:
        btn.click()
        return
    # Fallbacks
    for txt in ["Ingresar", "Continuar", "Siguiente", "Confirmar"]:
        btn = d(text=txt)
        if btn.exists:
            btn.click()
            return
    raise RuntimeError("No se encontro el boton de login.")
