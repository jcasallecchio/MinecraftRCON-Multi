<p align="center">
  <img src="MinecraftRCON/icon.png" width="96" alt="MinecraftRCON Multi">
</p>

<h1 align="center">MinecraftRCON Multi</h1>

<p align="center">Gerencie até cinco mundos Minecraft Bedrock simultaneamente no Home Assistant, com APIs RCON separadas e painel MineDev com seletor de instância.</p>

<p align="center">
  <a href="https://my.home-assistant.io/redirect/supervisor_add_addon_repository/?repository_url=https%3A%2F%2Fgithub.com%2Fjcasallecchio%2FMinecraftRCON-Multi"><img src="https://my.home-assistant.io/badges/supervisor_add_addon_repository.svg" alt="Adicionar repositório ao Home Assistant"></a>
</p>

## Recursos

- **Até cinco servidores Bedrock ativos ao mesmo tempo.** Cada instância tem mundo, propriedades, logs, backups, feed, estatísticas e sessão de processo próprios.
- **Portas independentes.** Cada instância recebe um par UDP para IPv4/IPv6 e uma porta TCP de API RCON. A porta de jogo é configurável.
- **Atualização automática do Bedrock.** Cada instância consulta a distribuição oficial na inicialização e preserva seu mundo e arquivos próprios durante atualizações.
- **MineDev Console multi-instância.** Selecione um servidor para ver telemetria, feed e estatísticas; envie comandos e inicie, pare ou reinicie o servidor selecionado.
- **Backups e restauração por mundo.** Agendamento, retenção e operação autenticada são separados por instância.

## Instalação rápida

1. No Home Assistant OS, abra **Configurações → Aplicativos (ou Add-ons) → Instalar aplicativo → ⋮ → Repositórios**.
2. Adicione `https://github.com/jcasallecchio/MinecraftRCON-Multi` ou use o botão acima.
3. Instale **MinecraftRCON Multi**, defina `rcon_token` com pelo menos 32 caracteres e configure `instances_json`. A configuração inicial já cria a instância `main` na porta UDP 19132.
4. Inicie o add-on e confira os logs de cada instância. No Minecraft, conecte ao IP do Home Assistant e à porta UDP configurada para o mundo.

Veja [configuração, portas e migração](MinecraftRCON/DOCS.md), [acesso remoto com DuckDNS ou DNS](docs/acesso-remoto.md) e [instalação do MineDev](console/README.md).

## Console e segurança

O add-on expõe a API HTTP autenticada em `19233/TCP` para a primeira instância, `19234/TCP` para a segunda e assim por diante. Restrinja essas portas à rede local ou VPN; não as encaminhe pela internet. Encaminhe no roteador apenas as portas do jogo que você quer disponibilizar.

O MineDev é um painel PHP opcional. Guarde URLs e tokens das instâncias no `.env` fora do controle de versão. O seletor do navegador recebe somente os nomes e IDs públicos configurados.

## Documentação

| Guia | Conteúdo |
| --- | --- |
| [Add-on, configuração e migração](MinecraftRCON/DOCS.md) | Configuração JSON, diretórios, portas, backups, API, recursos e migração de um mundo existente |
| [Acesso externo](docs/acesso-remoto.md) | Encaminhamento UDP, DuckDNS e DNS-only na Cloudflare |
| [MineDev Console](console/README.md) | Instalação, login, `.env`, associação das APIs e uso do seletor |
| [Histórico](MinecraftRCON/CHANGELOG.md) | Alterações desta linha multi-instância |

## Limites conhecidos

- O limite atual é cinco instâncias por add-on. Cada processo Bedrock usa CPU, memória, armazenamento e tráfego próprios; o número suportado na configuração não significa que qualquer host consiga manter cinco mundos carregados com bom desempenho.
- A lista é lida ao iniciar o add-on. Depois de mudar IDs, mundos ou portas, reinicie o add-on.
- A primeira instalação de cada mundo baixa sua própria distribuição Bedrock. Reserve espaço para os arquivos do servidor, mundos e backups.
- `enable-lan-visibility=false` evita que os mundos disputem os anúncios LAN na mesma rede. Adicione os servidores manualmente no jogo usando IP e porta.
- Esta é uma cópia independente do projeto [MinecraftRCON](https://github.com/jcasallecchio/MinecraftRCON). Ela não atualiza nem modifica a instalação anterior.

## Créditos

Baseado no add-on original de [jdeath](https://github.com/jdeath/homeassistant-addons). Código sob a [licença MIT](LICENSE.txt).
