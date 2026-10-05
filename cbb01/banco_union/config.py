import os
from dataclasses import dataclass
from dotenv import load_dotenv

load_dotenv()

@dataclass
class Config:
    usuario: str
    password: str
    dispositivo: str

def load_config() -> Config:
    usuario = os.getenv("BU_USUARIO")
    password = os.getenv("BU_PASSWORD")
    dispositivo = os.getenv("BU_DISPOSITIVO")

    if not all([usuario, password, dispositivo]):
        raise RuntimeError(
            "Faltan variables de entorno. Verifica que exista el archivo .env con:\n"
            "  BU_USUARIO, BU_PASSWORD, BU_DISPOSITIVO"
        )

    return Config(usuario=usuario, password=password, dispositivo=dispositivo)
