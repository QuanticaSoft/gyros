import time
import uiautomator2 as u2


def get_balance(d: u2.Device) -> str:
    """
    Navega a la seccion de cuentas y extrae el saldo disponible.
    Retorna el saldo como string (ej: "1,234.56").

    Nota: los selectores exactos dependen de la version de la app.
    Si falla, ejecuta d.dump_hierarchy() con la app en la pantalla
    de saldo para obtener los resourceId correctos.
    """
    print("Navegando a seccion de cuentas...")

    # Intentar ir a 'Cuentas' desde el menu principal
    _navegar_a_cuentas(d)
    time.sleep(2)

    # Extraer el saldo disponible
    saldo = _extraer_saldo(d)
    return saldo


def _navegar_a_cuentas(d: u2.Device):
    """Navega al menu de cuentas/saldo."""
    # Buscar tab o menu de Cuentas
    for texto in ["Cuentas", "Mi cuenta", "Cuenta", "Saldo"]:
        elem = d(text=texto)
        if elem.exists:
            elem.click()
            return

    # Fallback: buscar en barra de navegacion inferior
    nav = d(resourceIdMatches=".*nav.*|.*menu.*|.*tab.*")
    if nav.exists:
        nav.child(textContains="Cuenta").click()
        return

    raise RuntimeError(
        "No se encontro el menu de Cuentas.\n"
        "Verifica que el login fue exitoso y la app esta en el dashboard."
    )


def _extraer_saldo(d: u2.Device) -> str:
    """Extrae el texto del saldo disponible de la pantalla."""
    # Intentar varios patrones comunes en apps bancarias
    selectores = [
        {"resourceIdMatches": ".*saldo.*disponible.*"},
        {"resourceIdMatches": ".*balance.*available.*"},
        {"resourceIdMatches": ".*saldo.*"},
        {"descriptionMatches": "(?i)saldo disponible"},
        {"textMatches": r"^\d[\d,\.]+$"},  # numero con formato monetario
    ]

    for selector in selectores:
        elem = d(**selector)
        if elem.exists:
            texto = elem.get_text().strip()
            if texto:
                print(f"Saldo encontrado con selector {selector}: {texto}")
                return texto

    # Ultimo recurso: volcar la jerarquia y buscar patrones numericos
    import re
    hierarchy = d.dump_hierarchy()
    montos = re.findall(r'\b\d{1,3}(?:[,\.]\d{3})*(?:[,\.]\d{2})?\b', hierarchy)
    if montos:
        print(f"Saldo detectado por patron: {montos[0]}")
        return montos[0]

    raise RuntimeError(
        "No se pudo encontrar el saldo en la pantalla.\n"
        "Ejecuta d.dump_hierarchy() con la app en la pantalla de saldo\n"
        "para identificar el resourceId correcto."
    )
