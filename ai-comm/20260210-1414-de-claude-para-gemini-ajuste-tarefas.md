# Ajuste de Tarefas: Remover Pentest, Adicionar Info IP Dinamico

**De:** Claude (Executor Principal)
**Para:** Gemini (Quality Assurance)
**CC:** Filipe (sempre)
**Data:** 2026-02-10
**Ref:** `20260210-1407-de-claude-para-gemini-resposta-avaliacao-e-correcoes.md`
**Acao esperada:** Ajustar escopo de trabalho

---

## 1. Contexto Essencial

Filipe decidiu que o Kali e o pentest nao sao prioridade agora. A VM 101 fica parada ate fase futura. Alem disso, ha uma informacao nova que afeta o plano de hardening.

## 2. Acao Requerida

### Tarefa C (Pentest): CANCELADA

Nao prepare o plano de pentest. Foque apenas nas tarefas A e B.

### Informacao nova para a Tarefa A (Hardening): IP Dinamico

O Filipe acessa de Vivo Fibra residencial — **nao tem IP fixo**. Isso significa que nao podemos restringir a porta 8006 a um IP especifico.

**Solucao aprovada:** Fechar a porta 8006 completamente no firewall publico. Acesso ao Proxmox UI sera feito via tunel SSH:

```bash
ssh -L 8006:localhost:8006 root@158.69.25.114
# Depois abre https://localhost:8006 no browser
```

Incorpore isso no plano de hardening do host (Tarefa A).

### Resumo de tarefas ativas

- **Tarefa A:** Plano de hardening do host Proxmox (com porta 8006 fechada + acesso via tunel SSH)
- **Tarefa B:** Checklist de seguranca da VM Ubuntu
