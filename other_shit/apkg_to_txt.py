#!/usr/bin/env python3
"""
apkg_to_txt.py — конвертирует Anki .apkg в .txt + .json (с id для прогресса).

Использование:
    python apkg_to_txt.py deck.apkg [base_name]
"""

import argparse
import gc
import hashlib
import json
import os
import shutil
import sqlite3
import sys
import tempfile
import time
import zipfile
from html.parser import HTMLParser


class HTMLStripper(HTMLParser):
    def __init__(self):
        super().__init__()
        self.reset()
        self.strict = False
        self.convert_charrefs = True
        self.text = []

    def handle_data(self, d):
        self.text.append(d)

    def get_text(self):
        return "".join(self.text)


def strip_html(html: str) -> str:
    if not html:
        return ""
    stripper = HTMLStripper()
    stripper.feed(html)
    return " ".join(stripper.get_text().split())


def make_id(front: str, back: str) -> str:
    h = hashlib.md5((front + "\x00" + back).encode("utf-8")).hexdigest()
    return h[:16]


def find_collection_file(zip_file: zipfile.ZipFile) -> str:
    for name in ("collection.anki21b", "collection.anki21", "collection.anki2"):
        if name in zip_file.namelist():
            return name
    for name in zip_file.namelist():
        if name.startswith("collection.anki"):
            return name
    raise FileNotFoundError("В .apkg не найден файл collection.anki*")


def extract_notes_from_db(db_path: str):
    conn = sqlite3.connect(db_path)
    conn.row_factory = sqlite3.Row
    cur = conn.cursor()
    cur.execute("SELECT name FROM sqlite_master WHERE type='table'")
    tables = {row[0] for row in cur.fetchall()}
    if "notes" not in tables:
        raise RuntimeError("В базе нет таблицы notes")

    models = {}
    if "col" in tables:
        cur.execute("SELECT models FROM col LIMIT 1")
        row = cur.fetchone()
        if row and row["models"]:
            try:
                models_json = json.loads(row["models"])
                for mid_str, model in models_json.items():
                    flds = model.get("flds", [])
                    models[int(mid_str)] = [f["name"] for f in flds]
            except Exception:
                pass

    cur.execute("SELECT id, mid, flds, tags FROM notes ORDER BY id")
    notes = []
    for row in cur.fetchall():
        raw = row["flds"].split("\x1f") if row["flds"] else []
        tags_raw = row["tags"].strip()
        tags = [t for t in tags_raw.split() if t] if tags_raw else []
        notes.append({
            "mid": row["mid"],
            "fields": raw,
            "tags": tags,
        })
    conn.close()
    return notes, models


def build_cards(notes, models):
    cards = []
    for note in notes:
        field_names = models.get(note["mid"], [])
        fields = note["fields"]

        front = strip_html(fields[0]) if len(fields) > 0 else ""
        back = strip_html(fields[1]) if len(fields) > 1 else ""
        if not front and not back:
            continue

        extra = {}
        for j in range(2, len(fields)):
            name = field_names[j] if j < len(field_names) else f"Field{j + 1}"
            val = strip_html(fields[j])
            if val:
                extra[name] = val

        card = {
            "id": make_id(front, back),
            "front": front,
            "back": back,
        }
        if extra:
            card["extra"] = extra
        if note["tags"]:
            card["tags"] = note["tags"]
        cards.append(card)
    return cards


def save_as_txt(cards, path, apkg_name):
    with open(path, "w", encoding="utf-8") as f:
        f.write(f"# Конвертировано из: {apkg_name}\n")
        f.write(f"# Всего карточек: {len(cards)}\n")
        f.write("# Формат: Front: ... / Back: ...\n\n")
        for i, c in enumerate(cards, 1):
            f.write(f"--- Карточка {i} (id: {c['id']}) ---\n")
            f.write(f"Front: {c['front']}\n")
            f.write(f"Back: {c['back']}\n")
            for name, val in c.get("extra", {}).items():
                f.write(f"{name}: {val}\n")
            if c.get("tags"):
                f.write(f"Tags: {' '.join(c['tags'])}\n")
            f.write("\n")


