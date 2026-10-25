"""Bounded, local-only document conversion worker. JSON request/response on stdio."""

from __future__ import annotations

import csv
import ctypes
import io
import json
import logging
import os
import re
import shutil
import subprocess
import sys
import tempfile
import time
import warnings
import zipfile
from datetime import date, datetime
from importlib.metadata import version
from pathlib import Path, PurePosixPath
from typing import Any

import pdfplumber
import pypdfium2 as pdfium
import pytesseract
from defusedxml import ElementTree
from docx import Document
from openpyxl import Workbook, load_workbook
from PIL import Image, ImageOps, UnidentifiedImageError
from pptx import Presentation
from pypdf import PdfReader, PdfWriter

CAPABILITIES = json.loads(Path(__file__).with_name("conversion-capabilities.json").read_text())
IMAGE_TYPES = set(CAPABILITIES["images"])
OFFICE_TYPES = set(CAPABILITIES["office"])
DATA_TYPES = {"csv", "tsv", "json"}
FORMATS = {**CAPABILITIES["office"], **CAPABILITIES["pdf"], **CAPABILITIES["images"]}
Image.MAX_IMAGE_PIXELS = 20_000_000
warnings.simplefilter("error", Image.DecompressionBombWarning)
logging.getLogger("pdfminer").setLevel(logging.ERROR)
logging.getLogger("pypdf").setLevel(logging.ERROR)


class RejectedDocument(Exception):
    pass


def restrict_process_tree() -> Any:
    """Keep converter subprocesses within a bounded job, including after a parent timeout."""
    if os.name != "nt":
        import resource
        resource.setrlimit(resource.RLIMIT_AS, (768 * 1024 * 1024, 768 * 1024 * 1024))
        resource.setrlimit(resource.RLIMIT_CPU, (125, 125))
        return None
    from ctypes import wintypes

    class BasicLimits(ctypes.Structure):
        _fields_ = [
            ("process_time", ctypes.c_int64), ("job_time", ctypes.c_int64),
            ("flags", wintypes.DWORD), ("min_working_set", ctypes.c_size_t),
            ("max_working_set", ctypes.c_size_t), ("active_processes", wintypes.DWORD),
            ("affinity", ctypes.c_size_t), ("priority", wintypes.DWORD),
            ("scheduling", wintypes.DWORD),
        ]

    class IoCounters(ctypes.Structure):
        _fields_ = [(name, ctypes.c_uint64) for name in ["read_ops", "write_ops", "other_ops", "read_bytes", "write_bytes", "other_bytes"]]

    class ExtendedLimits(ctypes.Structure):
        _fields_ = [("basic", BasicLimits), ("io", IoCounters), ("process_memory", ctypes.c_size_t),
                    ("job_memory", ctypes.c_size_t), ("peak_process_memory", ctypes.c_size_t),
                    ("peak_job_memory", ctypes.c_size_t)]

    kernel = ctypes.WinDLL("kernel32", use_last_error=True)
    kernel.CreateJobObjectW.argtypes = [ctypes.c_void_p, wintypes.LPCWSTR]
    kernel.CreateJobObjectW.restype = wintypes.HANDLE
    kernel.SetInformationJobObject.argtypes = [wintypes.HANDLE, ctypes.c_int, ctypes.c_void_p, wintypes.DWORD]
    kernel.AssignProcessToJobObject.argtypes = [wintypes.HANDLE, wintypes.HANDLE]
    kernel.GetCurrentProcess.restype = wintypes.HANDLE
    job = kernel.CreateJobObjectW(None, None)
    limits = ExtendedLimits()
    limits.basic.flags = 0x2000 | 0x100 | 0x8  # Kill descendants on close; per-process memory; bounded child count.
    limits.basic.active_processes = 8
    limits.process_memory = 768 * 1024 * 1024
    if not job or not kernel.SetInformationJobObject(job, 9, ctypes.byref(limits), ctypes.sizeof(limits)) or not kernel.AssignProcessToJobObject(job, kernel.GetCurrentProcess()):
        raise RuntimeError("Could not establish local converter resource limits.")
    return job


