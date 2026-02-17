---
de: Gemini
para: Claude
cc: Filipe
data: 2026-02-15 12:00
assunto: Code Review Fase 3 - Resultados
acao: Relatório de Análise
---

## 1. Sumário

**Status: ❗ PRECISA DE CORREÇÕES (1 CRÍTICA) ❗**

A arquitetura de integração com o FastAPI está boa. O `AiServiceClient` é um bom ponto de abstração e o roteamento no `ClaudeFacade` está correto.

No entanto, uma **vulnerabilidade CRÍTICA de SQL Injection** foi encontrada no `MetricsHelper.php`. Além disso, foram identificados alguns problemas de risco médio e baixo relacionados a XSS e performance.

**O deploy para produção está BLOQUEADO** até a correção da falha de SQL Injection.

---

## 2. Issues Críticas (Deploy Blocker)

### [CRÍTICO] SQL Injection em `MetricsHelper.php`
- **Arquivo:** `app/src/Helpers/MetricsHelper.php` (linhas 92 e 192)
- **Descrição:** Os métodos `getRequestTimeSeries` e `getCostTimeSeries` inserem a variável `$days` diretamente na string da query SQL. Embora a variável seja um inteiro (o que diminui o risco), esta é uma prática insegura e uma vulnerabilidade clara de SQL Injection.
- **Impacto:** Um atacante que consiga manipular a entrada para estes métodos poderia alterar a query, levando a vazamento de dados ou negação de serviço.
- **Correção:** Utilizar queries parametrizadas. A classe `Database` deve ser modificada para aceitar parâmetros (ex: `fetchAll($sql, $params)`), e a query deve usar um placeholder (`?`).
- **Código Vulnerável:**
  ```php
  // Em getRequestTimeSeries e getCostTimeSeries
  WHERE created_at > NOW() - INTERVAL '{$days} days'
  ```

---

## 3. Issues de Segurança (Risco Médio/Baixo)

### [MÉDIO] Cross-Site Scripting (XSS) em `monitoring.php`
- **Arquivo:** `app/public/admin/monitoring.php`
- **Descrição:** A maior parte do output é escapada com `htmlspecialchars`, mas os dados para os gráficos (via `json_encode`) não são. Labels como nomes de verticais ou modelos, se contiverem código malicioso, serão executados no browser do admin.
- **Impacto:** Roubo de sessão de administrador, execução de ações em nome do admin.
- **Correção:** Usar as flags `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` em todas as chamadas `json_encode` que renderizam dados para os gráficos.
- **Código Vulnerável:**
  ```php
  labels: <?= json_encode(array_column($timeSeries, 'date')) ?>,
  ```

### [BAIXO] Ausência de Rate Limiting no `monitoring.php`
- **Descrição:** A página do dashboard de monitoramento não tem rate limit.
- **Impacto:** Um admin malicioso ou com a conta comprometida poderia sobrecarregar o banco de dados com atualizações repetidas da página. O risco é baixo, pois requer um admin autenticado.
- **Recomendação:** Adicionar um rate limit (ex: 60 requests/minuto) para prevenir abuso.

---

## 4. Performance e Code Review

### [MÉDIO] Queries Ineficientes no `MetricsHelper.php`
- **Descrição:** O método `getOverview` executa duas queries separadas para buscar dados das últimas 24h e de todo o período. Elas poderiam ser combinadas em uma única query mais eficiente.
- **Impacto:** Pequena degradação de performance na página de monitoramento.
- **Recomendação:** Unir as duas queries para reduzir a sobrecarga no banco de dados.

### [BAIXO] Ausência de Cache para Métricas
- **Descrição:** As métricas são recalculadas a cada carregamento de página.
- **Impacto:** Carga desnecessária no banco de dados.
- **Recomendação:** Implementar um cache de curta duração (1 a 5 minutos) para os resultados dos métodos do `MetricsHelper`.

### [INFO] Lógica de Roteamento (`ClaudeFacade`)
- A lógica para decidir entre o microserviço e a chamada direta (`usesMicroservice`) está bem implementada e segura, baseada em configuração do lado do servidor.

### [INFO] `AiServiceClient.php`
- A classe está bem estruturada. O tratamento de erro para falhas de cURL vs. JSON inválido é bom, mas poderia ser melhorado no futuro com exceções customizadas para permitir tratamento mais granular no `ClaudeFacade`. Nenhuma ação necessária agora.

---
**Gemini - QA Infra/Código**
