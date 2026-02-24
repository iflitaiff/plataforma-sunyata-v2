"""
DataJud (CNJ) judicial process search router.

Queries the CNJ public API to retrieve judicial process metadata
for contracting organs (Features 1/2) and companies (Feature 3).

API: POST https://api-publica.datajud.cnj.jus.br/api_publica_{tribunal}/_search
Auth: APIKey (public, no registration needed)
Format: Elasticsearch DSL
"""

import asyncio
import json
import logging
import re
from datetime import datetime, timedelta, timezone
from typing import Optional

import asyncpg
import httpx
from fastapi import APIRouter, Depends, Request
from pydantic import BaseModel, Field
from slowapi import Limiter
from slowapi.util import get_remote_address

from ..dependencies import get_db, verify_internal_key

logger = logging.getLogger(__name__)
router = APIRouter()
limiter = Limiter(key_func=get_remote_address)

# --- Constants ---

DATAJUD_BASE_URL = "https://api-publica.datajud.cnj.jus.br"
DATAJUD_API_KEY = "cDZHYzlZa0JadVREZDJCendQbXY6SkJlTzNjLV9TRENyQk1RdnFKZGRQdw=="
DATAJUD_TIMEOUT = 15  # seconds per tribunal query
CACHE_HOURS = 24
MAX_PROCESSOS_PER_TRIBUNAL = 20
MAX_PROCESSOS_TOTAL = 100

# Prioritized procedural classes
CLASSES_ORGAO = [1116, 1117, 12078, 65, 12135]
CLASSES_EMPRESA = [1037, 1049, 1116, 1117]
CLASSES_CRITICAS = {1037, 1049}  # Block habilitacao (falencia, recuperacao judicial)
CLASSES_ATENCAO = {1116, 1117}  # Fiscal/financial risk

# UF -> relevant tribunais mapping
UF_TRIBUNAIS = {
    "AC": ["TJAC", "TRF1", "TRT14"],
    "AL": ["TJAL", "TRF5", "TRT19"],
    "AM": ["TJAM", "TRF1", "TRT11"],
    "AP": ["TJAP", "TRF1", "TRT8"],
    "BA": ["TJBA", "TRF1", "TRT5"],
    "CE": ["TJCE", "TRF5", "TRT7"],
    "DF": ["TJDFT", "TRF1", "TRT10"],
    "ES": ["TJES", "TRF2", "TRT17"],
    "GO": ["TJGO", "TRF1", "TRT18"],
    "MA": ["TJMA", "TRF1", "TRT16"],
    "MG": ["TJMG", "TRF1", "TRT3"],
    "MS": ["TJMS", "TRF3", "TRT24"],
    "MT": ["TJMT", "TRF1", "TRT23"],
    "PA": ["TJPA", "TRF1", "TRT8"],
    "PB": ["TJPB", "TRF5", "TRT13"],
    "PE": ["TJPE", "TRF5", "TRT6"],
    "PI": ["TJPI", "TRF1", "TRT22"],
    "PR": ["TJPR", "TRF4", "TRT9"],
    "RJ": ["TJRJ", "TRF2", "TRT1"],
    "RN": ["TJRN", "TRF5", "TRT21"],
    "RO": ["TJRO", "TRF1", "TRT14"],
    "RR": ["TJRR", "TRF1", "TRT11"],
    "RS": ["TJRS", "TRF4", "TRT4"],
    "SC": ["TJSC", "TRF4", "TRT12"],
    "SE": ["TJSE", "TRF5", "TRT20"],
    "SP": ["TJSP", "TRF3", "TRT2", "TRT15"],
    "TO": ["TJTO", "TRF1", "TRT10"],
}
TRIBUNAIS_SUPERIORES = ["STJ", "TST"]


# --- Models ---


class OrgaoProcessosRequest(BaseModel):
    edital_id: Optional[int] = Field(None, description="ID do edital (extrai CNPJ do pncp_id)")
    cnpj: Optional[str] = Field(None, description="CNPJ direto (14 digitos, sem pontuacao)")
    force_refresh: bool = Field(False, description="Ignorar cache")


