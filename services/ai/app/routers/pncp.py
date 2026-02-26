"""
PNCP (Portal Nacional de Contratacoes Publicas) search router.

Proxies search requests to the PNCP public API and normalizes results.
PNCP uses an ElasticSearch-backed API at https://pncp.gov.br/api/search/

Also provides PDF text extraction from PNCP edital attachments.
"""

import logging
from datetime import datetime, timezone
from typing import Optional

import asyncpg
import httpx
from fastapi import APIRouter, Depends, Request
from pydantic import BaseModel, Field
from slowapi import Limiter
from slowapi.util import get_remote_address

from ..pncp_keywords import KEYWORD_MAPPING, build_search_query, build_pncp_api_params
from ..dependencies import get_db, verify_internal_key
from ..services.document_processor import (
    extract_text_from_pdf,
    extract_text_from_docx,
    extract_texts_from_zip,
    detect_format,
)

logger = logging.getLogger(__name__)
router = APIRouter()
limiter = Limiter(key_func=get_remote_address)

PNCP_SEARCH_URL = "https://pncp.gov.br/api/search/"
PNCP_TIMEOUT = 30  # seconds


# --- Models ---

class PncpSearchRequest(BaseModel):
    q: str = Field(..., min_length=1, description="Search keywords")
    pagina: int = Field(default=1, ge=1)
    tipos_documento: str = Field(default="edital", description="Required by PNCP API: edital, aviso, ata, contrato")
    status: Optional[str] = None
    modalidade: Optional[str] = None
    ordenacao: Optional[str] = "-data"
    uf: Optional[str] = None
    tam_pagina: int = Field(default=20, ge=1, le=50)


class PncpItem(BaseModel):
    titulo: str = ""
    objeto: str = ""
    orgao: str = ""
    uf: str = ""
    modalidade: str = ""
    status: str = ""
    valor_estimado: Optional[float] = None
    data_publicacao: Optional[str] = None
    data_abertura: Optional[str] = None
    hora_abertura: Optional[str] = None
    url_pncp: str = ""
    url_edital: Optional[str] = None
    numero_licitacao: str = ""
    numero_processo: str = ""
    uasg: str = ""


class PncpSearchResponse(BaseModel):
    success: bool
    items: list[PncpItem] = []
    total: int = 0
    page: int = 1
    error: Optional[str] = None


# --- Endpoint ---

@router.post("/pncp-search", response_model=PncpSearchResponse)
@limiter.limit("100/minute")
async def pncp_search(
    request: Request,
    req: PncpSearchRequest,
    _key: str = Depends(verify_internal_key),
):
    """Search PNCP for public procurement notices."""
    try:
        params = {
            "q": req.q,
            "pagina": req.pagina,
            "tipos_documento": req.tipos_documento or "edital",
        }
        if req.status:
            params["status"] = req.status
        if req.modalidade:
            params["modalidade"] = req.modalidade
        if req.ordenacao:
            params["ordenacao"] = req.ordenacao
        if req.uf:
            params["uf"] = req.uf
        if req.tam_pagina != 20:
            params["tam_pagina"] = req.tam_pagina

        logger.info(f"PNCP search: q={req.q}, page={req.pagina}")

        async with httpx.AsyncClient(timeout=PNCP_TIMEOUT) as client:
            resp = await client.get(PNCP_SEARCH_URL, params=params)

        if resp.status_code != 200:
            logger.warning(f"PNCP API returned {resp.status_code}: {resp.text[:200]}")
            return PncpSearchResponse(
                success=False,
                error=f"PNCP API returned status {resp.status_code}",
            )

        data = resp.json()

        # PNCP response structure varies; normalize it
        items = []
        raw_items = data.get("items", data.get("data", data.get("hits", [])))

        # Handle nested ElasticSearch hits
        if isinstance(raw_items, dict) and "hits" in raw_items:
            raw_items = raw_items["hits"]

        for raw in raw_items:
            # Handle ElasticSearch _source wrapper
            source = raw.get("_source", raw)

            item = PncpItem(
                titulo=source.get("title", source.get("titulo", "")),
                objeto=source.get("description", source.get("objeto", "")),
                orgao=source.get("orgao_nome", source.get("unidade_nome", "")),
                uf=source.get("uf", ""),
                modalidade=source.get("modalidade_licitacao_nome", _extract_modalidade(source)),
                status=source.get("situacao_nome", source.get("status", "")),
                valor_estimado=_extract_valor(source),
                data_publicacao=source.get("data_publicacao_pncp", source.get("dataPublicacao", None)),
                data_abertura=source.get("data_inicio_vigencia", source.get("dataAbertura", None)),
                hora_abertura=source.get("horaAbertura", None),
                url_pncp=_build_pncp_url(source),
                url_edital=source.get("urlEdital", None),
                numero_licitacao=source.get("numero_controle_pncp", source.get("numeroCompra", "")),
                numero_processo=source.get("numeroProcesso", source.get("processo", "")),
                uasg=source.get("unidade_codigo", source.get("uasg", "")),
            )
            items.append(item)

        total = data.get("total", data.get("count", data.get("totalRegistros", len(items))))
        if isinstance(total, dict):
            total = total.get("value", len(items))

        logger.info(f"PNCP search returned {len(items)} items (total: {total})")

        return PncpSearchResponse(
            success=True,
            items=items,
            total=total,
            page=req.pagina,
        )

    except httpx.TimeoutException:
        logger.warning("PNCP API timeout")
        return PncpSearchResponse(
            success=False,
            error="PNCP API timeout - tente novamente em alguns segundos",
        )
    except Exception as e:
        logger.exception(f"PNCP search error: {e}")
        return PncpSearchResponse(
            success=False,
            error="Ocorreu um erro ao buscar no PNCP. Tente novamente.",
        )


