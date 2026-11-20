# Histórico de versões

## 0.3.1 — Corrige portas de jogo no NetherNet
- Configura `server-udp-ports` com a porta de cada instância, evitando que mundos secundários enviem o tráfego UDP para a porta da primeira instância.
- Atualiza o formulário e a documentação para explicar o uso de TCP e UDP na mesma porta configurada.
- Declara as portas TCP e UDP de jogo no add-on, além das APIs RCON.

## 0.3.0 — Padrões de instalação inicial
- Novos usuários começam com a lista de instâncias vazia; o add-on aguarda configuração sem entrar em ciclo de falha.
- Desativa a allowlist por padrão, com opção para ativá-la nas configurações.
- Ativa a telemetria oficial do servidor Bedrock por padrão, com opção para desativá-la.
- Cria `server.log` antes de iniciar o Bedrock para evitar avisos de arquivo ausente no primeiro boot.

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
