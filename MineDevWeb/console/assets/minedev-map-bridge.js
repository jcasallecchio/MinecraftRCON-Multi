/* MineDev controls for uNmINeD's block-coordinate map. */
(() => {
  'use strict';
  if (typeof unmined === 'undefined' || !unmined.olMap || typeof ol === 'undefined') throw new Error('uNmINeD indisponível.');
  const match = location.pathname.match(/\/map\/([a-z0-9][a-z0-9_-]{0,31})(?:\/|$)/i);
  if (!match) throw new Error('Instância do mapa não identificada.');
  const instanceId = match[1].toLowerCase();
  const map = unmined.olMap;
  const gameToMap = (x, z) => ol.proj.transform([Number(x), Number(z)], unmined.dataProjection, unmined.viewProjection);
  const mapToGame = (point) => { const [x, z] = ol.proj.transform(point, unmined.viewProjection, unmined.dataProjection); return { x, z }; };
  window.MineDevMap = Object.freeze({ map, gameToMap, mapToGame, centerAt(x, z) { unmined.center([Number(x), Number(z)]); } });
  const css = document.createElement('link');
  css.rel = 'stylesheet';
  css.href = 'minedev-map-bridge.css?v=27';
  document.head.append(css);

  const panel = document.createElement('section');
  panel.className = 'minedev-map-panel';
  panel.setAttribute('aria-label', 'Ferramentas do mapa');
  panel.innerHTML = `<div class="minedev-map-heading"><strong>Explorar mundo</strong><button type="button" class="minedev-map-collapse" aria-label="Recolher ferramentas" aria-expanded="true">−</button></div>
    <div class="minedev-map-body"><form class="minedev-map-go"><label for="minedev-map-coordinates">Ir para coordenadas X, Z</label><div class="minedev-map-field"><input id="minedev-map-coordinates" autocomplete="off" placeholder="Ex.: 120, -350" maxlength="24"><button type="submit">Ir</button></div></form>
    <details class="minedev-live-players" open><summary>Jogadores ao vivo <span class="minedev-live-count">—</span></summary><p class="minedev-live-status">Consultando jogadores…</p><div class="minedev-live-list"></div></details>
    <details class="minedev-seed-details"><summary>Explorar pela seed <span class="minedev-seed-count"></span></summary><div class="minedev-seed-body"><p class="minedev-seed-status">Carregando dados da seed…</p><div class="minedev-seed-controls" hidden><details class="minedev-seed-types" open><summary>Locais e estruturas</summary><div class="minedev-seed-type-actions"><button type="button" class="minedev-seed-all">Todos disponíveis</button><button type="button" class="minedev-seed-none">Nenhum</button></div><div class="minedev-seed-type-list"></div></details></div></div></details>
    <div class="minedev-map-message" role="status" aria-live="polite"></div></div>`;
  document.body.append(panel);
  const $ = (selector) => panel.querySelector(selector);
  const coordinateInput = $('#minedev-map-coordinates');
  const message = $('.minedev-map-message');
  const hud = document.createElement('output');
  hud.className = 'minedev-map-hud';
  hud.textContent = 'X — · Z —';
  document.body.append(hud);
  const popup = document.createElement('section');
  popup.className = 'minedev-map-popup';
  popup.setAttribute('role', 'dialog');
  popup.setAttribute('aria-label', 'Detalhes do marcador');
  popup.hidden = true;
  document.body.append(popup);
  const biomeTooltip = document.createElement('div');
  biomeTooltip.className = 'minedev-map-biome-tooltip';
  biomeTooltip.hidden = true;
  biomeTooltip.setAttribute('role', 'status');
  document.body.append(biomeTooltip);
  let biomeTooltipTimer = 0;
  let selected = null;
  const liveSource = new ol.source.Vector();
  map.addLayer(new ol.layer.Vector({ source: liveSource, zIndex: 90 }));
  let liveRequestPending = false;
  let seedStructures = [];
  let seedSource = null;
  let seedImageLayer = null;
  let seedSlimeLayer = null;
  let seedBiomeAt = null;
  let seedSlimeAt = null;
  let livePlayers = [];
  let seedGeneratorVersion = '';
  let seedTileRevision = '';
  let seedTileSize = 0;
  const seedTiles = new Map();
  const pendingSeedTiles = new Set();
  const enabledTypes = new Set();
  const seedMarkerStyles = {
    spawn: ['#ffffff', 'S'], village: ['#63d471', 'V'], outpost: ['#ed6a5a', 'P'], desert_pyramid: ['#e6bd55', 'D'],
    jungle_temple: ['#3ba272', 'T'], swamp_hut: ['#72a07a', 'C'], igloo: ['#bfe8ed', 'I'], ocean_ruin: ['#4b9cd3', 'R'],
    shipwreck: ['#3577a8', 'N'], treasure: ['#ffd700', '$'], monument: ['#3755c5', 'M'], mansion: ['#864eaa', 'F'],
    ruined_portal: ['#bd62d0', 'O'], ancient_city: ['#315c8c', 'A'], trail_ruins: ['#be7d47', 'U'],
    trial_chambers: ['#4d76a8', 'X'], abandoned_camp: ['#a37c4b', 'A'], mineshaft: ['#d09b62', 'Mi'], ravine: ['#ef784b', 'R'],
    geode: ['#cc74dc', 'G'], desert_well: ['#60bdba', 'W'], lava_lake: ['#f04427', 'L'],
    dungeon_zombie: ['#72a36a', 'Z'], dungeon_skeleton: ['#d4d5ca', 'Es'], dungeon_spider: ['#68546d', 'Ar'],
    fossil: ['#eee1c8', 'Fo'], ore_vein: ['#d7a834', 'Min'], cave: ['#9ca9b2', 'C'],
  };
  const seedCatalog = [
    ['Spawn Point', 'spawn'], ['Slime Chunk', 'slime'], ['Village', 'village'], ['Ancient City', 'ancient_city'],
    ['Dungeon', 'dungeon'], ['Mansion', 'mansion'], ['Monument', 'monument'], ['Outpost', 'outpost'],
    ['Mineshaft', 'mineshaft'], ['Ruined Portal', 'ruined_portal'], ['Jungle Temple', 'jungle_temple'],
    ['Desert Temple', 'desert_pyramid'], ['Witch Hut', 'swamp_hut'], ['Treasure', 'treasure'],
    ['Shipwreck', 'shipwreck'], ['Igloo', 'igloo'], ['Ocean Ruins', 'ocean_ruin'], ['Fossil', 'fossil'],
    ['Cave', 'cave'], ['Ravine', 'ravine'], ['Lava Pool', 'lava_lake'], ['Geode', 'geode'],
    ['Ore Veins', 'ore_vein'], ['Desert Well', 'desert_well'], ['Trail Ruins', 'trail_ruins'],
    ['Trial Chamber', 'trial_chambers'], ['Camp', 'abandoned_camp'],
  ];
  function notify(text, error = false) { message.textContent = text; message.classList.toggle('error', error); }
  function formatBiomeName(value) {
    return String(value || '').replaceAll('_', ' ').trim().toLowerCase().replace(/\b\p{L}/gu, (letter) => letter.toUpperCase());
  }
  function showBiomeTooltip(name, pixel) {
    if (!name) { biomeTooltip.hidden = true; return; }
    biomeTooltip.textContent = formatBiomeName(name);
    biomeTooltip.hidden = false;
    const rect = map.getViewport().getBoundingClientRect();
    const x = rect.left + pixel[0], y = rect.top + pixel[1];
    const width = biomeTooltip.offsetWidth, height = biomeTooltip.offsetHeight;
    biomeTooltip.style.left = `${Math.max(8, Math.min(window.innerWidth - width - 8, x + 12))}px`;
    biomeTooltip.style.top = `${Math.max(8, Math.min(window.innerHeight - height - 8, y + 12))}px`;
    window.clearTimeout(biomeTooltipTimer);
    biomeTooltipTimer = window.setTimeout(() => { biomeTooltip.hidden = true; }, 2600);
  }
  function parseCoordinates(value) {
    const match = /^\s*(-?\d{1,8})\s*[,;\s]\s*(-?\d{1,8})\s*$/.exec(value);
    if (!match) return null;
    const x = Number(match[1]), z = Number(match[2]);
    return Math.abs(x) <= 30000000 && Math.abs(z) <= 30000000 ? { x, z } : null;
  }
  function select(point) { selected = { x: Math.floor(point.x), z: Math.floor(point.z) }; coordinateInput.value = `${selected.x}, ${selected.z}`; hud.textContent = `X ${selected.x} · Z ${selected.z}`; }
  function go(point) { select(point); unmined.center([selected.x, selected.z]); unmined.placeRedDotMarker([selected.x, selected.z]); }
  function closeMarkerDetails() { popup.hidden = true; popup.replaceChildren(); }
  function showMarkerDetails(kind, item) {
    const point = kind === 'player' ? item.position : item;
    if (!Number.isFinite(point?.x) || !Number.isFinite(point?.z)) return;
    const x = Math.floor(point.x), z = Math.floor(point.z);
    const category = kind === 'structure' ? 'Local da seed' : 'Jogador online';
    popup.replaceChildren();
    const heading = document.createElement('div'); heading.className = 'minedev-popup-heading';
    const label = document.createElement('span'); label.className = 'minedev-popup-category'; label.textContent = category;
    const close = document.createElement('button'); close.type = 'button'; close.className = 'minedev-popup-close'; close.setAttribute('aria-label', 'Fechar detalhes'); close.textContent = '×'; close.addEventListener('click', closeMarkerDetails);
    heading.append(label, close);
    const name = document.createElement('strong'); name.className = 'minedev-popup-title'; name.textContent = item.name;
    const details = document.createElement('dl'); details.className = 'minedev-popup-details';
    const addDetail = (term, value) => { const dt = document.createElement('dt'); dt.textContent = term; const dd = document.createElement('dd'); dd.textContent = value; details.append(dt, dd); };
    addDetail('Coordenadas', kind === 'player' && Number.isInteger(item.block?.y) ? `X ${x} · Y ${item.block.y} · Z ${z}` : Number.isInteger(item.y) ? `X ${x} · Y ${item.y} · Z ${z}` : `X ${x} · Z ${z}`);
    addDetail('Dimensão', 'Overworld');
    if (kind !== 'player') {
      const biome = seedBiomeAt?.({ x, z });
      if (biome) addDetail('Bioma em Y 64', formatBiomeName(biome));
      const nearest = livePlayers.filter((player) => player.position?.dimension === 0 && Number.isFinite(player.position.x) && Number.isFinite(player.position.z))
        .map((player) => ({ name: player.name, distance: Math.round(Math.hypot(player.position.x - x, player.position.z - z)) }))
        .sort((a, b) => a.distance - b.distance)[0];
      if (nearest) addDetail('Jogador próximo', `${nearest.name} · ${nearest.distance} blocos`);
    }
    if (kind === 'structure') addDetail('Origem', `Seed · Bedrock ${seedGeneratorVersion || '26.60'}`);
    const actions = document.createElement('div'); actions.className = 'minedev-popup-actions';
    const copy = document.createElement('button'); copy.type = 'button'; copy.textContent = 'Copiar X, Z';
    copy.addEventListener('click', async () => { try { await navigator.clipboard.writeText(`${x}, ${z}`); copy.textContent = 'Copiado'; } catch (_) { copy.textContent = 'Cópia indisponível'; } });
    const center = document.createElement('button'); center.type = 'button'; center.textContent = 'Centralizar'; center.addEventListener('click', () => go({ x, z }));
    actions.append(copy, center);
    popup.append(heading, name, details);
    popup.append(actions);
    popup.hidden = false;
  }
  function renderLivePlayers(data) {
    liveSource.clear();
    livePlayers = data.available && Array.isArray(data.players) ? data.players : [];
    const list = $('.minedev-live-list');
    list.replaceChildren();
    const status = $('.minedev-live-status');
    if (!data.available || !Array.isArray(data.players)) {
      $('.minedev-live-count').textContent = '—';
      status.textContent = 'Posições indisponíveis. Nova tentativa em instantes.';
      return;
    }
    $('.minedev-live-count').textContent = String(data.online_count);
    status.textContent = '';
    if (!data.players.length) {
      const empty = document.createElement('p'); empty.className = 'minedev-map-empty'; empty.textContent = 'Nenhum jogador online.'; list.append(empty);
    }
    for (const player of data.players) {
      if (typeof player.name !== 'string') continue;
      const position = player.position;
      const block = player.block;
      const valid = position && block && [position.x, position.y, position.z].every(Number.isFinite) &&
        [block.x, block.y, block.z].every(Number.isInteger) && [0, 1, 2].includes(position.dimension);
      const row = document.createElement(valid && position.dimension === 0 ? 'button' : 'div');
      row.className = 'minedev-live-row';
      if (row instanceof HTMLButtonElement) {
        row.type = 'button';
        row.title = 'Centralizar no jogador';
        row.addEventListener('click', () => { go(position); showMarkerDetails('player', player); });
        const feature = new ol.Feature({ geometry: new ol.geom.Point(gameToMap(position.x, position.z)) });
        feature.set('livePlayer', player);
        feature.setStyle(new ol.style.Style({
          image: new ol.style.Circle({ radius: 7, fill: new ol.style.Fill({ color: '#71dfdb' }), stroke: new ol.style.Stroke({ color: '#103034', width: 2 }) }),
          text: new ol.style.Text({ text: player.name, font: '600 12px sans-serif', offsetY: -20, fill: new ol.style.Fill({ color: '#e6fffb' }), stroke: new ol.style.Stroke({ color: '#101a12', width: 4 }) }),
        }));
        liveSource.addFeature(feature);
      }
      const name = document.createElement('strong'); name.textContent = player.name;
      const detail = document.createElement('span');
      detail.textContent = valid
        ? `${position.dimension === 0 ? 'Overworld' : position.dimension === 1 ? 'Nether' : 'End'} · ${block.x}, ${block.y}, ${block.z}`
        : 'Posição indisponível';
      row.append(name, detail); list.append(row);
    }
  }
  async function loadLivePlayers() {
    if (liveRequestPending || (window.frameElement && !window.frameElement.getClientRects().length)) return;
    liveRequestPending = true;
    try {
      const response = await fetch(`../../api.php?action=map-players&instance_id=${encodeURIComponent(instanceId)}`, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const data = await response.json();
      if (data.instance_id !== instanceId) throw new Error('Instância inesperada.');
      renderLivePlayers(data);
    } catch (_) { renderLivePlayers({ available: false }); }
    finally { liveRequestPending = false; }
  }
  function renderSeedStructures() {
    if (!seedSource) return;
    seedSource.clear();
    const extent = map.getView().calculateExtent(map.getSize());
    const corners = [mapToGame([extent[0], extent[1]]), mapToGame([extent[2], extent[3]])];
    const minX = Math.min(corners[0].x, corners[1].x), maxX = Math.max(corners[0].x, corners[1].x);
    const minZ = Math.min(corners[0].z, corners[1].z), maxZ = Math.max(corners[0].z, corners[1].z);
    const visible = seedStructures.filter((item) => enabledTypes.has(item.type) && item.x >= minX && item.x <= maxX && item.z >= minZ && item.z <= maxZ);
    const limit = 2500;
    if (visible.length > limit) {
      const center = mapToGame(map.getView().getCenter());
      visible.sort((a, b) => (a.x - center.x) ** 2 + (a.z - center.z) ** 2 - ((b.x - center.x) ** 2 + (b.z - center.z) ** 2));
    }
    for (const item of visible.slice(0, limit)) {
      const feature = new ol.Feature({ geometry: new ol.geom.Point(gameToMap(item.x, item.z)) });
      feature.set('seedStructure', item);
      const [color, icon] = seedMarkerStyles[item.type] || ['#f4c46f', item.name.slice(0, 1).toUpperCase()];
      feature.setStyle(new ol.style.Style({
        image: new ol.style.Circle({ radius: 8, fill: new ol.style.Fill({ color }), stroke: new ol.style.Stroke({ color: '#152018', width: 2 }) }),
        text: new ol.style.Text({ text: icon, font: '700 8px system-ui,sans-serif', fill: new ol.style.Fill({ color: '#101810' }), stroke: new ol.style.Stroke({ color: '#ffffff99', width: 0.5 }) }),
      }));
      seedSource.addFeature(feature);
    }
  }
  async function loadSeedTiles() {
    if (!seedTileRevision || !seedTileSize || !enabledTypes.size) return;
    const extent = map.getView().calculateExtent(map.getSize());
    const corners = [mapToGame([extent[0], extent[1]]), mapToGame([extent[2], extent[3]])];
    const x0 = Math.max(-16, Math.floor(Math.min(corners[0].x, corners[1].x) / seedTileSize));
    const x1 = Math.min(15, Math.floor(Math.max(corners[0].x, corners[1].x) / seedTileSize));
    const z0 = Math.max(-16, Math.floor(Math.min(corners[0].z, corners[1].z) / seedTileSize));
    const z1 = Math.min(15, Math.floor(Math.max(corners[0].z, corners[1].z) / seedTileSize));
    const center = mapToGame(map.getView().getCenter());
    const wanted = [];
    for (let z = z0; z <= z1; z++) for (let x = x0; x <= x1; x++) wanted.push({ x, z, distance: (x * seedTileSize + seedTileSize / 2 - center.x) ** 2 + (z * seedTileSize + seedTileSize / 2 - center.z) ** 2 });
    wanted.sort((a, b) => a.distance - b.distance);
    const includeDungeons = ['dungeon_zombie', 'dungeon_skeleton', 'dungeon_spider'].some((type) => enabledTypes.has(type));
    const includeFeatures = ['fossil', 'cave', 'ore_vein'].some((type) => enabledTypes.has(type));
    await Promise.all(wanted.slice(0, 16).map(async ({ x, z }) => {
      const tile = `${x}_${z}`;
      const key = `${tile}:d${includeDungeons ? 1 : 0}:f${includeFeatures ? 1 : 0}`;
      if (seedTiles.has(key) || pendingSeedTiles.has(key)) return;
      pendingSeedTiles.add(key);
      try {
        let data = null;
        for (let attempt = 0; attempt < 360; attempt++) {
          const response = await fetch(`../../api.php?action=seed-tile&instance_id=${encodeURIComponent(instanceId)}&x=${x}&z=${z}&dungeons=${includeDungeons ? '1' : '0'}&features=${includeFeatures ? '1' : '0'}`, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
          if (response.status === 202) {
            await new Promise((resolve) => window.setTimeout(resolve, Math.max(1000, Number(response.headers.get('Retry-After') || 5) * 1000)));
            continue;
          }
          if (!response.ok) throw new Error(`Setor ${key}: HTTP ${response.status}`);
          data = await response.json();
          break;
        }
        if (!data) throw new Error(`O cálculo do setor ${tile} ainda está em andamento; mova o mapa para tentar novamente.`);
        if (data.revision !== seedTileRevision || data.instance_id !== instanceId || !Array.isArray(data.points)) throw new Error('Setor da seed inválido.');
        seedTiles.set(key, data.points.filter((item) => typeof item.type === 'string' && typeof item.name === 'string' && Number.isFinite(item.x) && Number.isFinite(item.z)));
        if (seedTiles.size > 192) seedTiles.delete(seedTiles.keys().next().value);
      } catch (error) { notify(error.message, true); }
      finally { pendingSeedTiles.delete(key); }
    }));
    seedStructures = [...seedTiles.values()].flat();
    renderSeedStructures();
  }
  async function loadSeedLayer() {
    const status = $('.minedev-seed-status');
    try {
      const response = await fetch(`../../api.php?action=seed-layer&instance_id=${encodeURIComponent(instanceId)}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error?.message || `Falha HTTP ${response.status}`);
      if (data.edition !== 'bedrock' || data.dimension !== 'overworld' || !Array.isArray(data.structures) ||
          !Number.isInteger(data.width) || !Number.isInteger(data.height) || !Number.isInteger(data.step) ||
          !Number.isInteger(data.min_x) || !Number.isInteger(data.min_z) || !data.image_url?.startsWith('../../seed_asset.php?')) throw new Error('Dados da seed inválidos.');
      seedGeneratorVersion = typeof data.generator_version === 'string' ? data.generator_version : '';
      seedTileRevision = typeof data.tile_revision === 'string' ? data.tile_revision : '';
      seedTileSize = Number.isInteger(data.tile_size) ? data.tile_size : 0;
      seedStructures = data.structures.filter((item) => typeof item.name === 'string' && typeof item.type === 'string' && Number.isFinite(item.x) && Number.isFinite(item.z));
      const extent = [gameToMap(data.min_x, data.min_z + data.height * data.step)[0],
        gameToMap(data.min_x, data.min_z + data.height * data.step)[1],
        gameToMap(data.min_x + data.width * data.step, data.min_z)[0],
        gameToMap(data.min_x + data.width * data.step, data.min_z)[1]];
      // Seed samples cover 16 x 16 blocks. Draw below saved terrain.
      seedImageLayer = new ol.layer.Image({ source: new ol.source.ImageStatic({ url: data.image_url, imageExtent: extent, projection: unmined.viewProjection, interpolate: false }), opacity: 1, zIndex: -1 });
      map.addLayer(seedImageLayer);
      const biomeNames = new Map((data.biomes || []).filter((biome) => typeof biome.color === 'string' && typeof biome.name === 'string')
        .map((biome) => [biome.color.toLowerCase(), biome.name.replaceAll('_', ' ')]));
      const preview = new Image();
      preview.addEventListener('load', () => {
        const canvas = document.createElement('canvas'); canvas.width = 1; canvas.height = 1;
        const context = canvas.getContext('2d', { willReadFrequently: true });
        if (!context) return;
        seedBiomeAt = (point) => {
          const pixelX = Math.floor((point.x - data.min_x) / data.step), pixelZ = Math.floor((point.z - data.min_z) / data.step);
          if (pixelX < 0 || pixelZ < 0 || pixelX >= data.width || pixelZ >= data.height) return null;
          context.drawImage(preview, pixelX, pixelZ, 1, 1, 0, 0, 1, 1);
          const rgb = context.getImageData(0, 0, 1, 1).data;
          return biomeNames.get(`#${[rgb[0], rgb[1], rgb[2]].map((value) => value.toString(16).padStart(2, '0')).join('')}`) || null;
        };
      });
      preview.src = data.image_url;
      let enableSlime = () => {};
      if (data.slime_url?.startsWith('../../seed_asset.php?')) {
        seedSlimeLayer = new ol.layer.Image({ source: new ol.source.ImageStatic({ url: data.slime_url, imageExtent: extent, projection: unmined.viewProjection, interpolate: false }), opacity: 0.8, visible: false, zIndex: 20 });
        map.addLayer(seedSlimeLayer);
        const slimePreview = new Image();
        slimePreview.addEventListener('load', () => {
          const canvas = document.createElement('canvas'); canvas.width = 1; canvas.height = 1;
          const context = canvas.getContext('2d', { willReadFrequently: true });
          if (!context) return;
          seedSlimeAt = (point) => {
            const pixelX = Math.floor((point.x - data.min_x) / data.step), pixelZ = Math.floor((point.z - data.min_z) / data.step);
            if (pixelX < 0 || pixelZ < 0 || pixelX >= data.width || pixelZ >= data.height) return false;
            context.drawImage(slimePreview, pixelX, pixelZ, 1, 1, 0, 0, 1, 1);
            return context.getImageData(0, 0, 1, 1).data[3] > 0;
          };
        });
        enableSlime = () => { seedSlimeLayer.setVisible(true); if (!slimePreview.src) slimePreview.src = data.slime_url; };
      }
      seedSource = new ol.source.Vector();
      map.addLayer(new ol.layer.Vector({ source: seedSource, zIndex: 70 }));
      const counts = new Map(Object.entries(data.structure_counts || {}));
      if (!counts.size) for (const item of seedStructures) counts.set(item.type, (counts.get(item.type) || 0) + 1);
      const available = new Set(Array.isArray(data.types) ? data.types.map((item) => item.type) : seedStructures.map((item) => item.type));
      const generatedByTile = new Set(['fossil', 'cave', 'ore_vein']);
      const typeList = $('.minedev-seed-type-list');
      const dungeonRadius = Number.isInteger(data.dungeon_coverage?.radius) ? data.dungeon_coverage.radius : 0;
      const dungeonTypes = ['dungeon_zombie', 'dungeon_skeleton', 'dungeon_spider'];
      const refreshPoints = () => { renderSeedStructures(); if (seedTileRevision && enabledTypes.size) loadSeedTiles(); };
      const makeRow = (label, type, count, supported, parent = typeList) => {
        const row = document.createElement('label');
        const input = document.createElement('input'); input.type = 'checkbox'; input.disabled = !supported;
        if (type) input.dataset.type = type;
        const [color, icon] = seedMarkerStyles[type] || ['#f4c46f', label.slice(0, 1).toUpperCase()];
        const badge = document.createElement('i'); badge.className = 'minedev-seed-type-icon'; badge.style.setProperty('--seed-marker-color', color); badge.textContent = icon;
        const text = document.createElement('span'); text.textContent = !supported ? `${label} · sem cálculo` : generatedByTile.has(type) ? `${label} · por setor` : `${label} (${Number(count).toLocaleString('pt-BR')})`;
        row.append(input, badge, text); parent.append(row);
        return input;
      };
      for (const [label, type] of seedCatalog) {
        if (type === 'slime') {
          const input = makeRow(label, '', data.slime_chunks || 0, Boolean(seedSlimeLayer));
          input.dataset.layer = 'slime';
          input.addEventListener('change', () => { if (input.checked) enableSlime(); else seedSlimeLayer?.setVisible(false); });
        } else if (type === 'dungeon') {
          const group = document.createElement('div'); group.className = 'minedev-seed-dungeon'; typeList.append(group);
          const supported = dungeonTypes.some((id) => available.has(id));
          const totalDungeons = dungeonTypes.reduce((sum, id) => sum + Number(counts.get(id) || 0), 0);
          const parent = makeRow(label, '', totalDungeons, supported, group);
          const children = document.createElement('div'); children.className = 'minedev-seed-dungeon-children'; group.append(children);
          const childInputs = dungeonTypes.map((id) => {
            const mob = id === 'dungeon_zombie' ? 'Zombie' : id === 'dungeon_skeleton' ? 'Skeleton' : 'Spider';
            const input = makeRow(mob, id, counts.get(id) || 0, available.has(id), children);
            input.addEventListener('change', () => { if (input.checked) enabledTypes.add(id); else enabledTypes.delete(id); const selected = childInputs.filter((item) => item.checked).length; parent.checked = selected === childInputs.filter((item) => !item.disabled).length; parent.indeterminate = selected > 0 && !parent.checked; refreshPoints(); });
            return input;
          });
          parent.addEventListener('change', () => { for (const input of childInputs) { if (input.disabled) continue; input.checked = parent.checked; if (parent.checked) enabledTypes.add(input.dataset.type); else enabledTypes.delete(input.dataset.type); } parent.indeterminate = false; refreshPoints(); });
          if (dungeonRadius) { const scope = document.createElement('p'); scope.className = 'minedev-seed-dungeon-scope'; scope.textContent = 'Geradas por setor no mapa, conforme a área é carregada'; group.append(scope); }
        } else {
          const input = makeRow(label, type, counts.get(type) || 0, available.has(type));
          input.addEventListener('change', () => { if (input.checked) enabledTypes.add(type); else enabledTypes.delete(type); refreshPoints(); });
        }
      }
      const setTypes = (checked) => {
        enabledTypes.clear();
        for (const input of typeList.querySelectorAll('input:not(:disabled)')) {
          input.checked = checked; input.indeterminate = false;
          if (checked && input.dataset.type) enabledTypes.add(input.dataset.type);
        }
        if (checked) enableSlime(); else seedSlimeLayer?.setVisible(false);
        refreshPoints();
      };
      $('.minedev-seed-all').addEventListener('click', () => setTypes(true));
      $('.minedev-seed-none').addEventListener('click', () => setTypes(false));
      renderSeedStructures();
      map.on('moveend', () => { if (seedTileRevision && enabledTypes.size) loadSeedTiles(); else renderSeedStructures(); });
      $('.minedev-seed-controls').hidden = false;
      const total = Number(data.structure_total ?? seedStructures.length);
      $('.minedev-seed-count').textContent = String(total);
      status.textContent = `${total.toLocaleString('pt-BR')} locais · ${data.biomes?.length || 0} biomas · ${Number(data.slime_chunks || 0).toLocaleString('pt-BR')} slime chunks · Bedrock ${seedGeneratorVersion}`;
    } catch (error) { status.textContent = error.message; }
  }
  $('.minedev-map-go').addEventListener('submit', (event) => { event.preventDefault(); const point = parseCoordinates(coordinateInput.value); if (!point) return notify('Informe X e Z válidos, separados por vírgula ou espaço.', true); go(point); notify('Mapa centralizado nas coordenadas informadas.'); });
  $('.minedev-map-collapse').addEventListener('click', (event) => { panel.classList.toggle('collapsed'); const expanded = !panel.classList.contains('collapsed'); event.currentTarget.setAttribute('aria-expanded', String(expanded)); event.currentTarget.setAttribute('aria-label', expanded ? 'Recolher ferramentas' : 'Expandir ferramentas'); event.currentTarget.textContent = expanded ? '−' : '+'; });
  map.on('singleclick', (event) => {
    biomeTooltip.hidden = true;
    const feature = map.forEachFeatureAtPixel(event.pixel, (candidate) => candidate.get('livePlayer') || candidate.get('seedStructure') ? candidate : null, { hitTolerance: 8 });
    const player = feature?.get('livePlayer');
    if (player) { select(player.position); showMarkerDetails('player', player); return; }
    const item = feature?.get('seedStructure');
    if (item) { select(item); showMarkerDetails('structure', item); return; }
    closeMarkerDetails();
    const point = mapToGame(event.coordinate);
    select(point);
    const biome = seedImageLayer?.getVisible() && seedBiomeAt ? seedBiomeAt(point) : null;
    const slime = seedSlimeLayer?.getVisible() && seedSlimeAt ? seedSlimeAt(point) : false;
    showBiomeTooltip(biome, event.pixel);
    notify(slime ? 'Slime chunk' : '');
  });
  map.on('pointermove', (event) => { if (!event.dragging) { const point = mapToGame(event.coordinate); hud.textContent = `X ${Math.floor(point.x)} · Z ${Math.floor(point.z)}`; const marker = map.forEachFeatureAtPixel(event.pixel, (candidate) => candidate.get('livePlayer') || candidate.get('seedStructure') ? candidate : null, { hitTolerance: 8 }); map.getTargetElement().style.cursor = marker ? 'pointer' : ''; } });
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape') closeMarkerDetails(); });
  loadSeedLayer();
  loadLivePlayers();
  window.setInterval(() => { if (!document.hidden) loadLivePlayers(); }, 5000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) loadLivePlayers(); });
})();