def _extract_modalidade(source: dict) -> str:
    """Extract modalidade from various PNCP response formats."""
    if "modalidade" in source:
        val = source["modalidade"]
        if isinstance(val, dict):
            return val.get("descricao", val.get("nome", str(val)))
        return str(val)
    if "modalidadeNome" in source:
        return source["modalidadeNome"]
    if "modalidadeId" in source:
        MODALIDADES = {
            1: "Pregão Eletrônico",
            2: "Concorrência",
            3: "Tomada de Preços",
            4: "Convite",
            5: "Leilão",
            6: "Dispensa de Licitação",
            7: "Inexigibilidade",
            8: "Diálogo Competitivo",
        }
        return MODALIDADES.get(source["modalidadeId"], f"Modalidade {source['modalidadeId']}")
    return ""


def _extract_valor(source: dict) -> float | None:
    """Extract estimated value from various fields."""
    for key in ("valorEstimado", "valor_estimado", "valorTotalEstimado", "valorTotalHomologado"):
        val = source.get(key)
        if val is not None:
            try:
                return float(val)
            except (ValueError, TypeError):
                pass
    return None


def _build_pncp_url(source: dict) -> str:
    """Build URL to view the item on PNCP portal.

    The PNCP frontend uses /app/editais/{cnpj}/{ano}/{seq} format.
    The API item_url returns /compras/... which is an API path, not a frontend route.
    """
    # Build from identifiers (most reliable — matches PNCP frontend route)
    cnpj = source.get("orgao_cnpj", source.get("cnpj", ""))
    ano = source.get("ano", "")
    seq = source.get("numero_sequencial", "")
    if cnpj and ano and seq:
        return f"https://pncp.gov.br/app/editais/{cnpj}/{ano}/{seq}"

    # Try direct URL fields
    for key in ("urlPncp", "url_pncp", "linkSistemaOrigem"):
        if source.get(key):
            return source[key]

    return ""


# --- Monitor endpoint (keyword-based multi-search) ---

class PncpMonitorRequest(BaseModel):
    keywords: list[str] = Field(..., min_length=1)
    ufs: list[str] = Field(default=[])
    status_contratacao: str = "recebendo_proposta"
    valor_minimo: Optional[float] = None
    tipos_documento: str = "edital"
    dias_retroativos: int = 7
    acao: str = "busca_imediata"


