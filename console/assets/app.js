(() => {
  'use strict';

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  const loginScreen = $('#loginScreen');
  const appShell = $('#appShell');
  const csrfMeta = $('meta[name="csrf-token"]');
  let csrf = csrfMeta?.content || '';
  let catalog = { commands: [], selectors: [] };
  let activeCategory = 'Todos';
  let historyRecords = [];
  let historyCursor = 0;
  let activityCursor = 0;
  let activityLoading = false;
  let feedInitialized = false;
  let outputMode = 'terminal';
  let playerStats = [];
  let statsLoading = false;
  let commandHistory = [];
  let historyPointer = 0;
  let selectedSuggestion = -1;
  let lastTelemetry = null;
  let activeInstance = sessionStorage.getItem('minedev-instance') || 'main';
  let pollTimer = null;
  let activityPollTimer = null;
  let propertiesRevision = '';
  let propertiesOriginal = '';
  let propertiesInstance = '';
  let propertiesBusy = false;

  const glossary = [
    { term: 'Alvo', tag: 'Sintaxe', description: 'Jogador, entidade ou seletor escolhido para receber a ação de um comando.' },
    { term: 'Seletor', tag: 'Sintaxe', description: '@a todos os jogadores · @e entidades · @p jogador próximo · @r aleatório · @s executor.' },
    { term: 'Coordenadas', tag: 'Mundo', description: 'X, Y e Z indicam posição. O til (~) representa uma posição relativa ao executor.' },
    { term: 'Cheats', tag: 'Permissão', description: 'Vários comandos de administração e edição exigem cheats habilitados no mundo.' },
    { term: 'Operador (OP)', tag: 'Permissão', description: 'Jogador com privilégios elevados dentro do Minecraft. Concedido com op e removido com deop.' },
    { term: 'Allowlist', tag: 'Servidor', description: 'Lista de jogadores autorizados a entrar. Também é conhecida como whitelist.' },
    { term: 'Gamemode', tag: 'Mundo', description: 'Modo de jogo: survival, creative, adventure ou spectator, conforme a versão.' },
    { term: 'NBT / componentes', tag: 'Itens', description: 'Dados e componentes opcionais usados por alguns comandos para personalizar itens e entidades.' },
    { term: 'Função', tag: 'Pacotes', description: 'Arquivo de comandos de um pacote de comportamento executado com function.' },
    { term: 'Tick', tag: 'Desempenho', description: 'Unidade de atualização do mundo. Um segundo normal corresponde a 20 ticks.' },
    { term: 'Console nativo', tag: 'Integração', description: 'A entrada do servidor Bedrock recebe comandos sem a barra inicial; o MineDev remove uma barra opcional.' },
    { term: 'API v1', tag: 'Integração', description: 'Contrato de comunicação versionado entre este console e o add-on Bedrock.' }
  ];

  function setCsrf(value) {
    if (!value) return;
    csrf = value;
    if (csrfMeta) csrfMeta.content = value;
  }

  async function api(action, options = {}) {
    const method = options.method || 'GET';
    const headers = { Accept: 'application/json' };
    if (method !== 'GET') headers['X-CSRF-Token'] = csrf;
    if (options.body !== undefined) headers['Content-Type'] = 'application/json';
    const query = [options.query || '', `instance_id=${encodeURIComponent(activeInstance)}`].filter(Boolean).join('&');
    const response = await fetch(`api.php?action=${encodeURIComponent(action)}&${query}`, {
      method,
      credentials: 'same-origin',
      headers,
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
      cache: 'no-store'
    });
    const contentType = response.headers.get('content-type') || '';
    const payload = contentType.includes('application/json') ? await response.json() : {};
    if (!response.ok) {
      const error = new Error(payload?.error?.message || payload?.message || `Falha HTTP ${response.status}`);
      error.status = response.status;
      error.payload = payload;
      throw error;
    }
    return payload;
  }

  function toast(message, type = '') {
    const region = $('#toastRegion');
    const item = document.createElement('div');
    item.className = `toast ${type}`.trim();
    item.textContent = message;
    region.append(item);
    window.setTimeout(() => item.remove(), 4200);
  }

  function setAuthenticated(username, token) {
    setCsrf(token);
    $('#userBadge').textContent = (username || 'AD').slice(0, 2).toUpperCase();
    loginScreen.hidden = true;
    appShell.hidden = false;
    document.body.classList.remove('guest');
    document.body.classList.add('authenticated');
    $('#commandInput').focus();
    initializeConsole();
  }

  async function loadCsrf() {
    try {
      const payload = await api('csrf');
      setCsrf(payload.csrf);
    } catch (_) {
      // The page still displays a useful login error if the server is unavailable.
    }
  }

  $('#togglePassword')?.addEventListener('click', () => {
    const input = $('#password');
    input.type = input.type === 'password' ? 'text' : 'password';
    $('#togglePassword').setAttribute('aria-label', input.type === 'password' ? 'Mostrar senha' : 'Ocultar senha');
  });

  $('#loginForm')?.addEventListener('submit', async event => {
    event.preventDefault();
    const button = $('.login-submit');
    const errorBox = $('#loginError');
    errorBox.textContent = '';
    button.disabled = true;
    button.querySelector('span').textContent = 'Validando acesso…';
    try {
      if (!csrf) await loadCsrf();
      const payload = await api('login', {
        method: 'POST',
        body: { username: $('#username').value.trim(), password: $('#password').value, csrf }
      });
      $('#password').value = '';
      setAuthenticated(payload.username, payload.csrf);
    } catch (error) {
      errorBox.textContent = error.message || 'Não foi possível iniciar a sessão.';
    } finally {
      button.disabled = false;
      button.querySelector('span').textContent = 'Entrar no console';
    }
  });

  $('#logoutButton')?.addEventListener('click', async () => {
    try { await api('logout', { method: 'POST', body: {} }); } catch (_) { /* session may already have expired */ }
    window.location.reload();
  });

  function switchTab(name) {
    if ($('#view-properties')?.classList.contains('active') && name !== 'properties' && propertiesDirty()) {
      if (!window.confirm('Há alterações não salvas em server.properties. Descartar?')) return;
      $('#propertiesEditor').value = propertiesOriginal;
      updatePropertiesButtons();
    }
    $$('.tab').forEach(tab => tab.classList.toggle('active', tab.dataset.tab === name));
    $$('.view').forEach(view => view.classList.toggle('active', view.dataset.view === name));
    const titles = { console: 'Visão geral', stats: 'Estatísticas', commands: 'Referência', properties: 'Configuração', history: 'Histórico' };
    $('#pageTitle').textContent = titles[name] || 'Visão geral';
    if (name === 'history' && historyRecords.length === 0) loadHistory(true);
    if (name === 'stats') loadPlayerStats();
    if (name === 'properties' && propertiesInstance !== activeInstance) loadProperties();
    if (name === 'console' && outputMode === 'terminal') $('#commandInput').focus();
  }
  $$('.tab').forEach(tab => tab.addEventListener('click', () => switchTab(tab.dataset.tab)));

  function propertiesDirty() {
    return !!propertiesRevision && $('#propertiesEditor').value !== propertiesOriginal;
  }
  function updatePropertiesButtons() {
    const ready = !!propertiesRevision && propertiesInstance === activeInstance && !propertiesBusy;
    $('#propertiesSave').disabled = !ready || !propertiesDirty();
    $('#propertiesSaveRestart').disabled = !ready;
    $('#propertiesEditor').disabled = !ready;
  }
  async function loadProperties() {
    const requestedInstance = activeInstance;
    propertiesBusy = true;
    propertiesRevision = '';
    propertiesInstance = '';
    $('#propertiesEditor').disabled = true;
    const propertiesState = $('#propertiesState');
    if (propertiesState) propertiesState.textContent = 'Carregando arquivo…';
    updatePropertiesButtons();
    try {
      const result = await api('server-properties');
      if (requestedInstance !== activeInstance) return;
      propertiesOriginal = result.content;
      propertiesRevision = result.revision;
      propertiesInstance = requestedInstance;
      $('#propertiesEditor').value = result.content;
      if (propertiesState) propertiesState.textContent = 'Arquivo carregado';
    } catch (error) {
      if (requestedInstance !== activeInstance) return;
      if (propertiesState) propertiesState.textContent = 'Arquivo indisponível';
      toast(error.message, 'error');
    } finally {
      if (requestedInstance === activeInstance) { propertiesBusy = false; updatePropertiesButtons(); }
    }
  }
  $('#propertiesEditor')?.addEventListener('input', updatePropertiesButtons);
  $('#propertiesReload')?.addEventListener('click', () => {
    if (propertiesDirty() && !window.confirm('Descartar alterações e recarregar o arquivo?')) return;
    loadProperties();
  });
  async function saveProperties(restart) {
    if (!propertiesRevision || propertiesBusy || propertiesInstance !== activeInstance) return;
    if (restart && !window.confirm('Salvar e reiniciar esta instância agora? Jogadores conectados serão desconectados.')) return;
    const requestedInstance = activeInstance;
    const content = $('#propertiesEditor').value;
    propertiesBusy = true;
    const propertiesState = $('#propertiesState');
    if (propertiesState) propertiesState.textContent = restart ? 'Salvando e reiniciando…' : 'Salvando…';
    updatePropertiesButtons();
    try {
      const result = await api('server-properties', { method: 'PUT', body: { content, revision: propertiesRevision, restart } });
      if (requestedInstance !== activeInstance) return;
      propertiesOriginal = content;
      propertiesRevision = result.revision;
      if (propertiesState) propertiesState.textContent = result.status === 'restarting' ? 'Reinício solicitado' : 'Arquivo salvo';
      toast(result.message || 'Propriedades salvas.', restart && result.status !== 'restarting' ? 'error' : '');
      if (result.status === 'restarting') window.setTimeout(() => refreshStatus(false), 1500);
    } catch (error) {
      if (requestedInstance !== activeInstance) return;
      if (propertiesState) propertiesState.textContent = error.status === 409 ? 'Conflito de edição' : 'Falha ao salvar';
      toast(error.message, 'error');
    } finally {
      if (requestedInstance === activeInstance) { propertiesBusy = false; updatePropertiesButtons(); }
    }
  }
  $('#propertiesSave')?.addEventListener('click', () => saveProperties(false));
  $('#propertiesSaveRestart')?.addEventListener('click', () => saveProperties(true));

  function formatUptime(seconds) {
    if (seconds === null || seconds === undefined) return '—';
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    return days ? `${days}d ${hours}h ${minutes}m` : `${hours}h ${minutes}m`;
  }
  function formatBytes(value) {
    if (value === null || value === undefined) return '—';
    const units = ['B', 'KB', 'MB', 'GB'];
    let amount = Number(value), index = 0;
    while (amount >= 1024 && index < units.length - 1) { amount /= 1024; index += 1; }
    return `${amount.toFixed(index ? 1 : 0)} ${units[index]}`;
  }

  function setConnection(connected, label) {
    const pill = $('#connectionPill');
    pill.classList.toggle('connected', connected);
    pill.classList.toggle('disconnected', !connected);
    $('#connectionText').textContent = label;
    $('#mobileStatusDot').style.background = connected ? 'var(--green)' : 'var(--red)';
    $('#railStatusDot').style.background = connected ? 'var(--green)' : 'var(--red)';
  }

  async function refreshStatus(showToast = false) {
    const requestedInstance = activeInstance;
    try {
      const state = await api('status');
      if (requestedInstance !== activeInstance) return;
      lastTelemetry = state.telemetry || null;
      const configured = !!state.configured;
      const server = state.telemetry?.server || {};
      const running = state.ok && server.status === 'running';
      const label = running ? 'Bedrock online' : server.status === 'maintenance' ? 'Manutenção' : configured ? 'Sem conexão' : 'Token pendente';
      setConnection(running, label);
      $('#mobileServerName').textContent = server.world_name || 'Bedrock';
      $('#mobilePlayerCount').textContent = state.telemetry?.players?.online_count === null || state.telemetry?.players?.online_count === undefined
        ? '— / — online'
        : `${state.telemetry.players.online_count} / ${state.telemetry.players.max_count ?? '—'} online`;
      $('#worldName').textContent = server.world_name || '—';
      $('#worldPort').textContent = `UDP ${server.game_port ?? '—'}`;
      $('#serverVersion').textContent = server.version || '—';
      $('#onlineCount').textContent = server.status === 'offline' ? '0' : (state.telemetry?.players?.online_count ?? '—');
      $('#maxCount').textContent = state.telemetry?.players?.max_count ?? server.max_players ?? '—';
      $('#gameMode').textContent = `Modo ${server.game_mode || '—'}`;
      $('#difficulty').textContent = `Dificuldade ${server.difficulty || '—'}`;
      $('#uptimeValue').textContent = formatUptime(server.uptime_seconds);
      const memoryValue = formatBytes(state.telemetry?.performance?.bedrock_memory_bytes);
      $('#memoryValue').textContent = memoryValue;
      $('#mobileMemoryValue').textContent = memoryValue;
      const players = state.telemetry?.players;
      const playerList = $('#playerList');
      playerList.replaceChildren();
      if (Array.isArray(players?.names) && players.names.length) {
        players.names.forEach(name => {
          const chip = document.createElement('span'); chip.className = 'player-chip'; chip.textContent = name; playerList.append(chip);
        });
      } else {
        const hint = document.createElement('span'); hint.className = 'muted';
        hint.textContent = players?.stale ? 'Aguardando amostra atualizada' : 'Nenhum jogador online';
        playerList.append(hint);
      }
      const canCommand = running;
      $('#commandInput').disabled = !canCommand;
      $('#sendButton').disabled = !canCommand;
      if (document.activeElement === $('#commandInput')) showSuggestions();
      const controlButton = $('#instanceControlButton');
      if (controlButton) { controlButton.dataset.action = running ? 'stop' : 'start'; controlButton.textContent = running ? 'Parar instância' : 'Iniciar instância'; controlButton.disabled = false; }
      const restartButton = $('#instanceRestartButton');
      if (restartButton) restartButton.disabled = !running;
      if (showToast) toast(running ? 'Telemetria atualizada.' : label, running ? '' : 'error');
    } catch (error) {
      if (requestedInstance !== activeInstance) return;
      lastTelemetry = null;
      setConnection(false, error.status === 401 ? 'Sessão expirada' : 'API indisponível');
      $('#commandInput').disabled = true;
      $('#sendButton').disabled = true;
      if (error.status === 401) window.location.reload();
      if (showToast) toast(error.message, 'error');
    }
  }
  async function controlInstance(action) {
    const labels = { start: 'iniciar', stop: 'parar', restart: 'reiniciar' };
    if (!window.confirm(`Confirma ${labels[action]} esta instância? Jogadores conectados serão desconectados em caso de parada ou reinício.`)) return;
    try {
      const result = await api('control', { method: 'POST', body: { action } });
      toast(result.message || `Solicitação para ${labels[action]} enviada.`);
      window.setTimeout(() => refreshStatus(false), 1200);
    } catch (error) { toast(error.message, 'error'); }
  }
  $('#instanceControlButton')?.addEventListener('click', event => controlInstance(event.currentTarget.dataset.action || 'stop'));
  $('#instanceRestartButton')?.addEventListener('click', () => controlInstance('restart'));

  async function loadCatalog() {
    try {
      catalog = await api('catalog');
      renderCategories();
      renderCommands();
      renderGlossary();
      const source = catalog.catalog_metadata || {};
      const status = $('#catalogStatus');
      if (status) {
        const date = source.updated_at ? new Date(source.updated_at).toLocaleDateString('pt-BR') : '';
        const version = source.server_version
          ? `servidor ${source.server_version} · dados oficiais ${source.source_version || 'locais'}`
          : (source.source_version ? `dados oficiais ${source.source_version}` : 'versão local');
        const items = Number(source.item_count || catalog.items?.length || 0);
        status.textContent = `Catálogo ${version} · ${items.toLocaleString('pt-BR')} identificadores de itens${date ? ` · atualizado em ${date}` : ''}`;
      }
      if (document.activeElement === $('#commandInput')) showSuggestions();
    } catch (error) { toast(`Catálogo: ${error.message}`, 'error'); }
  }

  function renderCategories() {
    const categories = ['Todos', ...new Set((catalog.commands || []).map(command => command.category))];
    const container = $('#categoryFilters'); container.replaceChildren();
    categories.forEach(category => {
      const button = document.createElement('button');
      button.type = 'button'; button.className = `filter-chip${activeCategory === category ? ' active' : ''}`;
      button.textContent = category; button.addEventListener('click', () => { activeCategory = category; renderCategories(); renderCommands(); });
      container.append(button);
    });
  }

  function renderCommands() {
    const search = ($('#catalogSearch')?.value || '').trim().toLowerCase();
    const commands = (catalog.commands || []).filter(command => {
      const inCategory = activeCategory === 'Todos' || command.category === activeCategory;
      const query = `${command.name} ${command.description} ${command.syntax} ${command.category}`.toLowerCase();
      return inCategory && (!search || query.includes(search));
    });
    const grid = $('#commandGrid'); grid.replaceChildren();
    if (!commands.length) { grid.innerHTML = '<div class="empty-state">Nenhum comando encontrado.</div>'; return; }
    commands.forEach(command => {
      const card = document.createElement('button'); card.type = 'button'; card.className = 'command-card';
      const head = document.createElement('div'); head.className = 'command-card-head';
      const name = document.createElement('span'); name.className = 'command-card-name'; name.textContent = `/${command.name}`;
      const category = document.createElement('span'); category.className = 'category-pill'; category.textContent = command.category;
      head.append(name, category);
      const description = document.createElement('p'); description.textContent = command.description;
      const syntax = document.createElement('div'); syntax.className = 'command-meta'; syntax.textContent = command.syntax;
      const example = document.createElement('div'); example.className = 'command-meta command-example'; example.textContent = `Ex.: ${commandExample(command)}`;
      card.append(head, description, syntax, example);
      card.addEventListener('click', () => {
        switchTab('console'); $('#commandInput').value = `/${command.name} `; hideSuggestions(); $('#commandInput').focus(); showSuggestions();
      });
      grid.append(card);
    });
  }
  $('#catalogSearch')?.addEventListener('input', renderCommands);

  function renderGlossary() {
    const query = ($('#glossarySearch')?.value || '').trim().toLowerCase();
    const items = glossary.filter(item => `${item.term} ${item.description} ${item.tag}`.toLowerCase().includes(query));
    const grid = $('#glossaryGrid'); grid.replaceChildren();
    items.forEach(item => {
      const card = document.createElement('article'); card.className = 'glossary-item';
      const title = document.createElement('strong'); title.textContent = item.term;
      const description = document.createElement('p'); description.textContent = item.description;
      const tag = document.createElement('span'); tag.className = 'glossary-tag'; tag.textContent = item.tag;
      card.append(title, description, tag); grid.append(card);
    });
  }
  $('#glossarySearch')?.addEventListener('input', renderGlossary);

  function lineTime(timestamp) {
    const date = timestamp ? new Date(timestamp) : new Date();
    return (Number.isNaN(date.getTime()) ? new Date() : date).toLocaleTimeString('pt-BR', { hour12: false });
  }
  function appendOutput(output, text, type = 'result', marker = '·', timestamp = null) {
    const line = document.createElement('div'); line.className = `terminal-line ${type}`;
    const time = document.createElement('span'); time.className = 'line-time'; time.textContent = lineTime(timestamp);
    const bullet = document.createElement('span'); bullet.className = 'line-marker'; bullet.textContent = marker;
    const value = document.createElement('span'); value.textContent = String(text);
    line.append(time, bullet, value); output.append(line); output.scrollTop = output.scrollHeight;
  }
  function appendTerminal(text, type = 'result', marker = '·', timestamp = null) {
    appendOutput($('#terminalOutput'), text, type, marker, timestamp);
  }
  function setOutputMode(mode) {
    outputMode = mode;
    const feed = mode === 'feed';
    $('#terminalOutput').hidden = feed;
    $('#feedOutput').hidden = !feed;
    $('#outputToggle').textContent = feed ? 'Terminal' : 'Feed';
    $('#outputToggle').setAttribute('aria-pressed', String(feed));
    $('#outputHeading').textContent = feed ? 'Feed de jogadores' : 'Terminal do servidor';
    hideSuggestions();
    const output = feed ? $('#feedOutput') : $('#terminalOutput');
    output.scrollTop = output.scrollHeight;
    if (feed && !feedInitialized) loadPlayerEvents(true);
  }
  $('#outputToggle')?.addEventListener('click', () => setOutputMode(outputMode === 'feed' ? 'terminal' : 'feed'));
  function hideSuggestions() { $('#suggestions').hidden = true; selectedSuggestion = -1; }

  const commonArgs = {
    gamemode: ['survival', 'creative', 'adventure', 'spectator'], difficulty: ['peaceful', 'easy', 'normal', 'hard'],
    weather: ['clear', 'rain', 'thunder'], time: ['set day', 'set night', 'set noon', 'set midnight', 'query daytime', 'query gametime'],
    daylock: ['true', 'false'], alwaysday: ['true', 'false'], mobevent: ['minecraft:pillager_patrols_event', 'minecraft:wandering_trader_event'],
    camerashake: ['add', 'stop'], permission: ['reload'], save: ['hold', 'resume', 'query', 'hold resume'],
    effect: ['clear'], allowlist: ['add', 'remove', 'list', 'reload'], whitelist: ['add', 'remove', 'list'],
    gamerule: ['doDaylightCycle', 'keepInventory', 'mobGriefing', 'doMobSpawning', 'doWeatherCycle', 'showcoordinates'],
    execute: ['as', 'at', 'if', 'unless', 'run'], scoreboard: ['objectives', 'players'], tickingarea: ['add', 'remove', 'remove_all', 'list']
  };
  const exampleItem = 'diamond';
  const commonEntities = ['minecraft:zombie', 'minecraft:skeleton', 'minecraft:creeper', 'minecraft:villager', 'minecraft:warden', 'minecraft:ender_dragon', 'minecraft:iron_golem', 'minecraft:horse'];

  function commandExample(command) {
    const name = command.name;
    const selector = (catalog.selectors || []).find(item => item.value === '@p')?.value || '@p';
    const item = exampleItem;
    const examples = {
      give: `${selector} ${item} 10`, time: commonArgs.time.find(value => value === 'query daytime') || 'query daytime',
      gamemode: `survival ${selector}`, difficulty: 'normal', weather: 'clear',
      effect: `${selector} clear`, teleport: `${selector} 0 64 0`, tp: `${selector} 0 64 0`,
      summon: `${commonEntities[0]} 0 64 0`, say: 'Olá mundo', list: '', help: '',
      allowlist: 'list', whitelist: 'list',
    };
    if (Object.prototype.hasOwnProperty.call(examples, name)) return `/${name}${examples[name] ? ` ${examples[name]}` : ''}`;

    const args = (command.syntax || '').slice(name.length).match(/<[^>]+>|[^\s<>\[\]]+|\.\.\./g) || [];
    const values = [];
    for (const token of args) {
      if (token === '...') break;
      if (!token.startsWith('<')) { values.push(token); continue; }
      const argument = token.slice(1, -1).toLowerCase();
      if (argument.includes('|')) {
        const choices = argument.split('|');
        values.push((commonArgs[name] || []).find(value => choices.includes(value)) || choices.find(value => ['list', 'query'].includes(value)) || choices[0]);
      } else if (/player|target|victim|selector/.test(argument)) values.push(selector);
      else if (/item/.test(argument)) values.push(item);
      else if (/block/.test(argument)) values.push('stone');
      else if (/begin|end|destination|position|coordinate|location/.test(argument)) values.push('0 64 0');
      else if (/entity|mob/.test(argument)) values.push(commonEntities[0] || 'minecraft:zombie');
      else if (/mode/.test(argument)) values.push('survival');
      else if (/difficulty/.test(argument)) values.push('normal');
      else if (/weather/.test(argument)) values.push('clear');
      else if (/effect/.test(argument)) values.push('speed');
      else if (/amount|count|seconds|duration|level|radius|distance|number|value/.test(argument)) values.push('10');
      else if (/true|false|bool|enable/.test(argument)) values.push('true');
      else if (/rule/.test(argument)) values.push('keepInventory');
      else if (/message|text|reason|title/.test(argument)) values.push('Olá mundo');
      else if (/time/.test(argument)) values.push('day');
      else values.push('example');
    }
    return `/${name}${values.length ? ` ${values.join(' ')}` : ''}`;
  }

  function argumentSuggestions(value) {
    const input = value.replace(/^\//, '');
    const parts = input.split(/\s+/);
    const name = (parts[0] || '').toLowerCase();
    const trailingSpace = /\s$/.test(input);
    // parts includes the command at index zero. Subtracting one gives the
    // current 1-based argument position, including the next position after a space.
    const argumentIndex = Math.max(0, parts.length - 1);
    const partial = trailingSpace ? '' : (parts[parts.length - 1] || '').toLowerCase();
    const previousArgs = parts.slice(1, argumentIndex);
    const playerNames = [...new Set([
      ...(lastTelemetry?.players?.names || []),
      ...playerStats.map(player => player.name).filter(Boolean),
    ])];
    const players = playerNames.map(player => ({ value: player, label: 'jogador', kind: 'argument' }));
    const selectors = (catalog.selectors || []).map(item => ({ value: item.value, label: item.label, kind: 'argument' }));
    const schemas = catalog.command_schemas?.[name] || [];
    const matchingSchemas = schemas.filter(schema => previousArgs.every((previous, index) => {
      const argument = schema.args?.[index];
      if (!argument) return false;
      const values = argument.values || [];
      return !values.length || values.some(option => String(option).toLowerCase() === previous.toLowerCase());
    }));
    let candidates = [];
    const argumentPosition = argumentIndex - 1;
    const addValues = (values, label) => {
      for (const rawValue of values || []) {
        const value = String(rawValue);
        const displayValue = !partial.startsWith('minecraft:') && value.startsWith('minecraft:')
          ? value.slice('minecraft:'.length)
          : value;
        candidates.push({ value: displayValue, label, kind: 'argument' });
      }
    };

    for (const schema of matchingSchemas) {
      const argument = schema.args?.[argumentPosition];
      if (!argument) continue;
      const type = String(argument.type || '').toUpperCase();
      const enumValues = argument.values || catalog.command_enums?.[type] || [];
      if (enumValues.length) addValues(enumValues, 'opção');
      else if (type === 'SELECTION' || type === 'TARGET' || type === 'TARGETS') candidates.push(...players, ...selectors);
      else if (type === 'ITEM') addValues(catalog.items?.length ? catalog.items : [exampleItem], 'item');
      else if (type === 'BLOCK' || type === 'BLOCK_ID') addValues(catalog.blocks || [], 'bloco');
      else if (/ENTITY|ACTOR/.test(type)) addValues(catalog.entities || commonEntities, 'entidade');
      else if (type === 'BOOLEAN' || type === 'BOOL') addValues(['true', 'false'], 'booleano');
    }

    if (!candidates.length && name === 'give') {
      if (argumentIndex === 1) candidates.push(...players, ...selectors);
      else if (argumentIndex === 2) addValues(catalog.items?.length ? catalog.items : [exampleItem], 'item');
    }

    if (!candidates.length && name === 'time') {
      if (argumentIndex === 1) addValues(['add', 'set', 'query', 'pause', 'resume'], 'opção');
      else if (argumentIndex === 2 && ['set', 'add'].includes(previousArgs[0])) addValues(['day', 'night', 'noon', 'midnight', '0', '1000', '12000', '18000'], 'horário ou ticks');
      else if (argumentIndex === 2 && previousArgs[0] === 'query') addValues(['time', 'daytime', 'gametime', 'day'], 'consulta');
    }
    if (!candidates.length && commonArgs[name] && argumentIndex <= 2) addValues(commonArgs[name], 'opção');

    candidates = [...new Map(candidates.map(item => [item.value.toLowerCase(), item])).values()];
    return candidates.filter(item => item.value.toLowerCase().startsWith(partial));
  }

  function currentSuggestions() {
    const value = $('#commandInput').value;
    if (!value.trim()) return [];
    const normalized = value.trimStart().replace(/^\//, '');
    if (!/\s/.test(normalized)) {
      const query = normalized.toLowerCase();
      if ((catalog.commands || []).some(command => command.name.toLowerCase() === query)) {
        const nextArguments = argumentSuggestions(`${value} `);
        if (nextArguments.length) return nextArguments.map(item => ({ ...item, syntax: item.label, appendAfterCommand: true }));
      }
      return (catalog.commands || []).filter(command => command.name.toLowerCase().startsWith(query))
        .map(command => ({ value: command.name, label: command.description, syntax: command.syntax, example: commandExample(command), kind: 'command' }));
    }
    return argumentSuggestions(value).map(item => ({ ...item, syntax: item.label }));
  }

  function currentArgumentHint(value) {
    const parts = value.trimStart().replace(/^\//, '').split(/\s+/);
    if (!parts.length || !catalog.command_schemas?.[parts[0].toLowerCase()]) return '';
    const index = parts.length - 2;
    const names = [...new Set((catalog.command_schemas[parts[0].toLowerCase()] || [])
      .map(schema => schema.args?.[index]?.name).filter(Boolean))];
    return names.length ? `Próximo argumento: ${names.map(name => `<${name}>`).join(' ou ')}` : '';
  }

  function showSuggestions() {
    const box = $('#suggestions'); const input = $('#commandInput');
    const options = currentSuggestions(); box.replaceChildren();
    if (input.disabled) { hideSuggestions(); return; }
    if (!options.length) {
      const hint = currentArgumentHint(input.value);
      if (!hint) { hideSuggestions(); return; }
      const message = document.createElement('div');
      message.className = 'suggestion-hint'; message.textContent = hint;
      box.append(message); box.hidden = false; return;
    }
    options.forEach((option, index) => {
      const button = document.createElement('button'); button.type = 'button';
      button.className = `suggestion-item${option.kind === 'command' ? ' command-suggestion' : ''}${selectedSuggestion === index ? ' selected' : ''}`;
      const name = document.createElement('span'); name.className = 'suggestion-name'; name.textContent = option.kind === 'command' ? `/${option.value}` : option.value;
      const description = document.createElement('span'); description.className = 'suggestion-desc'; description.textContent = option.label || '';
      const syntax = document.createElement('span'); syntax.className = 'suggestion-syntax'; syntax.textContent = option.syntax || '';
      button.append(name, description, syntax);
      if (option.kind === 'command' && option.example) {
        const example = document.createElement('span'); example.className = 'suggestion-example'; example.textContent = `Ex.: ${option.example}`;
        button.append(example);
      }
      button.addEventListener('mousedown', event => { event.preventDefault(); chooseSuggestion(option); });
      box.append(button);
    });
    box.hidden = false;
    if (selectedSuggestion >= 0) {
      box.children[selectedSuggestion]?.scrollIntoView({ block: 'nearest' });
    }
  }

  function chooseSuggestion(option) {
    const input = $('#commandInput');
    const original = input.value;
    if (option.kind === 'command') {
      const prefix = original.trimStart().startsWith('/') ? '/' : '';
      input.value = `${prefix}${option.value} `;
    } else if (option.appendAfterCommand) {
      input.value = `${original} ${option.value} `;
    } else {
      const endsWithSpace = /\s$/.test(original);
      const next = endsWithSpace ? `${original}${option.value} ` : original.replace(/\S+$/, `${option.value} `);
      input.value = next;
    }
    selectedSuggestion = -1;
    input.focus(); showSuggestions();
  }

  $('#commandInput')?.addEventListener('input', () => { selectedSuggestion = -1; showSuggestions(); });
  $('#commandInput')?.addEventListener('keydown', event => {
    const options = currentSuggestions();
    if (options.length && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
      event.preventDefault();
      selectedSuggestion = event.key === 'ArrowDown'
        ? (selectedSuggestion + 1) % options.length
        : (selectedSuggestion <= 0 ? options.length - 1 : selectedSuggestion - 1);
      showSuggestions(); return;
    }
    if (options.length && (event.key === 'Tab' || (event.key === 'Enter' && selectedSuggestion >= 0))) {
      event.preventDefault(); chooseSuggestion(options[selectedSuggestion >= 0 ? selectedSuggestion : 0]); return;
    }
    if (event.key === 'Escape') { hideSuggestions(); return; }
    if (event.key === 'ArrowUp' && !$('#suggestions').hidden) return;
    if (event.key === 'ArrowUp' && commandHistory.length) {
      event.preventDefault(); historyPointer = Math.max(0, historyPointer - 1); event.currentTarget.value = commandHistory[historyPointer] || ''; showSuggestions();
    } else if (event.key === 'ArrowDown' && commandHistory.length) {
      event.preventDefault(); historyPointer = Math.min(commandHistory.length, historyPointer + 1); event.currentTarget.value = commandHistory[historyPointer] || ''; showSuggestions();
    }
  });
  $('#commandInput')?.addEventListener('blur', () => window.setTimeout(hideSuggestions, 140));

  async function executeCommand(rawCommand) {
    const command = rawCommand.trim();
    if (!command) return;
    if (outputMode === 'feed') setOutputMode('terminal');
    $('#commandInput').value = ''; hideSuggestions();
    commandHistory.push(command); historyPointer = commandHistory.length;
    appendTerminal(command, 'command', '›');
    $('#sendButton').disabled = true;
    try {
      const result = await api('command', { method: 'POST', body: { command } });
      (result.console_output || []).forEach(line => appendTerminal(line, 'result'));
      if (!result.console_output?.length) appendTerminal(result.message || 'Comando enviado; a API não confirmou a execução específica.', result.execution_confirmed ? 'result' : 'warning');
      if (result.execution_confirmed) appendTerminal('Execução confirmada pelo servidor.', 'system', '✓');
      await loadHistory(true);
    } catch (error) {
      appendTerminal(error.message, 'error', '!');
      toast(error.message, 'error');
      await loadHistory(true);
    } finally {
      $('#sendButton').disabled = !lastTelemetry || lastTelemetry.server?.status !== 'running';
      $('#commandInput').focus();
    }
  }
  $('#commandForm')?.addEventListener('submit', event => { event.preventDefault(); executeCommand($('#commandInput').value); });
  $('#clearTerminal')?.addEventListener('click', () => {
    if (outputMode === 'feed') {
      $('#feedOutput').replaceChildren();
      appendOutput($('#feedOutput'), 'Feed limpo nesta tela. Novos eventos aparecerão automaticamente.', 'system', '◆');
    } else {
      $('#terminalOutput').replaceChildren();
      appendTerminal('Tela limpa. Os registros permanentes continuam no Histórico.', 'system', '◆');
    }
  });
  $$('.quick-command').forEach(button => button.addEventListener('click', () => executeCommand(button.dataset.command || '')));
  $('#refreshButton')?.addEventListener('click', () => refreshStatus(true));

  function renderHistory() {
    const query = ($('#historySearch')?.value || '').trim().toLowerCase();
    const list = $('#historyList'); list.replaceChildren();
    const records = historyRecords.filter(item => `${item.actor} ${item.event_type} ${item.command} ${item.status} ${item.detail}`.toLowerCase().includes(query));
    if (!records.length) {
      const empty = document.createElement('div'); empty.className = 'empty-state';
      empty.textContent = historyRecords.length ? 'Nenhum registro corresponde à busca.' : 'Ainda não há registros no período de retenção.';
      list.append(empty); $('#historyMore').hidden = true; return;
    }
    records.forEach(item => {
      const card = document.createElement('article'); card.className = 'history-item';
      const head = document.createElement('div'); head.className = 'history-head';
      const icon = document.createElement('span'); icon.className = 'history-type-icon'; icon.textContent = item.event_type === 'command' ? '›' : item.event_type === 'login' ? '↗' : '·';
      const command = document.createElement('code'); command.className = 'history-command'; command.textContent = item.command ? `/${item.command}` : item.event_type;
      const meta = document.createElement('div'); meta.className = 'history-meta';
      const date = document.createElement('time'); date.dateTime = new Date(Number(item.created_at) * 1000).toISOString(); date.textContent = new Date(Number(item.created_at) * 1000).toLocaleString('pt-BR');
      const status = document.createElement('span'); status.className = `history-status ${item.status}`; status.textContent = item.status;
      meta.append(date, status); head.append(icon, command, meta); card.append(head);
      if (item.detail) { const detail = document.createElement('div'); detail.className = 'history-detail'; detail.textContent = item.detail; card.append(detail); }
      list.append(card);
    });
    $('#historyMore').hidden = historyRecords.length < 60 || !historyCursor;
  }

  async function loadHistory(reset = false) {
    try {
      const query = reset || !historyCursor ? 'limit=60' : `limit=60&before=${historyCursor}`;
      const payload = await api('history', { query });
      if (reset) historyRecords = [];
      const incoming = payload.events || [];
      if (incoming.length) {
        historyCursor = Number(incoming[incoming.length - 1].id);
        historyRecords = reset ? incoming : [...historyRecords, ...incoming];
      } else if (reset) historyCursor = 0;
      renderHistory();
    } catch (error) { if (error.status !== 401) toast(`Histórico: ${error.message}`, 'error'); }
  }
  $('#historyRefresh')?.addEventListener('click', () => loadHistory(true));
  $('#historyMore')?.addEventListener('click', () => loadHistory(false));
  $('#historySearch')?.addEventListener('input', renderHistory);

  function appendPlayerEvent(event) {
    const player = event.player || 'Jogador';
    const labels = { join: 'entrou no servidor', leave: 'saiu do servidor', death: 'morreu' };
    const markers = { join: '↗', leave: '↙', death: '✕' };
    if (!Object.hasOwn(labels, event.type)) return;
    const detail = event.type === 'death' && event.detail ? ` — ${event.detail}` : '';
    appendOutput($('#feedOutput'), `${player} ${labels[event.type]}${detail}`, `player-event ${event.type}`, markers[event.type], event.timestamp);
  }

  async function loadPlayerEvents(reset = false) {
    const requestedInstance = activeInstance;
    if (activityLoading === requestedInstance) return;
    activityLoading = requestedInstance;
    try {
      const query = `after=${reset ? 0 : activityCursor}&limit=${reset ? 20 : 100}`;
      const payload = await api('events', { query });
      if (requestedInstance !== activeInstance) return;
      const incoming = Array.isArray(payload.events) ? payload.events : [];
      if (reset) {
        activityCursor = 0;
        $('#feedOutput').replaceChildren();
        appendOutput($('#feedOutput'), 'Atividade do mundo selecionado. Exibindo até 20 eventos recentes.', 'system', '◆');
      }
      incoming.filter(event => Number(event.id) > activityCursor).forEach(event => {
        appendPlayerEvent(event);
        activityCursor = Number(event.id);
      });
      feedInitialized = true;
    } catch (error) {
      if (requestedInstance !== activeInstance) return;
      if (error.status !== 401 && reset) appendOutput($('#feedOutput'), `Eventos de jogadores indisponíveis: ${error.message}`, 'warning', '!');
      if (error.status === 401) window.location.reload();
    } finally { if (activityLoading === requestedInstance) activityLoading = false; }
  }

  function formatDuration(seconds) {
    const value = Math.max(0, Number(seconds) || 0);
    const days = Math.floor(value / 86400);
    const hours = Math.floor((value % 86400) / 3600);
    const minutes = Math.floor((value % 3600) / 60);
    return days ? `${days}d ${hours}h` : hours ? `${hours}h ${minutes}m` : `${minutes}m`;
  }

  function formatPlayerDate(value) {
    if (!value) return 'Sem registro';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? 'Sem registro' : date.toLocaleString('pt-BR');
  }

  function renderPlayerStats() {
    const query = ($('#statsSearch')?.value || '').trim().toLocaleLowerCase();
    const list = $('#playerStatsList'); list.replaceChildren();
    const players = playerStats.filter(player => player.name.toLocaleLowerCase().includes(query));
    if (!players.length) {
      const empty = document.createElement('div'); empty.className = 'empty-state';
      empty.textContent = playerStats.length ? 'Nenhum jogador corresponde à busca.' : 'As estatísticas serão preenchidas conforme os jogadores entrarem, saírem e jogarem.';
      list.append(empty); return;
    }
    players.forEach(player => {
      const card = document.createElement('article'); card.className = 'player-stat-card';
      const head = document.createElement('div'); head.className = 'player-stat-head';
      const name = document.createElement('strong'); name.className = 'player-stat-name'; name.textContent = player.name;
      const status = document.createElement('span'); status.className = `player-stat-status ${player.online ? 'online' : ''}`; status.textContent = player.online ? 'ONLINE' : 'OFFLINE';
      head.append(name, status);
      const metrics = document.createElement('div'); metrics.className = 'player-stat-metrics';
      [["Tempo jogado", formatDuration(player.playtime_seconds)], ["Sessões", String(player.join_count)], ["Mortes", String(player.death_count)], ["Saídas", String(player.leave_count)]].forEach(([label, value]) => {
        const item = document.createElement('div'); item.className = 'player-stat-metric';
        const caption = document.createElement('span'); caption.textContent = label;
        const amount = document.createElement('strong'); amount.textContent = value;
        item.append(caption, amount); metrics.append(item);
      });
      const lastSeen = document.createElement('div'); lastSeen.className = 'player-stat-last-seen';
      lastSeen.textContent = `Última atividade · ${formatPlayerDate(player.last_seen)}`;
      if (player.online && player.current_session_seconds > 0) lastSeen.textContent += ` · sessão atual ${formatDuration(player.current_session_seconds)}`;
      card.append(head, metrics, lastSeen); list.append(card);
    });
  }

  async function loadPlayerStats() {
    const requestedInstance = activeInstance;
    if (statsLoading === requestedInstance) return;
    statsLoading = requestedInstance;
    try {
      const payload = await api('player-stats');
      if (requestedInstance !== activeInstance) return;
      playerStats = Array.isArray(payload.players)
        ? payload.players.filter(player => typeof player?.name === 'string' && player.name.trim().length > 0 && player.name.trim().length <= 16 && !/[\[\]]/.test(player.name))
        : [];
      $('#statsUpdated').textContent = `Atualizado às ${new Date().toLocaleTimeString('pt-BR')}`;
      renderPlayerStats();
      if (document.activeElement === $('#commandInput')) showSuggestions();
    } catch (error) {
      if (requestedInstance !== activeInstance) return;
      if (error.status !== 401) $('#statsUpdated').textContent = `Falha ao atualizar: ${error.message}`;
      if (error.status === 401) window.location.reload();
    } finally { if (statsLoading === requestedInstance) statsLoading = false; }
  }
  $('#statsSearch')?.addEventListener('input', renderPlayerStats);
  $('#statsRefresh')?.addEventListener('click', loadPlayerStats);

  async function initializeConsole() {
    await loadInstancePicker();
    await Promise.all([loadCatalog(), refreshStatus(), loadHistory(true), loadPlayerStats()]);
    if (pollTimer) window.clearInterval(pollTimer);
    if (activityPollTimer) window.clearInterval(activityPollTimer);
    pollTimer = window.setInterval(() => {
      refreshStatus(false);
      if ($('#view-stats')?.classList.contains('active')) loadPlayerStats();
    }, 15000);
    activityPollTimer = window.setInterval(() => {
      if (outputMode === 'feed') loadPlayerEvents(!feedInitialized);
    }, 5000);
  }

  async function loadInstancePicker() {
    const picker = $('#instancePicker');
    if (!picker) return;
    try {
      const payload = await api('instances');
      const instances = Array.isArray(payload.instances) ? payload.instances : [];
      picker.replaceChildren();
      instances.forEach(instance => {
        const option = document.createElement('option');
        option.value = instance.id;
        option.textContent = instance.name;
        picker.append(option);
      });
      if (!instances.some(instance => instance.id === activeInstance)) activeInstance = instances[0]?.id || 'main';
      picker.value = activeInstance;
      picker.hidden = instances.length < 2;
      picker.addEventListener('change', () => {
        if (propertiesDirty() && !window.confirm('Há alterações não salvas em server.properties. Descartar e trocar de instância?')) {
          picker.value = activeInstance;
          return;
        }
        activeInstance = picker.value;
        sessionStorage.setItem('minedev-instance', activeInstance);
        lastTelemetry = null;
        activityCursor = 0;
        feedInitialized = false;
        playerStats = [];
        historyRecords = []; historyCursor = 0;
        $('#terminalOutput')?.replaceChildren();
        $('#feedOutput')?.replaceChildren();
        setConnection(false, 'Conectando…');
        $('#worldName').textContent = 'Carregando…';
        $('#worldPort').textContent = 'UDP —';
        $('#mobileServerName').textContent = 'Carregando…';
        renderPlayerStats();
        propertiesRevision = '';
        propertiesInstance = '';
        if ($('#view-properties')?.classList.contains('active')) loadProperties();
        refreshStatus(false);
        loadPlayerStats();
        if (outputMode === 'feed') loadPlayerEvents(true);
      });
    } catch (error) {
      picker.replaceChildren(new Option('Instância indisponível', 'main'));
      if (error.status !== 401) toast(`Não foi possível carregar as instâncias: ${error.message}`, 'error');
    }
  }

  if (!loginScreen.hidden) loadCsrf();
  if (appShell && !appShell.hidden) {
    $('#userBadge').textContent = ($('#username')?.value || 'AD').slice(0, 2).toUpperCase();
    initializeConsole();
  }
})();
