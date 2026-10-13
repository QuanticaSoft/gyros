import os
from dataclasses import dataclass
from dotenv import load_dotenv

load_dotenv()


@dataclass
class Config:
    usuario: str
    password: str
    dispositivo: str
    nombre_titular: str


@dataclass
class OficinaConfig:
    cuenta: str
    banco: str
    moneda: str
    alias: str = ""
    titular: str = ""

    @property
    def es_banco_union(self) -> bool:
        """La app no ofrece al propio banco como destino ACH: va por Transferencia a Terceros."""
        return "UNION" in self.banco.upper().replace("Ó", "O")


def load_config() -> Config:
    usuario = os.getenv("BU_USUARIO")
    password = os.getenv("BU_PASSWORD")
    dispositivo = os.getenv("BU_DISPOSITIVO")
    nombre_titular = os.getenv("BU_NOMBRE_TITULAR", "")

    if not all([usuario, password, dispositivo]):
        raise RuntimeError(
            "Faltan variables de entorno. El archivo .env debe tener:\n"
            "  BU_USUARIO, BU_PASSWORD, BU_DISPOSITIVO\n"
            "  BU_NOMBRE_TITULAR (opcional, para verificar pantalla de contraseña)"
        )

    return Config(
        usuario=usuario,
        password=password,
        dispositivo=dispositivo,
        nombre_titular=nombre_titular,
    )


def load_oficina_config() -> OficinaConfig:
    cuenta = os.getenv("BU_OFICINA_CUENTA")
    banco = os.getenv("BU_OFICINA_BANCO")
    oficina = OficinaConfig(
        cuenta=cuenta or "",
        banco=banco or "",
        moneda=os.getenv("BU_OFICINA_MONEDA", "BOLIVIANOS"),
        alias=os.getenv("BU_OFICINA_ALIAS", ""),
        titular=os.getenv("BU_OFICINA_TITULAR", ""),
    )

    if not cuenta or not banco or not (oficina.alias or oficina.es_banco_union):
        raise RuntimeError(
            "Faltan variables de entorno para la cuenta oficina. El archivo .env debe tener:\n"
            "  BU_OFICINA_CUENTA, BU_OFICINA_BANCO\n"
            "  BU_OFICINA_ALIAS (solo si la cuenta es de otro banco: transferencia ACH)\n"
            "  BU_OFICINA_TITULAR (opcional, para verificar al titular en el Banco Union)"
        )

    return oficina