@router.post("/pncp-monitor")
@limiter.limit("100/minute")
async def pncp_monitor(
    request: Request,
    req: PncpMonitorRequest,
    _key: str = Depends(verify_internal_key),
):
    """Search PNCP using keyword mapping with automatic singular/plural expansion."""
    try:
        form_data = {
            "keywords": req.keywords,
            "ufs": req.ufs,
            "status_contratacao": req.status_contratacao,
            "tipos_documento": req.tipos_documento,
        }
        params = build_pncp_api_params(form_data)
        query_used = params.get("q", "")

        logger.info(f"PNCP monitor: keywords={req.keywords}, ufs={req.ufs}, query={query_used[:100]}")

        async with httpx.AsyncClient(timeout=PNCP_TIMEOUT) as client:
            resp = await client.get(PNCP_SEARCH_URL, params=params)

        if resp.status_code != 200:
            logger.warning(f"PNCP API returned {resp.status_code}")
            return {"success": False, "error": f"PNCP API status {resp.status_code}"}

        data = resp.json()

        # Reuse existing normalization logic
        items = []
        raw_items = data.get("items", data.get("data", data.get("hits", [])))
        if isinstance(raw_items, dict) and "hits" in raw_items:
            raw_items = raw_items["hits"]

        for raw in raw_items:
            source = raw.get("_source", raw)
            item = PncpItem(
                titulo=source.get("title", source.get("titulo", "")),
                objeto=source.get("description", source.get("objeto", "")),
                orgao=source.get("orgao_nome", source.get("unidade_nome", "")),
                uf=source.get("uf", ""),
                modalidade=source.get("modalidade_licitacao_nome", _extract_modalidade(source)),
                status=source.get("situacao_nome", source.get("status", "")),
                valor_estimado=_extract_valor(source),
                data_publicacao=source.get("data_publicacao_pncp", source.get("dataPublicacao", None)),
                data_abertura=source.get("data_inicio_vigencia", source.get("dataAbertura", None)),
                hora_abertura=source.get("horaAbertura", None),
                url_pncp=_build_pncp_url(source),
                url_edital=source.get("urlEdital", None),
                numero_licitacao=source.get("numero_controle_pncp", source.get("numeroCompra", "")),
                numero_processo=source.get("numeroProcesso", source.get("processo", "")),
                uasg=source.get("unidade_codigo", source.get("uasg", "")),
            )
            items.append(item)

        total = data.get("total", data.get("count", data.get("totalRegistros", len(items))))
        if isinstance(total, dict):
            total = total.get("value", len(items))

        # Build keyword display for response
        keywords_used = []
        for kw in req.keywords:
            if kw in KEYWORD_MAPPING:
                keywords_used.append(KEYWORD_MAPPING[kw]["display_name"])

        return {
            "success": True,
            "items": [item.model_dump() for item in items],
            "total": total,
            "query_used": query_used,
            "keywords_display": keywords_used,
            "ufs": req.ufs,
        }
    except httpx.HTTPError as e:
        logger.error(f"PNCP monitor HTTP error: {e}")
        return {"success": False, "error": "Erro de conexão com a API do PNCP."}
    except Exception as e:
        logger.error(f"PNCP monitor error: {e}")
        return {"success": False, "error": "Ocorreu um erro inesperado no monitor do PNCP."}


# --- Extract PDF endpoint ---

PNCP_API_BASE = "https://pncp.gov.br/pncp-api/v1"
PDF_DOWNLOAD_TIMEOUT = 60  # seconds per file


class PncpExtractPdfRequest(BaseModel):
    """Request to extract text from PNCP edital PDFs.

    Provide either edital_id (DB lookup) or cnpj + ano + sequencial directly.
    """
    edital_id: Optional[int] = None
    cnpj: Optional[str] = None
    ano: Optional[int] = None
    sequencial: Optional[int] = None
    save_to_db: bool = Field(default=True, description="Cache extracted text in pncp_editais")


class PncpArquivo(BaseModel):
    sequencial: int
    titulo: str
    tipo: str
    url: str
    paginas: int = 0
    caracteres: int = 0
    extraido: bool = False
    erro: Optional[str] = None


