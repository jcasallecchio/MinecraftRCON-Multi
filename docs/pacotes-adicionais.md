# Pacotes adicionais por instância

Pacotes de comportamento e de recursos são opcionais e não acompanham este repositório. Obtenha-os de suas fontes originais e confira a licença e a compatibilidade com a versão Bedrock instalada.

Para cada mundo que deve receber um pacote:

1. Pare **somente aquela instância** no MineDev e aguarde a parada ser confirmada. Faça uma cópia do mundo e dos arquivos de pacotes antes de editar.
2. Extraia cada behavior pack em `/share/minecraftRCON-Multi/instances/<id>/behavior_packs/<pasta-do-pacote>/` e cada resource pack em `.../resource_packs/<pasta-do-pacote>/`. Cada pasta de pacote deve conter `manifest.json` na raiz. Um `.mcaddon`/`.mcpack` é um arquivo compactado; verifique seu conteúdo antes de copiar.
3. Leia `header.uuid` e `header.version` de cada `manifest.json`. Use o UUID do **header**, não o UUID em `modules`. Dentro de `worlds/<world_name>/`, crie ou atualize `world_behavior_packs.json` e `world_resource_packs.json`. Cada arquivo é **uma lista de todos os pacotes daquele tipo** usados pelo mundo:

~~~json
[
  {"pack_id":"UUID-DO-HEADER-DO-PRIMEIRO-PACOTE","version":[1,0,0]},
  {"pack_id":"UUID-DO-HEADER-DO-SEGUNDO-PACOTE","version":[1,2,0]}
]
~~~

4. Repita nas outras instâncias que devem usar os mesmos pacotes. As pastas e os dois arquivos JSON são separados por instância. Respeite dependências declaradas nos manifestos e inclua os behavior/resource packs correspondentes.
5. Inicie a instância, consulte seu log para erros de manifesto, versão ou dependência e teste a entrada no mundo. Se precisar baixar recursos no cliente, aguarde a transferência terminar.

Não copie os JSON de associação de um mundo para outro sem confirmar que os mesmos pacotes e versões estão instalados no destino. O gerador de seed usa regras da versão Bedrock modelada; pacotes que modificam a geração podem produzir diferenças entre marcadores calculados e terreno real.
