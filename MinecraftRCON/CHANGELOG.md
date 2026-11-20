# Histórico de versões

## 0.2.0 — Formulário multi-instância
- Substitui o campo de texto JSON por uma lista nativa de instâncias com campos editáveis na configuração do add-on.
- Adiciona rótulos e descrições em português e inglês.
- Mantém compatibilidade de leitura com `instances_json` salvo pela versão 0.1.0.

## 0.1.0 — Primeira versão multi-instância
- Cria o projeto e o slug Home Assistant independentes do MinecraftRCON original.
- Gerencia até cinco processos Bedrock simultâneos com IDs, mundos, diretórios, logs, backups, feeds e estatísticas isolados.
- Atribui portas UDP de jogo e portas TCP RCON independentes, validando IDs e colisões antes da inicialização.
- Adiciona controles autenticados para iniciar, parar e reiniciar uma instância, sem encerrar as demais.
- Adiciona o seletor de mundos ao MineDev e configura uma conexão/token por instância no `.env` privado.
- Documenta instalação, migração de mundos, portas, encaminhamento, DuckDNS, DNS na Cloudflare e uso do painel.