class EmpresaIdoneidadeRequest(BaseModel):
    cnpj: str = Field(..., description="CNPJ da empresa (14 digitos, sem pontuacao)")
    edital_id: Optional[int] = Field(None, description="Vincular consulta a um edital")
    uf_prioridade: Optional[str] = Field(None, description="UF para priorizar tribunais")
    user_id: Optional[int] = Field(None, description="ID do usuario que consultou")


# --- Helper functions ---


def _extract_cnpj_from_pncp_id(pncp_id: str) -> Optional[str]:
    """Extract CNPJ (first 14 digits) from pncp_id format: CNPJ-esfera-seq/ano."""
    if not pncp_id:
        return None
    match = re.match(r"^(\d{14})", pncp_id)
    return match.group(1) if match else None


def _clean_cnpj(cnpj: str) -> str:
    """Remove punctuation from CNPJ, keep only digits."""
    return re.sub(r"\D", "", cnpj)


def _get_tribunais_for_uf(uf: Optional[str]) -> list[str]:
    """Get relevant tribunais for a UF, plus superior courts."""
    tribunais = list(TRIBUNAIS_SUPERIORES)
    if uf and uf.upper() in UF_TRIBUNAIS:
        tribunais.extend(UF_TRIBUNAIS[uf.upper()])
    else:
        # Fallback: major courts
        tribunais.extend(["TJSP", "TJRJ", "TJMG", "TJDFT", "TRF1", "TRF2", "TRF3"])
    # Deduplicate preserving order
    return list(dict.fromkeys(tribunais))


def _build_datajud_query(classes: list[int], size: int = MAX_PROCESSOS_PER_TRIBUNAL) -> dict:
    """Build Elasticsearch DSL query for DataJud API filtered by procedural classes."""
    return {
        "size": size,
        "query": {
            "bool": {
                "filter": [
                    {"terms": {"classe.codigo": classes}},
                ]
            }
        },
        "sort": [{"@timestamp": {"order": "desc"}}],
        "_source": [
            "numeroProcesso",
            "classe",
            "tribunal",
            "orgaoJulgador",
            "dataAjuizamento",
            "assuntos",
            "movimentos",
            "grau",
            "dataHoraUltimaAtualizacao",
        ],
    }


async def _query_tribunal(
    client: httpx.AsyncClient,
    tribunal: str,
    classes: list[int],
    size: int = MAX_PROCESSOS_PER_TRIBUNAL,
) -> list[dict]:
    """Query a single tribunal endpoint on DataJud."""
    alias = f"api_publica_{tribunal.lower()}"
    url = f"{DATAJUD_BASE_URL}/{alias}/_search"
    query = _build_datajud_query(classes, size)

    try:
        resp = await client.post(
            url,
            json=query,
            headers={
                "Authorization": f"APIKey {DATAJUD_API_KEY}",
                "Content-Type": "application/json",
            },
            timeout=DATAJUD_TIMEOUT,
        )
        if resp.status_code != 200:
            logger.warning(
                "DataJud %s returned %d: %s", tribunal, resp.status_code, resp.text[:200]
            )
            return []

        data = resp.json()
        hits = data.get("hits", {}).get("hits", [])

        processos = []
        for hit in hits:
            source = hit.get("_source", {})
            # Extract last movement
            movimentos = source.get("movimentos", [])
            ultima_mov = None
            if movimentos and isinstance(movimentos, list) and len(movimentos) > 0:
                last = movimentos[-1]
                ultima_mov = {
                    "data": last.get("dataHora", ""),
                    "nome": last.get("nome", ""),
                }

            processos.append(
                {
                    "numero": source.get("numeroProcesso", ""),
                    "classe": source.get("classe", {}),
                    "tribunal": tribunal,
                    "orgao_julgador": (source.get("orgaoJulgador") or {}).get("nome", ""),
                    "data_ajuizamento": (source.get("dataAjuizamento") or "")[:10],
                    "assuntos": source.get("assuntos", []),
                    "ultima_movimentacao": ultima_mov,
                    "grau": source.get("grau", ""),
                }
            )

        return processos

    except httpx.TimeoutException:
        logger.warning("DataJud %s timeout after %ds", tribunal, DATAJUD_TIMEOUT)
        return []
    except Exception as e:
        logger.error("DataJud %s error: %s", tribunal, e)
        return []


