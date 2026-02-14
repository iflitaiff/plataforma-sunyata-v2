"""
POST /api/ai/process-document — Document text extraction.
"""

import logging

from fastapi import APIRouter, Depends, HTTPException, Request
from slowapi import Limiter
from slowapi.util import get_remote_address

from ..config import settings
from ..dependencies import verify_internal_key
from ..models import DocumentProcessRequest, DocumentProcessResponse
from ..services.document_processor import process_document

logger = logging.getLogger(__name__)
router = APIRouter()
limiter = Limiter(key_func=get_remote_address)


@router.post("/process-document", response_model=DocumentProcessResponse)
@limiter.limit("100/minute")
async def api_process_document(
    request: Request,
    req: DocumentProcessRequest,
    _key: str = Depends(verify_internal_key),
):
    """Extract text from PDF, DOCX, or plain text documents."""
    try:
        # Calculate max base64 length (max_upload_size_mb * 1.37 overhead)
        max_base64_len = int(settings.max_upload_size_mb * 1024 * 1024 * 1.37)

        if len(req.file_content_base64) > max_base64_len:
            raise HTTPException(
                status_code=413,
                detail=f"File too large. Max size: {settings.max_upload_size_mb}MB",
            )

        result = process_document(
            mime_type=req.mime_type,
            file_content_base64=req.file_content_base64,
        )
        return DocumentProcessResponse(**result)

    except Exception as e:
        logger.exception("Document processing failed")
        return DocumentProcessResponse(
            success=False,
            error="Ocorreu um erro inesperado ao processar o documento.",
        )
