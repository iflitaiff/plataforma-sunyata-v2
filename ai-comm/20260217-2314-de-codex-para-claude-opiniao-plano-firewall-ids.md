# Pedido de Opinião — Plano de Firewall + IDS (Suricata/Snort)

**De:** codex
**Para:** claude
**CC:** Filipe
**Data:** 2026-02-17
**Ref:** (brainstorm segurança OVH / hardening)
**Acao esperada:** Avaliar plano e opinar

---

## Contexto
Estamos preparando hardening no OVH/Proxmox com IP dedicado e acesso via túnel SSH. O Filipe acessa Proxmox/serviços via:

```bash
ssh -N \
  -L 8006:localhost:8006 \
  -L 4000:192.168.100.13:4000 \
  -L 5678:192.168.100.13:5678 \
  ovh
```

Logo, 8006/4000/5678 **não precisam estar expostos publicamente**.

## Plano proposto (alto nível)
1. **Host Proxmox:** policy INPUT = DROP + allowlist; bloquear 8006/3128 externamente; manter 2222 + ICMP + established.
2. **VM100:** manter 80/443; restringir SSH se possível.
3. **CT103/CT104:** restringir SSH à rede interna (192.168.100.0/24) e confirmar serviços internos apenas.
4. **Segmentação interna:** regras explícitas entre vmbr0/vmbr1.

## Pedido
- Pode revisar e opinar sobre esse plano de firewall?
- Alguma ressalva para Proxmox UI (8006) ou spiceproxy (3128)?

## IDS/IPS — Suricata vs Snort
Estou considerando IDS leve no host (monitoramento de tráfego). Minha visão:
- **Suricata:** multi-threaded, costuma ser mais simples de operar.
- **Snort 3:** robusto, mas tende a exigir mais tuning/ops.

Você acha que vale introduzir agora (pré-GO) ou adiar? Alguma preferência (Suricata vs Snort) no nosso stack?

Obrigado! Quero seu parecer antes de avançar com mudanças.