class PncpExtractPdfResponse(BaseModel):
    success: bool
    edital_id: Optional[int] = None
    cnpj: str = ""
    ano: int = 0
    sequencial: int = 0
    texto_completo: str = ""
    total_paginas: int = 0
    total_caracteres: int = 0
    arquivos: list[PncpArquivo] = []
    error: Optional[str] = None


@router.post("/iatr/extract-pdf", response_model=PncpExtractPdfResponse)
@limiter.limit("20/minute")
async def extract_pdf_from_pncp(
    request: Request,
    req: PncpExtractPdfRequest,
    _key: str = Depends(verify_internal_key),
    pool: asyncpg.Pool = Depends(get_db),
):
    """Download PDFs from PNCP API, extract text, and return consolidated result.

    Flow:
    1. Resolve CNPJ/ano/seq from edital_id (DB) or request params
    2. Check if text already cached in DB
    3. Fetch file list from PNCP /arquivos endpoint
    4. Download each PDF and extract text with pypdf
    5. Consolidate and optionally save to DB
    """
    try:
        cnpj, ano, seq, edital_id = await _resolve_edital_params(req, pool)

        # Check cache: if text already extracted, return it
        if edital_id and req.save_to_db:
            cached = await pool.fetchrow(
                """SELECT texto_completo, texto_total_paginas, texto_total_caracteres,
                          arquivos_pncp, texto_extraido_em
                   FROM pncp_editais WHERE id = $1
                   AND texto_completo IS NOT NULL""",
                edital_id,
            )
            if cached:
                import json
                arquivos_raw = json.loads(cached["arquivos_pncp"]) if cached["arquivos_pncp"] else []
                logger.info(f"Returning cached text for edital {edital_id}")
                return PncpExtractPdfResponse(
                    success=True,
                    edital_id=edital_id,
                    cnpj=cnpj,
                    ano=ano,
                    sequencial=seq,
                    texto_completo=cached["texto_completo"],
                    total_paginas=cached["texto_total_paginas"] or 0,
                    total_caracteres=cached["texto_total_caracteres"] or 0,
                    arquivos=[PncpArquivo(**a) for a in arquivos_raw],
                )

        # Fetch file list from PNCP API
        arquivos_url = f"{PNCP_API_BASE}/orgaos/{cnpj}/compras/{ano}/{seq}/arquivos"
        logger.info(f"Fetching PNCP files: {arquivos_url}")

        async with httpx.AsyncClient(timeout=PNCP_TIMEOUT) as client:
            resp = await client.get(arquivos_url)

        if resp.status_code != 200:
            return PncpExtractPdfResponse(
                success=False,
                cnpj=cnpj, ano=ano, sequencial=seq, edital_id=edital_id,
                error=f"PNCP API returned {resp.status_code} for file listing",
            )

        files_data = resp.json()
        if not isinstance(files_data, list) or len(files_data) == 0:
            return PncpExtractPdfResponse(
                success=False,
                cnpj=cnpj, ano=ano, sequencial=seq, edital_id=edital_id,
                error="No files found for this edital",
            )

        # Download and extract text from each PDF
        all_texts = []
        arquivos_result = []
        total_pages = 0

        async with httpx.AsyncClient(timeout=PDF_DOWNLOAD_TIMEOUT, follow_redirects=True) as client:
            for file_info in files_data:
                titulo = file_info.get("titulo", "")
                file_url = file_info.get("url", "")
                seq_doc = file_info.get("sequencialDocumento", 0)
                tipo_doc = file_info.get("tipoDocumentoNome", "")

                try:
                    logger.info(f"Downloading: {titulo} ({file_url})")
                    dl_resp = await client.get(file_url)
                    if dl_resp.status_code != 200:
                        arquivos_result.append(PncpArquivo(
                            sequencial=seq_doc,
                            titulo=titulo,
                            tipo=tipo_doc,
                            url=file_url,
                            extraido=False,
                            erro=f"Download failed: HTTP {dl_resp.status_code}",
                        ))
                        continue

                    file_bytes = dl_resp.content
                    fmt = detect_format(file_bytes)

                    if fmt == "pdf":
                        result = extract_text_from_pdf(file_bytes)
                        if result["success"] and result["text"].strip():
                            text = result["text"]
                            pages = result["pages"]
                            all_texts.append(f"=== {titulo} ({pages} páginas) ===\n\n{text}")
                            total_pages += pages
                            arquivos_result.append(PncpArquivo(
                                sequencial=seq_doc, titulo=titulo, tipo=tipo_doc,
                                url=file_url, paginas=pages,
                                caracteres=len(text), extraido=True,
                            ))
                        else:
                            arquivos_result.append(PncpArquivo(
                                sequencial=seq_doc, titulo=titulo, tipo=tipo_doc,
                                url=file_url, extraido=False,
                                erro=result.get("error", "Empty text extracted"),
                            ))

                    elif fmt == "zip":
                        zip_results = extract_texts_from_zip(file_bytes)
                        any_extracted = False
                        for zr in zip_results:
                            inner_name = zr.get("filename", "unknown")
                            if zr["success"] and zr["text"].strip():
                                text = zr["text"]
                                pages = zr["pages"]
                                label = f"{titulo}/{inner_name}" if inner_name != "(archive)" else titulo
                                all_texts.append(f"=== {label} ({pages} páginas) ===\n\n{text}")
                                total_pages += pages
                                any_extracted = True
                        extracted_count = sum(1 for zr in zip_results if zr["success"] and zr["text"].strip())
                        total_inner = len(zip_results)
                        inner_pages = sum(zr["pages"] for zr in zip_results if zr["success"])
                        inner_chars = sum(len(zr["text"]) for zr in zip_results if zr["success"])
                        if any_extracted:
                            zip_erro = None
                        elif total_inner == 0:
                            zip_erro = "ZIP contained no processable files"
                        else:
                            zip_erro = f"ZIP: {extracted_count}/{total_inner} files extractable"
                        arquivos_result.append(PncpArquivo(
                            sequencial=seq_doc, titulo=titulo, tipo=tipo_doc,
                            url=file_url, paginas=inner_pages,
                            caracteres=inner_chars, extraido=any_extracted,
                            erro=zip_erro,
                        ))

                    elif fmt == "docx":
                        result = extract_text_from_docx(file_bytes)
                        if result["success"] and result["text"].strip():
                            text = result["text"]
                            all_texts.append(f"=== {titulo} (DOCX) ===\n\n{text}")
                            arquivos_result.append(PncpArquivo(
                                sequencial=seq_doc, titulo=titulo, tipo=tipo_doc,
                                url=file_url, paginas=0,
                                caracteres=len(text), extraido=True,
                            ))
                        else:
                            arquivos_result.append(PncpArquivo(
                                sequencial=seq_doc, titulo=titulo, tipo=tipo_doc,
                                url=file_url, extraido=False,
                                erro=result.get("error", "Empty text from DOCX"),
                            ))

                    elif fmt in ("xlsx", "odt"):
                        # Recognized format but extraction not implemented yet.
                        # Log clearly so logs are actionable (not silent empty ZIP result).
                        logger.info(f"Recognized unsupported format ({fmt.upper()}): {titulo}")
                        arquivos_result.append(PncpArquivo(
                            sequencial=seq_doc, titulo=titulo, tipo=tipo_doc,
                            url=file_url, extraido=False,
                            erro=f"Formato reconhecido mas não suportado: {fmt.upper()}",
                        ))

                    else:
                        arquivos_result.append(PncpArquivo(
                            sequencial=seq_doc, titulo=titulo, tipo=tipo_doc,
                            url=file_url, extraido=False,
                            erro=f"Formato desconhecido (magic bytes: {file_bytes[:4].hex()})",
                        ))

                except Exception as e:
                    logger.warning(f"Error processing {titulo}: {e}")
                    arquivos_result.append(PncpArquivo(
                        sequencial=seq_doc,
                        titulo=titulo,
                        tipo=tipo_doc,
                        url=file_url,
                        extraido=False,
                        erro=str(e),
                    ))

        texto_completo = "\n\n".join(all_texts)
        total_chars = len(texto_completo)

        # Save to DB if requested and edital_id exists
        if req.save_to_db and edital_id and texto_completo:
            import json
            arquivos_json = json.dumps(
                [a.model_dump() for a in arquivos_result],
                ensure_ascii=False,
            )
            await pool.execute(
                """UPDATE pncp_editais
                   SET texto_completo = $1,
                       texto_extraido_em = $2,
                       texto_total_paginas = $3,
                       texto_total_caracteres = $4,
                       arquivos_pncp = $5::jsonb
                   WHERE id = $6""",
                texto_completo,
                datetime.now(timezone.utc),
                total_pages,
                total_chars,
                arquivos_json,
                edital_id,
            )
            logger.info(f"Saved extracted text for edital {edital_id}: {total_pages} pages, {total_chars} chars")

        return PncpExtractPdfResponse(
            success=True,
            edital_id=edital_id,
            cnpj=cnpj,
            ano=ano,
            sequencial=seq,
            texto_completo=texto_completo,
            total_paginas=total_pages,
            total_caracteres=total_chars,
            arquivos=arquivos_result,
        )

    except ValueError as e:
        return PncpExtractPdfResponse(success=False, error=str(e))
    except httpx.TimeoutException:
        return PncpExtractPdfResponse(
            success=False,
            error="Timeout downloading files from PNCP",
        )
    except Exception as e:
        logger.exception(f"extract-pdf error: {e}")
        return PncpExtractPdfResponse(
            success=False,
            error=f"Internal error: {str(e)}",
        )