async def _query_datajud_parallel(
    tribunais: list[str],
    classes: list[int],
) -> tuple[list[dict], list[str]]:
    """Query multiple tribunais in parallel, return (processos, tribunais_consultados)."""
    async with httpx.AsyncClient() as client:
        tasks = [_query_tribunal(client, tribunal, classes) for tribunal in tribunais]
        results = await asyncio.gather(*tasks, return_exceptions=True)

    all_processos = []
    consulted = []
    for tribunal, result in zip(tribunais, results):
        consulted.append(tribunal)
        if isinstance(result, list):
            all_processos.extend(result)

    # Sort by data_ajuizamento descending, limit total
    all_processos.sort(key=lambda p: p.get("data_ajuizamento", ""), reverse=True)
    return all_processos[:MAX_PROCESSOS_TOTAL], consulted


def _build_resumo(processos: list[dict]) -> dict:
    """Build statistical summary from process list."""
    por_classe = {}
    por_tribunal = {}
    datas = []

    for p in processos:
        classe_nome = (p.get("classe") or {}).get("nome", "Outros")
        por_classe[classe_nome] = por_classe.get(classe_nome, 0) + 1

        tribunal = p.get("tribunal", "?")
        por_tribunal[tribunal] = por_tribunal.get(tribunal, 0) + 1

        data = p.get("data_ajuizamento", "")
        if data:
            datas.append(data)

    return {
        "por_classe": por_classe,
        "por_tribunal": por_tribunal,
        "mais_recente": max(datas) if datas else None,
        "mais_antigo": min(datas) if datas else None,
    }


def _build_alertas(processos: list[dict]) -> list[dict]:
    """Build alerts for empresa idoneidade check (Feature 3)."""
    alertas = []
    criticos = []
    atencao = []
    info = []

    for p in processos:
        codigo = (p.get("classe") or {}).get("codigo", 0)
        if codigo in CLASSES_CRITICAS:
            criticos.append(p)
        elif codigo in CLASSES_ATENCAO:
            atencao.append(p)
        else:
            info.append(p)

    for p in criticos:
        classe_nome = (p.get("classe") or {}).get("nome", "?")
        alertas.append(
            {
                "tipo": "CRITICO",
                "descricao": f"{classe_nome} encontrada (processo {p['numero']})",
                "processo": p,
            }
        )

    if atencao:
        nomes: dict[str, int] = {}
        for p in atencao:
            nome = (p.get("classe") or {}).get("nome", "?")
            nomes[nome] = nomes.get(nome, 0) + 1
        for nome, count in nomes.items():
            alertas.append(
                {
                    "tipo": "ATENCAO",
                    "descricao": f"{count} processo(s) de {nome}",
                    "processos": [
                        p
                        for p in atencao
                        if (p.get("classe") or {}).get("nome") == nome
                    ],
                }
            )

    if info:
        alertas.append(
            {
                "tipo": "INFO",
                "descricao": f"{len(info)} outro(s) processo(s) encontrado(s)",
                "processos": info,
            }
        )

    return alertas


# --- Endpoints ---


