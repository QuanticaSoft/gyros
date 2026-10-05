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
    alias: str
    cuenta: str
    banco: str
    moneda: str


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
    alias = os.getenv("BU_OFICINA_ALIAS")
    cuenta = os.getenv("BU_OFICINA_CUENTA")
    banco = os.getenv("BU_OFICINA_BANCO")
    moneda = os.getenv("BU_OFICINA_MONEDA", "BOLIVIANOS")

    if not all([alias, cuenta, banco]):
        raise RuntimeError(
            "Faltan variables de entorno para la cuenta oficina. El archivo .env debe tener:\n"
            "  BU_OFICINA_ALIAS, BU_OFICINA_CUENTA, BU_OFICINA_BANCO"
        )

    return OficinaConfig(alias=alias, cuenta=cuenta, banco=banco, moneda=moneda)