async def _resolve_edital_params(
    req: PncpExtractPdfRequest,
    pool: asyncpg.Pool,
) -> tuple[str, int, int, int | None]:
    """Resolve CNPJ/ano/seq from request. Returns (cnpj, ano, seq, edital_id).

    DB schema: pncp_editais has pncp_id (format: 'CNPJ-1-SEQ/ANO') and
    url_pncp (format: 'https://pncp.gov.br/app/editais/CNPJ/ANO/SEQ').
    We parse CNPJ/ano/seq from url_pncp when doing DB lookups.
    """
    if req.edital_id:
        row = await pool.fetchrow(
            "SELECT id, url_pncp FROM pncp_editais WHERE id = $1",
            req.edital_id,
        )
        if not row:
            raise ValueError(f"Edital {req.edital_id} not found in database")
        cnpj, ano, seq = _parse_url_pncp(row["url_pncp"])
        return cnpj, ano, seq, row["id"]

    if req.cnpj and req.ano and req.sequencial:
        # Try to find in DB for caching — match by url_pncp pattern
        expected_url = f"https://pncp.gov.br/app/editais/{req.cnpj}/{req.ano}/{req.sequencial}"
        row = await pool.fetchrow(
            "SELECT id FROM pncp_editais WHERE url_pncp = $1",
            expected_url,
        )
        edital_id = row["id"] if row else None
        return req.cnpj, req.ano, req.sequencial, edital_id

    raise ValueError("Provide either edital_id or cnpj + ano + sequencial")


