"""
POST /api/ai/process-document — Document text extraction.
"""

import logging

from fastapi import APIRouter, Depends

from ..dependencies import verify_internal_key
from ..models import DocumentProcessRequest, DocumentProcessResponse
from ..services.document_processor import process_document

logger = logging.getLogger(__name__)
router = APIRouter()


@router.post("/process-document", response_model=DocumentProcessResponse)
async def api_process_document(
    req: DocumentProcessRequest,
    _key: str = Depends(verify_internal_key),
):
    """Extract text from PDF, DOCX, or plain text documents."""
    try:
        result = process_document(
            mime_type=req.mime_type,
            file_path=req.file_path,
            file_content_base64=req.file_content_base64,
        )
        return DocumentProcessResponse(**result)

    except Exception as e:
        logger.exception("Document processing failed")
        return DocumentProcessResponse(
            success=False,
            error=str(e),
        )
