"""
PNCP Monitor - Mapeamento de Keywords para API
Gera automaticamente singular/plural e variações
"""

KEYWORD_MAPPING = {
    "ar-condicionado": {
        "search_terms": ["ar-condicionado", "ar-condicionados", "ar condicionado", "ar condicionados"],
        "display_name": "Ar-condicionado(s)"
    },
    "computador": {
        "search_terms": ["computador", "computadores"],
        "display_name": "Computador(es)"
    },
    "microcomputador": {
        "search_terms": ["microcomputador", "microcomputadores"],
        "display_name": "Microcomputador(es)"
    },
    "notebook": {
        "search_terms": ["notebook", "notebooks"],
        "display_name": "Notebook(s)"
    },
    "tablet": {
        "search_terms": ["tablet", "tablets"],
        "display_name": "Tablet(s)"
    },
    "chromebook": {
        "search_terms": ["chromebook", "chromebooks"],
        "display_name": "Chromebook(s)"
    },
    "desktop": {
        "search_terms": ["desktop", "desktops"],
        "display_name": "Desktop(s)"
    },
    "ged": {
        "search_terms": [
            "GED",
            "gerenciamento eletrônico de documentos",
            "gerenciamento eletronico de documentos"
        ],
        "display_name": "GED (Gerenciamento Eletrônico de Documentos)",
        "brasil_only": True  # Flag para buscar apenas BR
    },
    "microfilmagem": {
        "search_terms": ["microfilmagem"],
        "display_name": "Microfilmagem",
        "brasil_only": True
    },
    "digitalizacao": {
        "search_terms": ["digitalização", "digitalizacao"],
        "display_name": "Digitalização",
        "brasil_only": True
    },
    "gestao-documentos": {
        "search_terms": [
            "gestão de documentos",
            "gestao de documentos"
        ],
        "display_name": "Gestão de Documentos",
        "brasil_only": True
    },
    "gestao-documental": {
        "search_terms": [
            "gestão documental",
            "gestao documental"
        ],
        "display_name": "Gestão Documental",
        "brasil_only": True
    },
    "estacao-trabalho": {
        "search_terms": [
            "estação de trabalho",
            "estações de trabalho",
            "estacao de trabalho",
            "estacoes de trabalho"
        ],
        "display_name": "Estação/Estações de Trabalho",
        "brasil_only": True
    },
    "escanerizacao": {
        "search_terms": [
            "escanerização",
            "escanerizacao"
        ],
        "display_name": "Escanerização",
        "brasil_only": True
    },
    "licenciamento-microsoft": {
        "search_terms": [
            "licenciamento de software microsoft",
            "licenciamento de softwares microsoft",
            "licenciamento microsoft"
        ],
        "display_name": "Licenciamento de Software(s) Microsoft",
        "brasil_only": True
    },
    "indexacao-documentos": {
        "search_terms": [
            "indexação de documentos",
            "indexacao de documentos"
        ],
        "display_name": "Indexação de Documentos",
        "brasil_only": True
    },
    "microfilme": {
        "search_terms": ["microfilme", "microfilmes"],
        "display_name": "Microfilme(s)",
        "brasil_only": True
    }
}


def build_search_query(keywords: list, uf: str = None) -> str:
    """
    Constrói query para API PNCP com OR entre termos de cada keyword
    
    Exemplo:
    keywords = ["computador", "notebook"]
    uf = "RJ"
    
    Retorna:
    q="(computador OR computadores) OR (notebook OR notebooks)"
    """
    query_parts = []
    
    for keyword_id in keywords:
        if keyword_id not in KEYWORD_MAPPING:
            continue
            
        keyword_data = KEYWORD_MAPPING[keyword_id]
        terms = keyword_data["search_terms"]
        
        # Agrupa termos da mesma keyword com OR
        # Ex: (computador OR computadores)
        keyword_query = f"({' OR '.join(terms)})"
        query_parts.append(keyword_query)
    
    # Junta todas as keywords com OR
    # Ex: (computador OR computadores) OR (notebook OR notebooks)
    full_query = " OR ".join(query_parts)
    
    return full_query


def build_pncp_api_params(form_data: dict) -> dict:
    """
    Converte dados do formulário em parâmetros da API PNCP
    
    Args:
        form_data: Dados do SurveyJS form
        
    Returns:
        dict com parâmetros para requests.get()
    """
    params = {
        "q": build_search_query(form_data["keywords"]),
        "tipos_documento": form_data.get("tipos_documento", "edital"),
        "ordenacao": "-data",  # Mais recentes primeiro
        "pagina": 1,
        "tam_pagina": 100  # Máximo de resultados
    }
    
    # Status da contratação
    if form_data.get("status_contratacao") != "todos":
        params["status"] = form_data["status_contratacao"]
    
    # UFs (se não for todas, filtra)
    # API PNCP aceita múltiplas UFs separadas por vírgula
    if form_data.get("ufs"):
        params["uf"] = ",".join(form_data["ufs"])
    
    return params


# Exemplo de uso:
if __name__ == "__main__":
    # Simula dados do formulário
    form_data = {
        "keywords": ["computador", "notebook", "ged"],
        "ufs": ["RJ", "SP"],
        "status_contratacao": "recebendo_proposta",
        "tipos_documento": "edital"
    }
    
    params = build_pncp_api_params(form_data)
    print("Parâmetros da API PNCP:")
    print(params)
    
    # Output esperado:
    # {
    #   "q": "(computador OR computadores) OR (notebook OR notebooks) OR (GED OR gerenciamento eletrônico de documentos OR gerenciamento eletronico de documentos)",
    #   "tipos_documento": "edital",
    #   "ordenacao": "-data",
    #   "pagina": 1,
    #   "tam_pagina": 100,
    #   "status": "recebendo_proposta",
    #   "uf": "RJ,SP"
    # }
