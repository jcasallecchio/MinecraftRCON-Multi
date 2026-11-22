# MineDev Web: instalação e primeiro acesso

Este add-on hospeda os arquivos de `console/` em `/share/htdocs/minedev` e mantém `.env` e `storage/` persistentes. O mesmo `/share` é acessível ao MinecraftRCON Multi, para que os cálculos do mapa ocorram no Home Assistant. O MineDev Web não abre as portas da API RCON na internet.

1. Instale e inicie MinecraftRCON Multi com ao menos uma instância. Configure um `rcon_token` de pelo menos 32 caracteres e anote os IDs na ordem da lista.
2. Instale e inicie MineDev Web. A porta HTTP local padrão é `8099`; ajuste-a no painel de rede se houver conflito. No terminal/SSH do Home Assistant com acesso ao Docker, descubra o nome do contêiner com `docker ps --format '{{.Names}}'` (procure `minedev_web`).
3. Execute o inicializador uma vez, substituindo o nome do contêiner:

~~~sh
docker exec NOME_DO_CONTAINER_MINDEV sh -c 'cd /share/htdocs/minedev && php tools/initialize.php && chgrp www-data .env && chmod 0640 .env'
~~~

Guarde a senha mostrada; ela não é gravada em texto puro. O instalador não sobrescreve um `.env` existente. Edite `/share/htdocs/minedev/.env` por um editor local/compartilhamento seguro. Defina `BEDROCK_API_TOKEN` com o token do add-on e `BEDROCK_INSTANCES` com ID, nome e URL de cada API, seguindo o [guia do MineDev](../console/README.md#2-crie-o-login-e-conecte-ao-bedrock). O mesmo token pode ser referenciado com `${BEDROCK_API_TOKEN}` em cada instância.

4. Abra `http://IP_DO_HA:8099/minedev/` na rede local e faça login. Confira que cada opção do seletor mostra a instância correta. Para instalar como aplicativo no celular ou acessar externamente, publique o painel por HTTPS ou use uma VPN com acesso seguro; não encaminhe as portas RCON `19233–19237/TCP`.
5. Verifique no navegador que `/minedev/.env.example` e `/minedev/tools/initialize.php` retornam HTTP **403**. Se não retornarem, pare o add-on e corrija o Apache antes de usar o painel. A porta `8099` é HTTP; configure HTTPS no proxy/rede apropriado para acesso fora da LAN.

O add-on copia o código da versão instalada para `/share/htdocs/minedev` ao iniciar, sem substituir `.env` e arquivos gerados em `storage/`. Uma atualização do MineDev Web sincroniza o código, mas preserva o login, mapas e histórico. Para mapa de seed, mantenha `seed_storage_path=/share/htdocs/minedev/storage` no MinecraftRCON Multi e siga [o roteiro de instalação](../docs/instalacao-do-zero.md#4-preparar-o-mapa).
