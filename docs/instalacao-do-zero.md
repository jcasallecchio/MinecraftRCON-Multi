# Instalação completa do zero

Este roteiro reúne os passos na ordem necessária para instalar o add-on, o MineDev e o mapa em um Home Assistant OS novo. O repositório inclui **dois add-ons**: MinecraftRCON Multi e MineDev Web. O uNmINeD CLI e os pacotes opcionais de conteúdo são externos; seus binários e os mundos não são distribuídos neste repositório.

## 1. Preparar o Home Assistant

- Use um host **amd64**, com Supervisor e espaço para uma cópia do Bedrock por instância, mundos, backups e mapas.
- Garanta acesso HTTPS de saída para o download oficial do Bedrock e para as dependências compiladas durante a instalação.
- Para administrar arquivos em `/share` e gerar a camada da seed, tenha um terminal/SSH com acesso ao Docker do host. Para o snapshot de terreno, tenha também acesso aos arquivos por compartilhamento e um computador Windows com PowerShell e uNmINeD CLI.

## 2. Instalar o add-on e criar os mundos

1. Adicione este repositório à loja de add-ons do Home Assistant e instale **MinecraftRCON Multi**. Veja [instalação e configuração](../MinecraftRCON/DOCS.md).
2. Configure um `rcon_token` com pelo menos 32 caracteres e adicione de uma a cinco instâncias. Anote a ordem dos IDs, os nomes das pastas dos mundos e as portas de jogo. A primeira API é `19233/TCP`, a segunda `19234/TCP` e assim por diante.
3. **Se quiser uma seed específica**, configure-a antes de iniciar a instância pela primeira vez, conforme [seed de um mundo novo](../MinecraftRCON/DOCS.md#definir-a-seed-de-um-mundo-novo). Após o mundo ser criado, alterar `level-seed` não recria o terreno existente.
4. Inicie o add-on e aguarde `Accepting clients on` para cada porta. Teste o IP local e a porta de cada mundo no Minecraft.

## 3. Instalar o MineDev

1. Instale **MineDev Web** no mesmo repositório de add-ons e inicie-o. Ele já inclui Apache/PHP, cURL, `mod_rewrite` e uma cópia do painel; publica os arquivos em `/share/htdocs/minedev`. Consulte o [primeiro acesso](../MineDevWeb/DOCS.md).
2. Inicialize o login pelo terminal do Home Assistant usando o comando do guia. Guarde a senha exibida e defina `BEDROCK_API_TOKEN` e `BEDROCK_INSTANCES` em `/share/htdocs/minedev/.env`.
3. Abra `http://IP_DO_HA:8099/minedev/` na LAN, confirme HTTP **403** para `.env.example` e `tools/initialize.php`, faça login e verifique cada instância no seletor. O add-on web mantém `storage/` em `/share`, acessível também ao worker do MinecraftRCON Multi.

Para hospedar o MineDev fora do Home Assistant, há um [guia alternativo de Apache/PHP](hospedagem-minedev.md).

## 4. Preparar o mapa

1. Para o terreno **já explorado**, baixe o [uNmINeD CLI](https://unmined.net/downloads/) do site oficial e siga [Publicar ou atualizar um mapa](../console/README.md#publicar-ou-atualizar-um-mapa) para cada instância. O snapshot é manual e exige uma parada breve apenas do mundo copiado.
2. Para biomas e locais calculados pela seed, siga [Preparar uma instalação nova do mapa](../console/README.md#preparar-uma-instalação-nova-do-mapa). O comando roda **dentro do Home Assistant** e precisa da seed verdadeira e da pasta `storage/` do MineDev no compartilhamento `/share`.
3. Ative um filtro de local no MineDev e aguarde o primeiro setor. O worker calcula e guarda os setores sob demanda; novos setores podem levar vários minutos.

Pacotes de comportamento e recursos são opcionais. Para instalá-los em cada mundo, veja [pacotes adicionais](pacotes-adicionais.md). Eles não são necessários para o add-on, o painel ou o mapa de seed funcionarem.

## 5. Acesso externo

Confirme primeiro a entrada pela rede local. Para acesso pela internet, siga [DuckDNS, Cloudflare DNS e portas](acesso-remoto.md). Encaminhe **TCP e UDP da porta de jogo de cada instância** para o Home Assistant. Nunca encaminhe `19233–19237/TCP`.

## Conferência final

- As instâncias configuradas mostram servidor pronto e aceitam jogadores pela rede local.
- `/health` de cada API responde `{"status":"ready"}` a partir do host PHP.
- O MineDev abre login, permite trocar de instância e exibe telemetria da instância correta.
- Os dois caminhos privados de teste do Apache retornam 403; `.env` e `storage/` não são públicos.
- Cada mapa mostra o snapshot publicado e/ou a camada da seed da **mesma** instância.
- Se houver acesso externo, cada porta de jogo foi testada fora do Wi-Fi; HTTP 200 na porta TCP não comprova a conexão UDP do Minecraft.

O mapa de terreno requer baixar o uNmINeD CLI do site oficial e executá-lo no Windows com acesso aos mundos por compartilhamento. Esse executável não pode ser redistribuído no repositório. O mapa de seed e todos os seus cálculos rodam no Home Assistant.
