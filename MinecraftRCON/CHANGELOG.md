# Histórico de versões

## 0.1.0 — Primeira versão multi-instância
- Cria o projeto e o slug Home Assistant independentes do MinecraftRCON original.
- Gerencia até cinco processos Bedrock simultâneos com IDs, mundos, diretórios, logs, backups, feeds e estatísticas isolados.
- Atribui portas UDP de jogo e portas TCP RCON independentes, validando IDs e colisões antes da inicialização.
- Adiciona controles autenticados para iniciar, parar e reiniciar uma instância, sem encerrar as demais.
- Adiciona o seletor de mundos ao MineDev e configura uma conexão/token por instância no `.env` privado.
- Documenta instalação, migração de mundos, portas, encaminhamento, DuckDNS, DNS na Cloudflare e uso do painel.
