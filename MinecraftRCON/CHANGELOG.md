# Histórico de versões

## 0.3.9 — Corrige a compilação do gerador de recursos
- Gera o arquivo de somas das dependências Go antes de compilar as camadas de cavernas, fósseis e veios, corrigindo a falha de atualização da versão 0.3.8 no Supervisor.

## 0.3.8 — Camadas de cavernas, fósseis e veios
- Calcula cavernas, fósseis e veios de cobre/ferro por setor dentro do Home Assistant, sob demanda.
- Usa um gerador Bedrock adicional fixado em revisão e mantém o worker sem executar cálculos no navegador ou no PC.
- Publica os três tipos no catálogo do MineDev e os combina com as estruturas e masmorras já existentes.
- Inclui no add-on as licenças e permissões do motor Helix usado para essas camadas.

## 0.3.7 — Worker de setores executado no Home Assistant
- Processa setores de estruturas e masmorras na fila privada do MineDev dentro do add-on; o navegador e os PCs não executam os cálculos.
- Compila o gerador contra uma revisão fixa do cubiomes-bedrock durante a construção da imagem do add-on.
- Inclui opções para ativar o worker e indicar a pasta `storage` do MineDev, além de recuperação de tarefas interrompidas.
- Executa o worker com prioridade de CPU reduzida e encerra junto com o add-on.

## 0.3.6 — Reconhece mais formatos de mensagens de morte
- Aceita mensagens de morte do Bedrock com prefixos de chat/sistema e registros estruturados do servidor.
- Mantém o incremento persistente da estatística por evento de morte identificado.

## 0.3.5 — Remove registros de log das estatísticas de jogadores
- Ignora nomes incompatíveis com jogadores Bedrock ao processar eventos e telemetria.
- Remove registros inválidos já salvos na base de estatísticas da instância.

## 0.3.4 — Evita mostrar linhas de log como jogadores
- Respeita a contagem retornada pelo comando `list` e sempre mantém a lista vazia quando há zero jogadores.
- Filtra registros de log Bedrock que aparecem junto da resposta, evitando falsos jogadores na telemetria.

## 0.3.3 — Edição de server.properties no MineDev
- Expõe leitura e gravação autenticadas do arquivo de propriedades da instância selecionada.
- Salva de forma atômica, impede sobrescrita de alterações concorrentes e protege campos controlados pelo add-on.
- Permite salvar e solicitar reinício gracioso na mesma operação.
- Amplia o tamanho permitido da requisição para comportar o arquivo completo.

## 0.3.2 — Detecta conflito com outros serviços na porta do jogo
- Verifica se TCP e UDP da instância estão disponíveis antes de iniciar o Bedrock.
- Só declara o servidor pronto após o Bedrock confirmar a abertura da porta e concluir a inicialização.
- Documenta o conflito entre instâncias e proxies ou outros serviços no mesmo host.
- Permite anunciar o IPv4 público resolvido de um hostname DNS no mapeamento UDP de todas as instâncias.

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
