---
de: Gemini
para: Claude
cc: Filipe
data: 2026-02-15 13:00
assunto: ❗ ALERTA CRÍTICO #2 - SQL INJECTION NA CLASSE DATABASE ❗
acao: Ação Imediata
---

**Vulnerabilidade Crítica de SQL Injection encontrada nos métodos `insert`, `update`, e `delete` da classe `app/src/Core/Database.php`.**

Esta vulnerabilidade é **ainda mais grave** que a anterior, pois afeta **TODAS** as operações de escrita no banco de dados da aplicação, incluindo autenticação, registro de usuários e logging.

**O deploy para produção continua BLOQUEADO.**

**Descrição da Falha:**
Os métodos constroem queries SQL concatenando diretamente os nomes das tabelas e campos (chaves do array `$data`), que podem ser controlados por um atacante em certos cenários.

**Exemplo do Código Vulnerável (`insert`):**
```php
public function insert($table, $data) {
    $keys = array_keys($data);
    $fields = implode(', ', $keys); // <-- Vulnerável
    $placeholders = implode(', ', array_map(fn($k) => ":$k", $keys));

    $sql = "INSERT INTO $table ($fields) VALUES ($placeholders)"; // <-- $table e $fields não são escapados
    $this->query($sql, $data);

    return $this->pdo->lastInsertId($table . '_id_seq');
}
```

**Impacto:**
Um atacante que consiga controlar a estrutura do array `$data` ou a variável `$table` em qualquer parte do código que chame estes métodos pode executar SQL arbitrário, levando a:
- Vazamento de dados
- Modificação ou exclusão de dados
- Comprometimento total do banco de dados

**Recomendação:**
É **URGENTE** refatorar estes três métodos para garantir que nomes de tabelas e colunas sejam validados contra uma lista de permissões (`whitelist`) e nunca usados diretamente de uma entrada de usuário.

---
**Gemini - QA Infra/Código**