def _parse_url_pncp(url: str) -> tuple[str, int, int]:
    """Parse CNPJ/ano/seq from url_pncp like 'https://pncp.gov.br/app/editais/CNPJ/ANO/SEQ'."""
    import re
    m = re.search(r"/editais/(\d+)/(\d+)/(\d+)", url)
    if not m:
        raise ValueError(f"Cannot parse CNPJ/ano/seq from url: {url}")
    return m.group(1), int(m.group(2)), int(m.group(3))


# --- Enrich Edital endpoint ---

PNCP_CONSULTA_BASE = "https://pncp.gov.br/api/consulta/v1"


class PncpEnrichRequest(BaseModel):
    edital_id: int | None = None
    cnpj: str | None = None
    ano: int | None = None
    sequencial: int | None = None


class PncpEnrichResponse(BaseModel):
    success: bool
    edital_id: int | None = None
    detalhes_ok: bool = False
    itens_count: int = 0
    arquivos_count: int = 0
    error: str | None = None


def parse_pncp_id(pncp_id: str) -> tuple[str, int, int]:
    """Parse pncp_id format 'CNPJ-ESFERA-SEQ/ANO' into (cnpj, ano, seq).

    Example: '42498600000171-1-000313/2026' -> ('42498600000171', 2026, 313)
    Raises ValueError with a clear message if format is unexpected.
    """
    try:
        parts = pncp_id.split("-")
        if len(parts) < 3:
            raise ValueError(f"Expected at least 3 dash-separated parts, got {len(parts)}")
        cnpj = parts[0]
        if len(cnpj) != 14 or not cnpj.isdigit():
            raise ValueError(f"CNPJ part '{cnpj}' is not 14 digits")
        rest = "-".join(parts[2:])  # Everything after esfera: '000313/2026'
        if "/" not in rest:
            raise ValueError(f"Missing '/' in seq/ano part: '{rest}'")
        seq_str, ano_str = rest.split("/", 1)
        return cnpj, int(ano_str), int(seq_str)
    except (ValueError, IndexError) as e:
        raise ValueError(f"Cannot parse pncp_id '{pncp_id}': {e}") from e


