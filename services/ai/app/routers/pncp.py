"""
PNCP (Portal Nacional de Contratacoes Publicas) search router.

Proxies search requests to the PNCP public API and normalizes results.
PNCP uses an ElasticSearch-backed API at https://pncp.gov.br/api/search/
"""

import logging
from typing import Optional

import httpx
from fastapi import APIRouter, Depends, Request
from pydantic import BaseModel, Field
from slowapi import Limiter
from slowapi.util import get_remote_address

from ..pncp_keywords import KEYWORD_MAPPING, build_search_query, build_pncp_api_params

from ..dependencies import verify_internal_key

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