def save_as_json(cards, path):
    with open(path, "w", encoding="utf-8") as f:
        json.dump(cards, f, ensure_ascii=False, indent=2)


def _decompress_collection(raw: bytes) -> bytes:
    """
    Приводит содержимое collection.anki* к чистому SQLite.
    - Если это уже SQLite — возвращает как есть.
    - Если это zstd-сжатый SQLite (collection.anki21b) — распаковывает.
    """
    if raw[:16].startswith(b"SQLite format 3"):
        return raw

    # Сигнатура zstd — магические байты 0x28 0xB5 0x2F 0xFD
    if not raw[:4] == b"\x28\xb5\x2f\xfd":
        raise RuntimeError(
            "Неизвестный формат collection-файла. "
            "Первые байты: " + raw[:16].hex()
        )

    # Пробуем разные обёртки zstd, чтобы работало независимо от окружения
    errors = []
    try:
        import zstandard as zstd
        dctx = zstd.ZstdDecompressor()
        return dctx.decompress(raw, max_output_size=200 * 1024 * 1024)
    except ImportError as e:
        errors.append(f"zstandard не установлен: {e}")
    except Exception as e:
        errors.append(f"zstandard ошибка: {e}")

    try:
        import pyzstd
        return pyzstd.decompress(raw)
    except ImportError as e:
        errors.append(f"pyzstd не установлен: {e}")
    except Exception as e:
        errors.append(f"pyzstd ошибка: {e}")

    raise RuntimeError(
        "Файл collection.anki21b сжат zstd, но ни одна библиотека "
        "не смогла его распаковать. Установите:\n"
        "    pip install zstandard\n"
        "Подробности: " + " | ".join(errors)
    )


def convert_apkg(apkg_path: str, base_path: str):
    if not zipfile.is_zipfile(apkg_path):
        raise ValueError(f"{apkg_path} — не zip-архив (не похоже на .apkg)")

    tmpdir = tempfile.mkdtemp(prefix="apkg_")
    db_path = os.path.join(tmpdir, "collection.anki2")

    try:
        with zipfile.ZipFile(apkg_path, "r") as zf:
            collection_name = find_collection_file(zf)
            raw = zf.read(collection_name)
            print(f"ℹ️  Внутри .apkg найден: {collection_name} "
                  f"({len(raw)} байт)")
            raw = _decompress_collection(raw)

        # Пишем распакованную БД на диск
        with open(db_path, "wb") as f:
            f.write(raw)

        # Небольшая пауза — чтобы Windows точно отпустил файл
        time.sleep(0.05)
        notes, models = extract_notes_from_db(db_path)

    finally:
        gc.collect()
        # Пытаемся удалить временную папку — не критично, если не выйдет
        for attempt in range(20):
            try:
                shutil.rmtree(tmpdir)
                break
            except (PermissionError, OSError):
                time.sleep(0.15)
        else:
            shutil.rmtree(tmpdir, ignore_errors=True)
            print(f"⚠️  Временная папка не удалилась: {tmpdir} "
                  f"(её можно удалить вручную)", file=sys.stderr)

    cards = build_cards(notes, models)
    txt_path = base_path + ".txt"
    json_path = base_path + ".json"
    save_as_txt(cards, txt_path, os.path.basename(apkg_path))
    save_as_json(cards, json_path)

    print(f"✅ Готово! Карточек: {len(cards)}")
    print(f"   TXT  → {txt_path}")
    print(f"   JSON → {json_path}")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("apkg")
    parser.add_argument("base", nargs="?", default=None)
    args = parser.parse_args()

    if not os.path.isfile(args.apkg):
        print(f"❌ Файл не найден: {args.apkg}", file=sys.stderr)
        sys.exit(1)

    base = args.base if args.base else os.path.splitext(args.apkg)[0]
    if base.endswith((".txt", ".json")):
        base = os.path.splitext(base)[0]

    try:
        convert_apkg(args.apkg, base)
    except Exception as e:
        print(f"❌ Ошибка: {e}", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()