@router.post("/iatr/enrich-edital", response_model=PncpEnrichResponse)
@limiter.limit("30/minute")
async def enrich_edital(
    req: PncpEnrichRequest,
    request: Request,
    _key=Depends(verify_internal_key),
    pool: asyncpg.Pool = Depends(get_db),
):
    """Fetch details, items, and documents list from PNCP API and save to DB."""
    import json as json_module

    edital_id = req.edital_id
    cnpj, ano, seq = req.cnpj, req.ano, req.sequencial

    if edital_id and not (cnpj and ano and seq):
        row = await pool.fetchrow(
            "SELECT pncp_id FROM pncp_editais WHERE id = $1", edital_id
        )
        if not row:
            return PncpEnrichResponse(success=False, error="Edital not found")
        try:
            cnpj, ano, seq = parse_pncp_id(row["pncp_id"])
        except ValueError as e:
            return PncpEnrichResponse(success=False, error=str(e))

    if not (cnpj and ano and seq):
        return PncpEnrichResponse(success=False, error="Provide edital_id or cnpj+ano+sequencial")

    try:
        async with httpx.AsyncClient(timeout=PNCP_TIMEOUT) as client:
            # 1. Full details (different base URL: api/consulta/v1)
            details_url = f"{PNCP_CONSULTA_BASE}/orgaos/{cnpj}/compras/{ano}/{seq}"
            det_resp = await client.get(details_url)
            detalhes = det_resp.json() if det_resp.status_code == 200 else None

            # 2. Items (up to 500, covers virtually all editais)
            itens_url = f"{PNCP_API_BASE}/orgaos/{cnpj}/compras/{ano}/{seq}/itens?pagina=1&tamanhoPagina=500"
            itens_resp = await client.get(itens_url)
            itens = itens_resp.json() if itens_resp.status_code == 200 else None

            # 3. Documents list
            arqs_url = f"{PNCP_API_BASE}/orgaos/{cnpj}/compras/{ano}/{seq}/arquivos"
            arqs_resp = await client.get(arqs_url)
            arquivos = arqs_resp.json() if arqs_resp.status_code == 200 else None

        if edital_id:
            await pool.execute(
                """UPDATE pncp_editais
                   SET pncp_detalhes = COALESCE($1::jsonb, pncp_detalhes),
                       pncp_itens = COALESCE($2::jsonb, pncp_itens),
                       arquivos_pncp = COALESCE($3::jsonb, arquivos_pncp),
                       enriquecido_em = NOW(),
                       updated_at = NOW()
                   WHERE id = $4""",
                json_module.dumps(detalhes, ensure_ascii=False) if detalhes else None,
                json_module.dumps(itens, ensure_ascii=False) if itens else None,
                json_module.dumps(arquivos, ensure_ascii=False) if arquivos else None,
                edital_id,
            )

        return PncpEnrichResponse(
            success=True,
            edital_id=edital_id,
            detalhes_ok=detalhes is not None,
            itens_count=len(itens) if isinstance(itens, list) else 0,
            arquivos_count=len(arquivos) if isinstance(arquivos, list) else 0,
        )
    except httpx.TimeoutException:
        return PncpEnrichResponse(success=False, error="Timeout fetching from PNCP API")
    except Exception as e:
        logger.exception(f"enrich-edital error: {e}")
        return PncpEnrichResponse(success=False, error=str(e))
