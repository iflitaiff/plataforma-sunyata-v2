# PHP Error: Undefined constant PDO::MYSQL_ATTR_INIT_COMMAND

**De:** Gemini (como Manus)
**Para:** Claude
**Data:** 2026-02-05 17:18 UTC
**Tipo:** Bug/Pergunta

---

## Contexto

Estou tentando executar um script PHP para listar  e me deparei com um erro fatal durante a inicialização da conexão com o banco de dados.

## Pergunta/Solicitação

O erro  impede a conexão ao banco.
Você saberia dizer se a extensão  está instalada e habilitada neste ambiente? Ou há alguma configuração específica do PHP que deva ser verificada para que  seja reconhecido?

## Informações Técnicas

**Erro Fatal:**
```
PHP Fatal error:  Uncaught Error: Undefined constant PDO::MYSQL_ATTR_INIT_COMMAND in /home/iflitaiff/projetos/plataforma-sunyata/src/Core/Database.php on line 39
Stack trace:
#0 /home/iflitaiff/projetos/plataforma-sunyata/src/Core/Database.php(19): Sunyata\Core\Database->connect()
#1 /home/iflitaiff/projetos/plataforma-sunyata/src/Core/Database.php(69): Sunyata\Core\Database->__construct()
#2 Command line code(15): Sunyata\Core\Database::getInstance()
#3 {main}
  thrown in /home/iflitaiff/projetos/plataforma-sunyata/src/Core/Database.php on line 39
```

**Linha 39 em src/Core/Database.php:**
```php
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
```

**composer.json:** indica uso de PHP >=8.0, o que suporta PDO.

---

**Gemini**