@router.post("/datajud/orgao-processos")
@limiter.limit("30/minute")
async def datajud_orgao_processos(
    request: Request,
    body: OrgaoProcessosRequest,
    pool: asyncpg.Pool = Depends(get_db),
    _key: str = Depends(verify_internal_key),
):
    """Feature 1 & 2: Search judicial processes for the contracting organ."""
    cnpj = None
    orgao_nome = None
    uf = None
    edital_id = body.edital_id

    # Resolve CNPJ
    if body.cnpj:
        cnpj = _clean_cnpj(body.cnpj)
    elif body.edital_id:
        row = await pool.fetchrow(
            "SELECT pncp_id, orgao, uf, datajud_orgao, datajud_consultado_em "
            "FROM pncp_editais WHERE id = $1",
            body.edital_id,
        )
        if not row:
            return {"error": f"Edital {body.edital_id} nao encontrado"}
        cnpj = _extract_cnpj_from_pncp_id(row["pncp_id"])
        orgao_nome = row["orgao"]
        uf = row["uf"]

        # Check cache
        if not body.force_refresh and row["datajud_consultado_em"]:
            cache_age = datetime.now(timezone.utc) - row["datajud_consultado_em"]
            if cache_age < timedelta(hours=CACHE_HOURS) and row["datajud_orgao"]:
                cached = row["datajud_orgao"]
                if isinstance(cached, str):
                    cached = json.loads(cached)
                cached["cached"] = True
                return cached
    else:
        return {"error": "Informe edital_id ou cnpj"}

    if not cnpj or len(cnpj) != 14:
        return {"error": "CNPJ invalido ou nao encontrado"}

    # Determine tribunais
    tribunais = _get_tribunais_for_uf(uf)

    logger.info("DataJud orgao query: CNPJ=%s, tribunais=%s", cnpj, tribunais)

    # Query DataJud
    processos, consultados = await _query_datajud_parallel(tribunais, CLASSES_ORGAO)

    result = {
        "cnpj": cnpj,
        "orgao": orgao_nome,
        "total_processos": len(processos),
        "tribunais_consultados": consultados,
        "resumo": _build_resumo(processos),
        "processos": processos,
        "cached": False,
        "consultado_em": datetime.now(timezone.utc).isoformat(),
    }

    # Save cache if edital_id provided
    if edital_id:
        await pool.execute(
            "UPDATE pncp_editais SET datajud_orgao = $1::jsonb, datajud_consultado_em = $2 WHERE id = $3",
            json.dumps(result, ensure_ascii=False, default=str),
            datetime.now(timezone.utc),
            edital_id,
        )

    return result


@router.post("/datajud/empresa-idoneidade")
@limiter.limit("20/minute")
async def datajud_empresa_idoneidade(
    request: Request,
    body: EmpresaIdoneidadeRequest,
    pool: asyncpg.Pool = Depends(get_db),
    _key: str = Depends(verify_internal_key),
):
    """Feature 3: Check judicial idoneidade for a company CNPJ."""
    cnpj = _clean_cnpj(body.cnpj)

    if len(cnpj) != 14:
        return {"error": "CNPJ deve ter 14 digitos"}

    # Check cache (24h, same CNPJ)
    cached_row = await pool.fetchrow(
        "SELECT resultado, created_at FROM datajud_consultas "
        "WHERE cnpj = $1 AND created_at > NOW() - INTERVAL '24 hours' "
        "ORDER BY created_at DESC LIMIT 1",
        cnpj,
    )
    if cached_row:
        cached = cached_row["resultado"]
        if isinstance(cached, str):
            cached = json.loads(cached)
        cached["cached"] = True
        return cached

    # Determine tribunais
    uf = body.uf_prioridade
    if not uf and body.edital_id:
        row = await pool.fetchrow("SELECT uf FROM pncp_editais WHERE id = $1", body.edital_id)
        if row:
            uf = row["uf"]

    tribunais = _get_tribunais_for_uf(uf)

    logger.info("DataJud empresa query: CNPJ=%s, tribunais=%s", cnpj, tribunais)

    # Query DataJud with empresa-specific classes
    processos, consultados = await _query_datajud_parallel(tribunais, CLASSES_EMPRESA)

    alertas = _build_alertas(processos)

    result = {
        "cnpj": cnpj,
        "total_processos": len(processos),
        "alertas": alertas,
        "tribunais_consultados": consultados,
        "resumo": _build_resumo(processos),
        "processos": processos,
        "cached": False,
        "consultado_em": datetime.now(timezone.utc).isoformat(),
    }

    # Save to datajud_consultas
    await pool.execute(
        "INSERT INTO datajud_consultas (cnpj, user_id, edital_id, resultado) "
        "VALUES ($1, $2, $3, $4::jsonb)",
        cnpj,
        body.user_id,
        body.edital_id,
        json.dumps(result, ensure_ascii=False, default=str),
    )

    return result
