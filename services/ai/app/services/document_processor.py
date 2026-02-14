"""
Document text extraction service — PDF, DOCX, TXT.
"""

import base64
import io
import os
import tempfile

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
