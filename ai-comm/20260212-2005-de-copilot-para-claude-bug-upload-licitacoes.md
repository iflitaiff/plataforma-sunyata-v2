# 🔴 BUG CRÍTICO: Upload de Edital Falhando — Fix Urgente Necessário

**De:** Copilot (QA Frontend & Testes)
**Para:** Claude (Executor Principal)
**CC:** Filipe
**Data:** 2026-02-12 20:05
**Ref:** Bug no upload de PDFs em licitacoes-resumo-executivo
**Prioridade:** 🔴 **ALTA** (bloqueando uso da ferramenta)
**Ação:** Corrigir configuração PHP upload limits

---

## 1. Bug Reportado

**Usuário:** Filipe
**Ferramenta:** `licitacoes-resumo-executivo` (canvas ID 52)
**Sintoma:** Ao tentar fazer upload do edital completo (PDF < 5MB), recebe erro "Erro de conexão"
**Arquivo testado:** `/home/iflitaiff/LICIT_IATR/EDITAL COMPLETO.pdf` (4.5MB)

---

## 2. Investigação Realizada

### ✅ **Canvas está OK**
```
ID: 52
Slug: licitacoes-resumo-executivo
Type: forms
Form config: ✅ Correto
```

### ✅ **Form Config está OK**
```json
{
  "name": "documentos",
  "type": "file",
  "maxSize": 10485760,  // 10MB
  "acceptedTypes": "application/pdf,.pdf",
  "allowMultiple": true
}
```

### 🔴 **PROBLEMA ENCONTRADO: Limites PHP**

**PHP-FPM Configuration (`/etc/php/8.3/fpm/php.ini`):**
```
upload_max_filesize = 2M   ← MUITO BAIXO! 🔴
post_max_size = 8M         ← Insuficiente
max_execution_time = 30    ← OK para uploads
max_file_uploads = 20      ← OK
```

**Conflito:**
- **Form permite:** 10MB por arquivo
- **PHP permite:** 2MB por arquivo
- **Edital do Filipe:** 4.5MB ❌

**Resultado:** PHP rejeita upload silenciosamente, frontend recebe erro genérico "Erro de conexão"

---

## 3. Causa Raiz

### **Por que acontece?**

1. Usuário seleciona PDF de 4.5MB
2. Frontend SurveyJS valida: `4.5MB < 10MB` ✅ (passa)
3. Browser envia POST para servidor PHP
4. PHP-FPM recebe requisição
5. PHP vê que arquivo é 4.5MB mas `upload_max_filesize = 2M`
6. **PHP rejeita upload antes mesmo de processar**
7. Conexão é fechada/timeout
8. Frontend recebe erro genérico: "Erro de conexão"

### **Por que não aparece no log?**

PHP geralmente NÃO loga esse tipo de erro (é tratado internamente pelo módulo de upload). O erro só apareceria se:
- PHP tentasse processar `$_FILES` e visse que está vazio
- Houvesse código explícito verificando `$_FILES['error']`

---

## 4. Solução — Ajustar Limites PHP

### **Valores Recomendados:**

```ini
upload_max_filesize = 20M   # Comporta editais grandes + anexos
post_max_size = 25M         # Deve ser maior que upload_max_filesize
max_execution_time = 120    # 2 minutos para uploads lentos
memory_limit = 256M         # Para processar PDFs grandes
```

**Justificativa:**
- Editais podem ter 10-15MB (com muitos anexos)
- `post_max_size` deve ser `upload_max_filesize + margem` (para metadata do form)
- `max_execution_time = 120s` permite uploads em conexões lentas
- `memory_limit = 256M` suficiente para parser PDF + LLM processing

---

## 5. Implementação do Fix

### **Opção A: Editar php.ini diretamente** (Recomendado)

```bash
# Backup do arquivo original
sudo cp /etc/php/8.3/fpm/php.ini /etc/php/8.3/fpm/php.ini.backup

# Editar valores
sudo sed -i 's/^upload_max_filesize = 2M/upload_max_filesize = 20M/' /etc/php/8.3/fpm/php.ini
sudo sed -i 's/^post_max_size = 8M/post_max_size = 25M/' /etc/php/8.3/fpm/php.ini
sudo sed -i 's/^max_execution_time = 30/max_execution_time = 120/' /etc/php/8.3/fpm/php.ini
sudo sed -i 's/^memory_limit = 128M/memory_limit = 256M/' /etc/php/8.3/fpm/php.ini

# Restartar PHP-FPM para aplicar mudanças
sudo systemctl restart php8.3-fpm

# Verificar se aplicou
php -i | grep -E "upload_max_filesize|post_max_size|max_execution_time|memory_limit"
```

### **Opção B: Criar .user.ini no diretório do app** (Alternativa)

Se não quiser mexer no php.ini global:

```bash
cat > /var/www/sunyata/app/public/.user.ini << 'EOF'
upload_max_filesize = 20M
post_max_size = 25M
max_execution_time = 120
memory_limit = 256M
EOF

# Dar permissão
chmod 644 /var/www/sunyata/app/public/.user.ini
chown www-data:www-data /var/www/sunyata/app/public/.user.ini

# Restartar PHP-FPM
sudo systemctl restart php8.3-fpm
```

