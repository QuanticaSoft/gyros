"""
Lector de saldo — Banco Union Bolivia
Uso: python -m banco_union.main
"""
from banco_union.config import load_config
from banco_union.device import get_device
from banco_union.auth import login
from banco_union.balance import get_balance


def main():
    cfg = load_config()
    d = get_device()
    login(d, cfg.usuario, cfg.password)
    saldo = get_balance(d)
    print(f"\nSaldo disponible: Bs. {saldo}")


if __name__ == "__main__":
    main()
