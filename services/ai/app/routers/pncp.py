"""
PNCP (Portal Nacional de Contratacoes Publicas) search router.

Proxies search requests to the PNCP public API and normalizes results.
PNCP uses an ElasticSearch-backed API at https://pncp.gov.br/api/search/
"""

import logging
from typing import Optional

import httpx
from fastapi import APIRouter, Depends
from pydantic import BaseModel, Field

from ..dependencies import verify_internal_key

logger = logging.getLogger(__name__)
router = APIRouter()

PNCP_SEARCH_URL = "https://pncp.gov.br/api/search/"
PNCP_TIMEOUT = 30  # seconds


# --- Models ---

class PncpSearchRequest(BaseModel):
    q: str = Field(..., min_length=1, description="Search keywords")
    pagina: int = Field(default=1, ge=1)
    tipos_documento: Optional[str] = None
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
async def pncp_search(
    req: PncpSearchRequest,
    _key: str = Depends(verify_internal_key),
):
    """Search PNCP for public procurement notices."""
    try:
        params = {"q": req.q, "pagina": req.pagina}

        if req.tipos_documento:
            params["tipos_documento"] = req.tipos_documento
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
                titulo=source.get("titulo", source.get("title", "")),
                objeto=source.get("objeto", source.get("description", source.get("objetoCompra", ""))),
                orgao=source.get("orgao", source.get("nomeOrgao", source.get("orgaoEntidade", {}).get("razaoSocial", ""))),
                uf=source.get("uf", source.get("municipio", {}).get("uf", "") if isinstance(source.get("municipio"), dict) else ""),
                modalidade=_extract_modalidade(source),
                status=source.get("status", source.get("situacaoCompra", "")),
                valor_estimado=_extract_valor(source),
                data_publicacao=source.get("dataPublicacao", source.get("dataPublicacaoPncp", None)),
                data_abertura=source.get("dataAbertura", source.get("dataAberturaPropostas", None)),
                hora_abertura=source.get("horaAbertura", None),
                url_pncp=_build_pncp_url(source),
                url_edital=source.get("urlEdital", source.get("linkSistemaOrigem", None)),
                numero_licitacao=source.get("numeroLicitacao", source.get("numeroCompra", "")),
                numero_processo=source.get("numeroProcesso", source.get("processo", "")),
                uasg=source.get("uasg", source.get("codigoUnidadeCompradora", "")),
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
            error=str(e),
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
    """Build URL to view the item on PNCP portal."""
    # Try direct URL first
    for key in ("urlPncp", "url_pncp", "linkSistemaOrigem"):
        if source.get(key):
            return source[key]

    # Build from identifiers
    cnpj = source.get("cnpjOrgao", source.get("cnpj", ""))
    ano = source.get("anoCompra", "")
    seq = source.get("sequencialCompra", source.get("numeroCompra", ""))
    if cnpj and ano and seq:
        return f"https://pncp.gov.br/app/editais/{cnpj}/{ano}/{seq}"

    return ""
