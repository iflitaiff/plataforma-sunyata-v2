"""
Document text extraction service — PDF, DOCX, TXT.
"""

import base64
import io
import os
import tempfile
import zipfile

import pypdf
from docx import Document as DocxDocument


def extract_text_from_pdf(data: bytes) -> dict:
    """Extract text from PDF binary data."""
    reader = pypdf.PdfReader(io.BytesIO(data))
    pages_text = []
    for page in reader.pages:
        text = page.extract_text() or ""
        pages_text.append(text)

    full_text = "\n\n".join(pages_text)
    return {
        "success": True,
        "text": full_text.strip(),
        "pages": len(reader.pages),
        "word_count": len(full_text.split()),
    }


def extract_text_from_docx(data: bytes) -> dict:
    """Extract text from DOCX binary data."""
    with tempfile.NamedTemporaryFile(suffix=".docx", delete=False) as tmp:
        tmp.write(data)
        tmp_path = tmp.name

    try:
        doc = DocxDocument(tmp_path)
        paragraphs = [p.text for p in doc.paragraphs if p.text.strip()]
        full_text = "\n".join(paragraphs)
        return {
            "success": True,
            "text": full_text.strip(),
            "pages": 0,  # DOCX doesn't have fixed pages
            "word_count": len(full_text.split()),
        }
    finally:
        os.unlink(tmp_path)


def extract_text_from_plain(data: bytes) -> dict:
    """Extract text from plain text / markdown."""
    text = data.decode("utf-8", errors="replace")
    return {
        "success": True,
        "text": text.strip(),
        "pages": 0,
        "word_count": len(text.split()),
    }


def detect_format(data: bytes) -> str:
    """Detect file format by magic bytes.

    Returns one of: 'pdf', 'zip', 'docx', 'xlsx', 'odt', 'unknown'.
    Note: DOCX, XLSX, ODT all start with PK\\x03\\x04 (ZIP-based formats).
    """
    if data[:4] == b"%PDF":
        return "pdf"
    if data[:4] == b"PK\x03\x04":
        try:
            with zipfile.ZipFile(io.BytesIO(data)) as zf:
                names = zf.namelist()
                # Identify specific ZIP-based Office formats by their internal structure
                if any(n.startswith("word/") for n in names):
                    return "docx"
                if any(n.startswith("xl/") for n in names):
                    return "xlsx"
                if "content.xml" in names and "mimetype" in names:
                    return "odt"
                # Generic ZIP (may contain PDFs or other files)
                return "zip"
        except zipfile.BadZipFile:
            return "unknown"
    return "unknown"


def extract_texts_from_zip(data: bytes) -> list[dict]:
    """Extract text from all supported files inside a ZIP archive.

    Returns a list of extraction results, one per file found inside.
    Each dict has: filename, success, text, pages, word_count, error.
    """
    results = []
    try:
        with zipfile.ZipFile(io.BytesIO(data)) as zf:
            for name in zf.namelist():
                # Skip directories and hidden files
                if name.endswith("/") or name.startswith("__MACOSX"):
                    continue

                inner_bytes = zf.read(name)
                fmt = detect_format(inner_bytes)

                if fmt == "pdf":
                    r = extract_text_from_pdf(inner_bytes)
                elif fmt == "docx":
                    r = extract_text_from_docx(inner_bytes)
                else:
                    r = {"success": False, "text": "", "pages": 0,
                         "word_count": 0, "error": f"Unsupported format inside ZIP: {name}"}

                r["filename"] = name
                results.append(r)
    except zipfile.BadZipFile:
        results.append({"success": False, "text": "", "pages": 0,
                        "word_count": 0, "error": "Invalid ZIP file",
                        "filename": "(archive)"})
    return results


EXTRACTORS = {
    "application/pdf": extract_text_from_pdf,
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document": extract_text_from_docx,
    "text/plain": extract_text_from_plain,
    "text/markdown": extract_text_from_plain,
}


def process_document(
    mime_type: str,
    file_content_base64: str | None = None,
) -> dict:
    """Extract text from a document by file path or base64 content."""
    extractor = EXTRACTORS.get(mime_type)
    if not extractor:
        return {
            "success": False,
            "text": "",
            "pages": 0,
            "word_count": 0,
            "error": f"Unsupported MIME type: {mime_type}",
        }

    try:
        if not file_content_base64:
            raise ValueError("file_content_base64 is required")

        data = base64.b64decode(file_content_base64)
        return extractor(data)

    except Exception as e:
        return {
            "success": False,
            "text": "",
            "pages": 0,
            "word_count": 0,
            "error": str(e),
        }