class Engine:
    def __init__(self, settings: dict[str, Any]) -> None:
        self.settings = settings
        self.deadline = time.monotonic() + settings.get("timeout", 120)
        self.max_pages = settings.get("max_pages", 20)
        self.max_bytes = settings.get("max_uncompressed_bytes", 33_554_432)
        self.max_rows = settings.get("max_rows", 20000)
        self.max_columns = settings.get("max_columns", 100)
        self.messages: list[str] = []
        self.workspaces: list[str] = []
        pytesseract.pytesseract.tesseract_cmd = settings["tesseract"]

    def check_time(self) -> None:
        if time.monotonic() > self.deadline:
            raise RejectedDocument("The conversion exceeded its processing time limit.")

    def warn(self, text: str) -> None:
        if text not in self.messages:
            self.messages.append(text)

    def register_workspace(self, path: str) -> None:
        self.workspaces.append(str(Path(path).resolve()))
        if self.settings.get("workspace_record"):
            record = Path(self.settings["workspace_record"])
            temporary = record.with_suffix(".new")
            temporary.write_text(json.dumps(self.workspaces), encoding="utf-8")
            temporary.replace(record)

    def zip_entries(self, path: Path, *, office: bool = False) -> list[zipfile.ZipInfo]:
        with zipfile.ZipFile(path) as archive:
            entries = archive.infolist()
            if len(entries) > (500 if office else 20):
                raise RejectedDocument("Too many entries in the document package or ZIP (20 documents maximum).")
            total = 0
            names: set[str] = set()
            for item in entries:
                name = item.filename
                parts = PurePosixPath(name).parts
                if (
                    not name or "\\" in name or ":" in name or name.startswith("/")
                    or any(part in {"..", "."} for part in parts)
                    or any(ord(character) < 32 for character in name)
                    or name.casefold() in names
                    or (item.external_attr >> 16) & 0o170000 == 0o120000
                    or item.flag_bits & 1
                ):
                    raise RejectedDocument("Unsafe paths, symbolic links, duplicates or encrypted ZIP entries are not allowed.")
                names.add(name.casefold())
                total += item.file_size
                if total > self.max_bytes or item.file_size > self.max_bytes:
                    raise RejectedDocument("The expanded package exceeds the size limit.")
                if office and not item.is_dir():
                    if re.search(r"vbaProject|macros/|scripts/|basic/|embeddings/|externalLinks/", name, re.I):
                        raise RejectedDocument("Macros, embedded executables and external workbook links are not supported.")
                    if name.endswith((".xml", ".rels")):
                        xml = archive.read(item)
                        try:
                            root = ElementTree.fromstring(xml)
                        except Exception as error:
                            raise RejectedDocument("Invalid or unsafe XML in the office package.") from error
                        for node in root.iter():
                            local = node.tag.rsplit("}", 1)[-1]
                            # Never permit network/file relationships or external spreadsheet formulas.
                            if local == "Relationship" and node.attrib.get("TargetMode") == "External":
                                raise RejectedDocument("External document relationships are not permitted.")
                            if any(key.endswith("}href") and re.match(r"(?i)^(?:https?:|ftp:|file:|/|\\|\.\.)", value)
                                   for key, value in node.attrib.items()):
                                raise RejectedDocument("External document resources are not permitted.")
                            if local == "instrText" and re.search(r"DDE|INCLUDETEXT|INCLUDEPICTURE|LINK", node.text or "", re.I):
                                raise RejectedDocument("Active document fields are not permitted.")
                            if local in {"f", "formula", "definedName"} or any(key.endswith("}formula") for key in node.attrib):
                                formula = (node.text or "") + " ".join(node.attrib.values())
                                if re.search(r"WEBSERVICE|HYPERLINK|DDE|RTD|https?:|file:|\[[^\]]+\]", formula, re.I):
                                    raise RejectedDocument("External or active spreadsheet formulas are not permitted.")
                            if local in {"row", "c"}:
                                ref = node.attrib.get("r", "")
                                digits = re.search(r"(\d+)$", ref)
                                if digits and int(digits[1]) > self.max_rows:
                                    raise RejectedDocument("Workbook exceeds the row limit.")
                                letters = re.match(r"([A-Z]+)", ref)
                                if letters:
                                    number = 0
                                    for letter in letters[1]:
                                        number = number * 26 + ord(letter) - 64
                                    if number > self.max_columns:
                                        raise RejectedDocument("Workbook exceeds the column limit.")
                self.check_time()
            return entries

    def office_preflight(self, path: Path, source: str) -> None:
        if source in {"docx", "pptx", "xlsx", "odt", "odp", "ods"}:
            self.zip_entries(path, office=True)
            with zipfile.ZipFile(path) as package:
                expected = {"docx": "word/document.xml", "pptx": "ppt/presentation.xml",
                            "xlsx": "xl/workbook.xml", "odt": "content.xml",
                            "odp": "content.xml", "ods": "content.xml"}[source]
                if expected not in package.namelist():
                    raise RejectedDocument("Office content does not match the source extension.")
        elif source == "rtf":
            content = path.read_bytes()
            if not content.startswith(b"{\\rtf") or re.search(rb"\\(object|objdata|field|filetbl)", content, re.I):
                raise RejectedDocument("Invalid RTF or active/embedded RTF content.")
        elif source == "txt":
            content = path.read_text(encoding="utf-8-sig")
            if "\0" in content:
                raise RejectedDocument("Text must be valid UTF-8 without binary bytes.")

    def office(self, path: Path, source: str, target: str, directory: Path) -> Path:
        self.office_preflight(path, source)
        filters = {
            "pdf": "pdf", "docx": "docx:Office Open XML Text",
            "odt": "odt:writer8", "rtf": "rtf:Rich Text Format",
            "txt": "txt:Text (encoded):UTF8", "pptx": "pptx:Impress MS PowerPoint 2007 XML",
            "odp": "odp:impress8", "xlsx": "xlsx:Calc MS Excel 2007 XML", "ods": "ods:calc8",
        }
        directory.mkdir(parents=True, exist_ok=True)
        # LibreOffice's Windows profile registry still encounters MAX_PATH limits in deeply nested job directories.
        with tempfile.TemporaryDirectory(prefix="oc-profile-") as profile:
            self.register_workspace(profile)
            user = Path(profile) / "user"
            user.mkdir()
            (user / "registrymodifications.xcu").write_text(
                '<?xml version="1.0"?><oor:items xmlns:oor="http://openoffice.org/2001/registry">'
                '<item oor:path="/org.openoffice.Office.Common/Security/Scripting">'
                '<prop oor:name="MacroSecurityLevel" oor:op="fuse"><value>3</value></prop></item>'
                '<item oor:path="/org.openoffice.Office.Calc/Content/Update">'
                '<prop oor:name="Link" oor:op="fuse"><value>0</value></prop></item></oor:items>',
                encoding="utf-8",
            )
            command = [
                self.settings["libreoffice"], f"-env:UserInstallation={Path(profile).as_uri()}",
                "--headless", "--nologo", "--nodefault", "--nolockcheck", "--norestore",
                "--convert-to", filters[target], "--outdir", str(directory), str(path),
            ]
            self.check_time()
            result = subprocess.run(command, capture_output=True, timeout=min(60, max(1, self.deadline - time.monotonic())), check=False)
            output = directory / f"{path.stem}.{target}"
            if result.returncode != 0 or not output.is_file() or output.stat().st_size == 0:
                raise RejectedDocument("LibreOffice could not convert this file. It may be damaged, encrypted or incompatible.")
        self.warn("Office layouts, fonts, charts and formulas are converted best-effort by LibreOffice. Review the result; macros and external links are rejected.")
        if target == "pdf":
            with pdfplumber.open(output) as pdf:
                self.validate_pdf(pdf)
        self.check_size(output)
        return output

    def validate_pdf(self, pdf: Any) -> None:
        if getattr(pdf.doc, "encryption", None):
            raise RejectedDocument("Encrypted PDFs are not supported.")
        if not 1 <= len(pdf.pages) <= self.max_pages:
            raise RejectedDocument(f"PDFs must contain between one and {self.max_pages} pages.")
        pixels = 0
        for page in pdf.pages:
            pixels += page.width * page.height * (150 / 72) ** 2
            if pixels > Image.MAX_IMAGE_PIXELS:
                raise RejectedDocument("Combined PDF pages exceed the 20 million rendered-pixel limit.")

    def check_size(self, path: Path) -> None:
        if path.stat().st_size > self.max_bytes:
            raise RejectedDocument("The result exceeds the output size limit.")

    def bound_rows(self, rows: list[list[str]]) -> list[list[str]]:
        if len(rows) > self.max_rows:
            raise RejectedDocument("The document exceeds the row limit.")
        for row in rows:
            if len(row) > self.max_columns:
                raise RejectedDocument("The document exceeds the column limit.")
            for cell in row:
                if len(cell.encode("utf-8")) > 32767 or re.search(r"[\x00-\x08\x0b\x0c\x0e-\x1f]", cell):
                    raise RejectedDocument("A cell is too large or contains unsupported control characters.")
        return rows

    def write_rows(self, rows: list[list[str]], target: str, output: Path) -> list[list[str]]:
        self.bound_rows(rows)
        if target == "xlsx":
            workbook = Workbook()
            sheet = workbook.active
            for row in rows:
                sheet.append(row)
                for cell in sheet[sheet.max_row]:
                    cell.data_type = "s"
            workbook.save(output)
            workbook.close()
        elif target == "json":
            output.write_text(json.dumps(rows, ensure_ascii=False), encoding="utf-8")
        else:
            safe_rows = [[("'" + value) if re.match(r"^\s*[=+\-@]", value) else value for value in row] for row in rows]
            with output.open("w", newline="", encoding="utf-8") as stream:
                csv.writer(stream, delimiter="\t" if target == "tsv" else ",").writerows(safe_rows)
            self.warn("CSV/TSV formula-like values receive an apostrophe prefix for spreadsheet safety; this changes those values.")
            return safe_rows
        return rows

    def package(self, files: list[Path], directory: Path) -> Path:
        output = directory / "results.zip"
        if not files:
            raise RejectedDocument("The conversion produced no files.")
        if sum(path.stat().st_size for path in files) > self.max_bytes:
            raise RejectedDocument("Combined results exceed the output size limit.")
        with zipfile.ZipFile(output, "w", zipfile.ZIP_DEFLATED) as archive:
            for index, path in enumerate(files, 1):
                archive.write(path, f"{index:03d}-{path.name}")
        self.check_size(output)
        return output

    def spreadsheet(self, path: Path, source: str, target: str, directory: Path) -> tuple[Path, list[list[str]]]:
        self.office_preflight(path, source)
        if target not in DATA_TYPES:
            return self.office(path, source, target, directory / "office"), []
        normalized = path
        # Recalculate real formulas using LibreOffice rather than exporting empty cached cells.
        with zipfile.ZipFile(path) as package:
            has_formula = source != "xlsx" or any(
                re.search(rb"<(?:\w+:)?f[ >]", package.read(name))
                for name in package.namelist() if name.startswith("xl/worksheets/") and name.endswith(".xml")
            )
        if has_formula:
            normalized = self.office(path, source, "xlsx", directory / "recalculated")
        workbook = load_workbook(normalized, read_only=True, data_only=True, keep_links=False)
        outputs: list[Path] = []
        preview: list[list[str]] = []
        total_rows = 0
        try:
            if len(workbook.worksheets) > 20:
                raise RejectedDocument("Workbooks are limited to 20 worksheets.")
            for index, sheet in enumerate(workbook.worksheets, 1):
                if sheet.max_row > self.max_rows or sheet.max_column > self.max_columns:
                    raise RejectedDocument("Workbook dimensions exceed the configured row or column limit.")
                rows = []
                for values in sheet.iter_rows(values_only=True):
                    row = [value.isoformat() if isinstance(value, (datetime, date)) else
                           ("true" if value else "false") if isinstance(value, bool) else
                           "" if value is None else str(value) for value in values]
                    rows.append(row)
                    total_rows += 1
                    if total_rows > self.max_rows:
                        raise RejectedDocument("Combined worksheets exceed the row limit.")
                self.bound_rows(rows)
                name = re.sub(r"[^a-zA-Z0-9_-]", "_", sheet.title)[:50] or "sheet"
                output = directory / f"sheet-{index}-{name}.{target}"
                emitted = self.write_rows(rows, target, output)
                if not preview:
                    preview = [row[:10] for row in emitted[:10]]
                outputs.append(output)
        finally:
            workbook.close()
        self.warn("Text/JSON exports contain calculated values, not formulas, styling, merged-cell layout or charts. Merged cells retain their top-left value; other positions are empty.")
        if len(outputs) > 1:
            self.warn("Every worksheet is included as a separate file in the ZIP. Preview shows the first worksheet.")
        return (outputs[0] if len(outputs) == 1 else self.package(outputs, directory)), preview

    def image_frames(self, path: Path, source: str) -> list[Image.Image]:
        allowed = {"jpg": "JPEG", "jpeg": "JPEG", "png": "PNG", "webp": "WEBP", "bmp": "BMP", "gif": "GIF", "tiff": "TIFF"}
        frames: list[Image.Image] = []
        with Image.open(path) as image:
            if image.format != allowed[source]:
                raise RejectedDocument("Image signature does not match its extension.")
            frame_count = getattr(image, "n_frames", 1)
            if frame_count > self.max_pages:
                raise RejectedDocument("Too many image frames.")
            pixels = 0
            for index in range(frame_count):
                image.seek(index)
                pixels += image.width * image.height
                if pixels > Image.MAX_IMAGE_PIXELS:
                    raise RejectedDocument("Combined image dimensions exceed 20 million pixels.")
                frame = ImageOps.exif_transpose(image).convert("RGBA")
                frames.append(frame.copy())
        return frames

    def opaque(self, image: Image.Image) -> Image.Image:
        background = Image.new("RGB", image.size, "white")
        background.paste(image, mask=image.getchannel("A") if image.mode == "RGBA" else None)
        return background

    def ocr(self, image: Image.Image) -> str:
        self.check_time()
        self.warn("OCR uses the installed English model. Recognition, reading order, numbers and inferred table columns can be wrong; verify against the original.")
        text = pytesseract.image_to_string(self.opaque(image), lang="eng", timeout=30)
        self.check_time()
        return text

    def ocr_rows(self, image: Image.Image) -> list[list[str]]:
        self.check_time()
        data = pytesseract.image_to_data(self.opaque(image), lang="eng", config="--psm 6", timeout=30, output_type=pytesseract.Output.DICT)
        groups: dict[tuple[int, int, int], list[tuple[int, int, str]]] = {}
        for index, text in enumerate(data["text"]):
            if text.strip() and float(data["conf"][index]) >= 20:
                key = (data["block_num"][index], data["par_num"][index], data["line_num"][index])
                groups.setdefault(key, []).append((data["left"][index], data["width"][index], text.strip()))
        rows: list[list[str]] = []
        for words in groups.values():
            words.sort()
            row: list[str] = []
            edge = -1
            for left, width, text in words:
                if edge < 0 or left - edge > max(25, image.width * 0.025):
                    row.append(text)
                else:
                    row[-1] += " " + text
                edge = left + width
            rows.append(row)
        self.warn("Scanned-table columns were inferred from word spacing, not a detected spreadsheet schema. Review every row in the preview.")
        return rows

    def searchable_pdf(self, frames: list[Image.Image], output: Path) -> None:
        writer = PdfWriter()
        for image in frames:
            self.check_time()
            data = pytesseract.image_to_pdf_or_hocr(self.opaque(image), extension="pdf", lang="eng", timeout=30)
            writer.append(io.BytesIO(data))
        with output.open("wb") as stream:
            writer.write(stream)
        writer.close()
        self.warn("Searchable PDF contains rasterized pages and a new English OCR layer. Forms, vector content, links and original digital signatures are not preserved.")

    def images(self, path: Path, source: str, target: str, directory: Path) -> tuple[Path, list[list[str]]]:
        frames = self.image_frames(path, source)
        try:
            if target == "txt":
                text = "\n\n".join(self.ocr(frame) for frame in frames)
                if not text.strip():
                    raise RejectedDocument("OCR found no readable text.")
                output = directory / "text.txt"
                output.write_text(text, encoding="utf-8")
                return output, [[line[:500]] for line in text.splitlines()[:10]]
            if target == "ocrpdf":
                output = directory / "searchable.pdf"
                self.searchable_pdf(frames, output)
                return output, []
            if target == "pdf":
                output = directory / "images.pdf"
                rgb = [self.opaque(frame) for frame in frames]
                try:
                    rgb[0].save(output, "PDF", save_all=True, append_images=rgb[1:], resolution=150)
                finally:
                    for image in rgb:
                        image.close()
                return output, []
            outputs = []
            for index, frame in enumerate(frames, 1):
                output = directory / f"frame-{index}.{target}"
                image = self.opaque(frame) if target in {"jpg", "bmp"} else frame
                image.save(output, {"jpg": "JPEG", "png": "PNG", "webp": "WEBP", "bmp": "BMP", "tiff": "TIFF"}[target])
                if image is not frame:
                    image.close()
                outputs.append(output)
                self.check_time()
            self.warn("Image conversion may change compression, color profiles and metadata. JPEG/BMP flatten transparency onto white.")
            if len(outputs) > 1:
                self.warn("All animation/image frames are returned separately in a ZIP; animation timing is not retained.")
            return (outputs[0] if len(outputs) == 1 else self.package(outputs, directory)), []
        finally:
            for frame in frames:
                frame.close()

    def pdf_tables(self, page: pdfplumber.page.Page) -> list[list[list[str | None]]]:
        # Sub-point accessibility labels are not visible cell contents.
        visible = page.filter(lambda item: item.get("object_type") != "char" or item.get("size", 0) >= 2)
        tables = []
        for table in visible.find_tables():
            self.check_time()
            rows = table.extract()
            if len(rows) == 1:
                left, top, right, bottom = table.bbox
                connected = []
                for edge in sorted(visible.vertical_edges, key=lambda item: item["top"]):
                    if left - 1 <= edge["x0"] <= right + 1 and edge["bottom"] >= top and edge["top"] <= bottom + 1:
                        connected.append(edge)
                        bottom = max(bottom, edge["bottom"])
                if bottom > table.bbox[3] + 1:
                    header_edges = [
                        edge for edge in visible.horizontal_edges
                        if top - 1 <= edge["top"] <= table.bbox[3] + 1
                        and edge["x1"] >= left - 1 and edge["x0"] <= right + 1
                    ]
                    xs = sorted({round(value, 2) for value in [
                        left, right, *(edge["x0"] for edge in connected),
                        *(edge["x0"] for edge in header_edges), *(edge["x1"] for edge in header_edges),
                    ]})
                    ys = sorted({round(value, 2) for edge in connected for value in [edge["top"], edge["bottom"]]})
                    if len(xs) - 1 > self.max_columns or len(ys) - 1 > self.max_rows:
                        raise RejectedDocument("Detected PDF table exceeds the row or column limit.")
                    expanded = visible.crop((xs[0], ys[0], xs[-1], ys[-1])).extract_table({
                        "vertical_strategy": "explicit", "horizontal_strategy": "explicit",
                        "explicit_vertical_lines": xs, "explicit_horizontal_lines": ys,
                    })
                    if expanded and len(expanded) > 1:
                        rows = expanded
                        self.warn("Incomplete PDF table borders were reconstructed from aligned line segments. Check all rows and empty columns.")
            if len(rows) > 1:
                tables.append(rows)
            else:
                self.warn("A header-only PDF table region was omitted because no data rows could be recovered.")
        return tables

    def pdf(self, path: Path, target: str, directory: Path) -> tuple[Path, list[list[str]]]:
        reader = PdfReader(path)
        if reader.is_encrypted:
            raise RejectedDocument("Encrypted PDFs are not supported, even when an empty password opens them.")
        with pdfplumber.open(path) as document:
            self.validate_pdf(document)
            if target in {"docx", "txt", "csv", "xlsx"}:
                texts = []
                tables: list[list[list[str]]] = []
                renderer = None
                try:
                    for index, page in enumerate(document.pages):
                        self.check_time()
                        text = page.extract_text() or ""
                        found = self.pdf_tables(page) if target in {"csv", "xlsx"} else []
                        if not text.strip():
                            if renderer is None:
                                renderer = pdfium.PdfDocument(str(path))
                            rendered = self.render_page(renderer, index)
                            try:
                                text = self.ocr(rendered)
                                if target in {"csv", "xlsx"}:
                                    rows = self.ocr_rows(rendered)
                                    if rows and max(map(len, rows)) >= 2:
                                        found = [rows]
                            finally:
                                rendered.close()
                        texts.append(text)
                        for table in found:
                            rows = [[str(cell or "") for cell in row] for row in table]
                            if rows:
                                if any("\ufffd" in cell for row in rows for cell in row):
                                    self.warn("The PDF contains undecodable text glyphs, retained as replacement characters. Verify affected labels and values against the original.")
                                tables.append(self.bound_rows(rows))
                finally:
                    if renderer is not None:
                        renderer.close()
                if target in {"csv", "xlsx"}:
                    if not tables:
                        raise RejectedDocument("No reliable table columns were detected. Use TXT or DOCX for non-tabular PDF content.")
                    if sum(map(len, tables)) > self.max_rows:
                        raise RejectedDocument("Extracted tables exceed the row limit.")
                    self.warn("PDF table extraction is best-effort. Tables are separate worksheets/files; surrounding prose, graphics and page layout are not exported. Verify rows and columns.")
                    if target == "xlsx":
                        output = directory / "tables.xlsx"
                        book = Workbook()
                        book.remove(book.active)
                        for index, rows in enumerate(tables, 1):
                            sheet = book.create_sheet(f"Table {index}")
                            for row in rows:
                                sheet.append(row)
                                for cell in sheet[sheet.max_row]:
                                    cell.data_type = "s"
                        book.save(output)
                        book.close()
                    else:
                        outputs = []
                        for index, rows in enumerate(tables, 1):
                            output = directory / f"table-{index}.csv"
                            emitted = self.write_rows(rows, "csv", output)
                            if index == 1:
                                tables[0] = emitted
                            outputs.append(output)
                        output = outputs[0] if len(outputs) == 1 else self.package(outputs, directory)
                    return output, [row[:10] for row in tables[0][:10]]
                if not any(text.strip() for text in texts):
                    raise RejectedDocument("No readable PDF text was found, including after OCR.")
                if target == "docx":
                    output = directory / "document.docx"
                    doc = Document()
                    for index, text in enumerate(texts):
                        if index:
                            doc.add_page_break()
                        for line in text.splitlines():
                            doc.add_paragraph(line)
                    doc.save(output)
                    self.warn("PDF to Word reconstructs editable text with page breaks, not the original layout. Images, complex columns, forms and table formatting are not reconstructed.")
                else:
                    output = directory / "text.txt"
                    output.write_text("\n\n".join(texts), encoding="utf-8")
                    self.warn("PDF text extraction does not preserve page formatting or embedded graphics.")
                return output, [[line[:500]] for text in texts for line in text.splitlines()][:10]
        renderer = pdfium.PdfDocument(str(path))
        frames = []
        try:
            frames = [self.render_page(renderer, index) for index in range(len(renderer))]
            if target == "ocrpdf":
                output = directory / "searchable.pdf"
                self.searchable_pdf(frames, output)
                return output, []
            if target == "pptx":
                deck = Presentation()
                for index, frame in enumerate(frames, 1):
                    image_path = directory / f"slide-{index}.png"
                    frame.save(image_path)
                    slide = deck.slides.add_slide(deck.slide_layouts[6])
                    ratio = min(deck.slide_width / frame.width, deck.slide_height / frame.height)
                    width, height = int(frame.width * ratio), int(frame.height * ratio)
                    slide.shapes.add_picture(str(image_path), (deck.slide_width - width) // 2, (deck.slide_height - height) // 2, width=width, height=height)
                output = directory / "slides.pptx"
                deck.save(output)
                self.warn("PDF to PowerPoint uses one rendered image per page. Slide text and objects are not individually editable.")
                return output, []
            outputs = []
            for index, frame in enumerate(frames, 1):
                output = directory / f"page-{index}.{target}"
                rgb = frame.convert("RGB")
                rgb.save(output, {"png": "PNG", "jpg": "JPEG", "tiff": "TIFF"}[target])
                rgb.close()
                outputs.append(output)
            self.warn("PDF pages are rasterized at 150 DPI. Multiple pages are returned in a ZIP; vector content is not retained.")
            return (outputs[0] if len(outputs) == 1 else self.package(outputs, directory)), []
        finally:
            for frame in frames:
                frame.close()
            renderer.close()

    def render_page(self, document: Any, index: int) -> Image.Image:
        self.check_time()
        page = document[index]
        bitmap = page.render(scale=150 / 72)
        try:
            return bitmap.to_pil().convert("RGBA").copy()
        finally:
            bitmap.close()
            page.close()

    def archive(self, path: Path, target: str, directory: Path) -> tuple[Path, list[list[str]]]:
        entries = self.zip_entries(path)
        outputs = []
        manifest = []
        with zipfile.ZipFile(path) as archive:
            for index, item in enumerate(entries, 1):
                if item.is_dir():
                    continue
                source = PurePosixPath(item.filename).suffix.lstrip(".").lower()
                if source == "zip" or source not in FORMATS and source not in DATA_TYPES:
                    raise RejectedDocument("ZIP contains an unsupported or nested archive entry. No entries are silently skipped.")
                folder = directory / f"entry-{index}"
                folder.mkdir()
                input_path = folder / f"input.{source}"
                input_path.write_bytes(archive.read(item))
                if source == target:
                    raise RejectedDocument("A ZIP entry already has the target format. Choose an archive whose documents all need the selected conversion.")
                result, _ = self.convert(input_path, source, target, folder)
                outputs.append(result)
                manifest.append([item.filename[:200], result.suffix.lstrip(".")])
                self.check_time()
        self.warn("All supported ZIP entries were converted. Output names are numbered; the manifest maps original entries to output formats. Nested/unsafe/unsupported entries cause the whole conversion to fail.")
        manifest_path = directory / "manifest.json"
        manifest_path.write_text(json.dumps(manifest, ensure_ascii=False), encoding="utf-8")
        return self.package(outputs + [manifest_path], directory), [["Archive entry", "Output"]] + manifest[:9]

    def convert(self, path: Path, source: str, target: str, directory: Path) -> tuple[Path, list[list[str]]]:
        directory.mkdir(parents=True, exist_ok=True)
        self.check_time()
        if source == "zip":
            result = self.archive(path, target, directory)
        elif source in {"csv", "tsv", "json"} and target in {"xlsx", "csv", "tsv", "json"} and source != target:
            if source == "json":
                rows = json.loads(path.read_text(encoding="utf-8-sig"))
                if not isinstance(rows, list) or not rows or any(not isinstance(row, list) or not row or any(not isinstance(cell, str) for cell in row) for row in rows):
                    raise RejectedDocument("JSON must be a nonempty array of nonempty arrays of strings.")
            else:
                with path.open(newline="", encoding="utf-8-sig") as stream:
                    rows = list(csv.reader(stream, delimiter="\t" if source == "tsv" else ",", strict=True))
            self.bound_rows(rows)
            output = directory / f"data.{target}"
            emitted = self.write_rows(rows, target, output)
            result = output, [row[:10] for row in emitted[:10]]
        elif target not in FORMATS.get(source, []):
            raise RejectedDocument("This archive entry or document has no compatible local conversion engine for that target.")
        elif source in {"xlsx", "ods"}:
            result = self.spreadsheet(path, source, target, directory)
        elif source in OFFICE_TYPES:
            output = self.office(path, source, target, directory / "office")
            preview = [[line[:500]] for line in output.read_text(encoding="utf-8-sig").splitlines()[:10]] if target == "txt" else []
            result = output, preview
        elif source in IMAGE_TYPES:
            result = self.images(path, source, target, directory)
        else:
            result = self.pdf(path, target, directory)
        self.check_size(result[0])
        self.check_time()
        return result


def main() -> int:
    job_handle = restrict_process_tree()
    settings = json.load(sys.stdin)
    if settings.get("health"):
        office = subprocess.run([settings["libreoffice"], "--version"], capture_output=True, timeout=15, check=True)
        pytesseract.pytesseract.tesseract_cmd = settings["tesseract"]
        languages = pytesseract.get_languages(config="")
        if "eng" not in languages:
            raise RuntimeError("The English OCR model is missing.")
        print(json.dumps({"ready": True, "libreoffice": office.stdout.decode(errors="replace").strip(),
                          "tesseract": str(pytesseract.get_tesseract_version()), "pillow": version("pillow")}))
        return 0
    engine = Engine(settings)
    output = Path(settings["output"]).resolve()
    engine.settings["workspace_record"] = str(output.parent / "engine-workspaces.json")
    with tempfile.TemporaryDirectory(prefix="oc-work-") as temp:
        engine.register_workspace(temp)
        result, preview = engine.convert(Path(settings["input"]), settings["source"], settings["target"], Path(temp))
        final = output.with_suffix(result.suffix)
        shutil.copyfile(result, final)
        print(json.dumps({"file": str(final), "extension": final.suffix.lstrip("."),
                          "preview": preview, "warnings": engine.messages}, ensure_ascii=True))
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (RejectedDocument, UnicodeError, csv.Error, json.JSONDecodeError, zipfile.BadZipFile, UnidentifiedImageError, Image.DecompressionBombError, Image.DecompressionBombWarning) as error:
        # Only controlled explanations reach the user; parser details may contain document text.
        message = str(error) if isinstance(error, RejectedDocument) else "Invalid, unsafe or damaged source document."
        print(json.dumps({"error": message}))
        sys.exit(2)
    except subprocess.TimeoutExpired:
        print(json.dumps({"error": "A local conversion engine exceeded its time limit."}))
        sys.exit(2)
    except Exception as error:
        print(json.dumps({"error": "The local engine could not process this document. It may be damaged, unsupported or exceed engine limits.", "category": type(error).__name__}))
        sys.exit(3)