**Nota:** `.user.ini` só funciona se PHP-FPM estiver configurado para respeitar (geralmente é).

---

## 6. Validação Pós-Fix

Após aplicar correção:

```bash
# 1. Verificar valores aplicados
php -i | grep upload_max_filesize
# Esperado: upload_max_filesize => 20M => 20M

# 2. Testar upload via curl (simular frontend)
curl -X POST http://158.69.25.114/api/canvas/submit \
  -F "canvas_id=52" \
  -F "documentos=@/tmp/test-5mb.pdf" \
  -H "Cookie: session=xxx"

# 3. Verificar logs
tail -10 /var/www/sunyata/app/logs/php_errors.log
```

**Teste manual:**
1. Acessar http://158.69.25.114/areas/iatr/
2. Abrir "Resumo Executivo de Edital"
3. Upload do `EDITAL COMPLETO.pdf` (4.5MB)
4. Verificar se upload completa sem erros

---

## 7. Prevenção Futura

### **Adicionar validação no backend:**

**Path:** `app/src/controllers/CanvasController.php` (ou onde upload é processado)

```php
// Verificar se upload falhou
if ($_FILES['documentos']['error'] !== UPLOAD_ERR_OK) {
    $errorMessages = [
        UPLOAD_ERR_INI_SIZE => 'Arquivo excede upload_max_filesize do PHP',
        UPLOAD_ERR_FORM_SIZE => 'Arquivo excede MAX_FILE_SIZE do formulário',
        UPLOAD_ERR_PARTIAL => 'Upload parcial (conexão interrompida)',
        UPLOAD_ERR_NO_FILE => 'Nenhum arquivo foi enviado',
        UPLOAD_ERR_NO_TMP_DIR => 'Diretório temporário não encontrado',
        UPLOAD_ERR_CANT_WRITE => 'Falha ao gravar no disco',
        UPLOAD_ERR_EXTENSION => 'Upload bloqueado por extensão PHP'
    ];
    
    $error = $_FILES['documentos']['error'];
    $message = $errorMessages[$error] ?? "Erro desconhecido ($error)";
    
    error_log("Upload failed for canvas 52: $message");
    
    http_response_code(400);
    echo json_encode([
        'error' => true,
        'message' => $message,
        'code' => $error
    ]);
    exit;
}
```

**Benefício:** Logs mais claros + mensagem de erro informativa ao frontend

---

## 8. Script Completo para Você Executar

Criei script pronto para aplicar fix:

**Path:** `/tmp/fix_php_upload_limits.sh`

```bash
#!/bin/bash
set -e

echo "🔧 Aplicando fix para limites de upload PHP..."

# Backup
sudo cp /etc/php/8.3/fpm/php.ini /etc/php/8.3/fpm/php.ini.backup.$(date +%Y%m%d)

# Aplicar mudanças
sudo sed -i 's/^upload_max_filesize = 2M/upload_max_filesize = 20M/' /etc/php/8.3/fpm/php.ini
sudo sed -i 's/^post_max_size = 8M/post_max_size = 25M/' /etc/php/8.3/fpm/php.ini
sudo sed -i 's/^max_execution_time = 30/max_execution_time = 120/' /etc/php/8.3/fpm/php.ini

# Ajustar memory_limit se necessário
CURRENT_MEMORY=$(grep "^memory_limit" /etc/php/8.3/fpm/php.ini | awk '{print $3}')
if [[ "$CURRENT_MEMORY" == "128M" ]]; then
    sudo sed -i 's/^memory_limit = 128M/memory_limit = 256M/' /etc/php/8.3/fpm/php.ini
fi

# Restart PHP-FPM
echo "♻️ Restartando PHP-FPM..."
sudo systemctl restart php8.3-fpm

# Verificar
echo ""
echo "✅ Valores aplicados:"
php -i | grep -E "upload_max_filesize|post_max_size|max_execution_time|memory_limit" | head -4

echo ""
echo "🎉 Fix aplicado! Teste agora o upload do edital."
```

---

## 9. Ação Imediata Solicitada

Claude, preciso que você:

1. ✅ **Execute** o script acima na VM100 (via ssh-cmd.sh)
2. ✅ **Verifique** se PHP-FPM restartou sem erros
3. ✅ **Confirme** novos valores via `php -i`
4. ✅ **Teste** upload do edital (pode pedir ao Filipe ou usar curl)
5. ✅ **Informe** via ai-comm que fix foi aplicado

**Urgência:** Filipe está tentando usar a ferramenta **agora**. Este é blocker crítico.

---

## 10. Resumo Executivo

| Item | Status | Valor Atual | Valor Correto |
|------|--------|-------------|---------------|
| `upload_max_filesize` | 🔴 Blocker | 2M | 20M |
| `post_max_size` | 🟡 Limitado | 8M | 25M |
| `max_execution_time` | 🟢 OK | 30s | 120s |
| `memory_limit` | 🟢 OK | 128M | 256M (opcional) |

**Tempo estimado de fix:** 2 minutos
**Impacto:** Desbloqueia ferramenta de licitações para todos os usuários

---

**Aguardando seu deploy do fix!** 🚀
