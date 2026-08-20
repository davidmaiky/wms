/**
 * WMS PRIME PRO - Aplicação Principal de Separação & Conferência
 */

const App = {
    currentView: 'pedidos',
    operador: localStorage.getItem('wms_operador') || 'David',
    currentUser: null,
    permData: null,
    conferenciaAtiva: null,
    scanner: null,
    soundEnabled: true,
    modoCego: false,
    pedidosCache: [],
    camerasList: [],
    currentCameraIndex: 0,

    init() {
        // Configurar interceptador fetch global para lidar com 401
        this.setupFetchInterceptor();

        // Registrar Service Worker PWA
        this.registerServiceWorker();

        // Monitorar conectividade de rede (Online / Offline)
        this.setupNetworkStatusListeners();

        // Configurar atalhos operacionais de teclado para coletores de dados
        this.setupKeyboardShortcuts();

        // Inicializar áudio e leitor
        this.soundEnabled = localStorage.getItem('wms_sound') !== '0';
        window.soundEngine.setEnabled(this.soundEnabled);
        this.updateSoundIcon();

        this.scanner = new ScannerManager((code, type) => {
            this.handleBarcodeScan(code, type);
        });

        // Registrar listener de enter no input manual
        const inputManual = document.getElementById('inputManualBarcode');
        if (inputManual) {
            inputManual.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    this.biparManual();
                }
            });
        }

        const inputManualRec = document.getElementById('inputManualBarcodeRec');
        if (inputManualRec) {
            inputManualRec.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    this.biparRecebimentoManual();
                }
            });
        }

        // Verificar se há uma sessão de login ativa
        this.checkSession().then(authenticated => {
            if (authenticated) {
                this.ocultarLoginScreen();
                this.carregarDadosIniciais();
            } else {
                this.exibirLoginScreen();
            }
        });
    },

    registerServiceWorker() {
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('./sw.js')
                    .then(reg => {
                        console.log('[PWA] Service Worker registrado com sucesso no escopo:', reg.scope);
                    })
                    .catch(err => {
                        console.warn('[PWA] Falha ao registrar Service Worker:', err);
                    });
            });
        }
    },

    setupNetworkStatusListeners() {
        const updateStatus = () => {
            const chip = document.getElementById('networkStatusChip');
            if (!chip) return;
            if (navigator.onLine) {
                chip.innerHTML = '<span class="status-dot"></span> Online';
                chip.classList.remove('offline');
                chip.style.borderColor = 'rgba(16, 185, 129, 0.4)';
                chip.style.color = '#34d399';
            } else {
                chip.innerHTML = '<span class="status-dot" style="background:#ef4444;box-shadow:0 0 8px #ef4444;"></span> Offline';
                chip.classList.add('offline');
                chip.style.borderColor = 'rgba(239, 68, 68, 0.5)';
                chip.style.color = '#f87171';
                this.toast('Conexão de rede perdida! O app funcionará com cache local.', 'warning');
            }
        };

        window.addEventListener('online', updateStatus);
        window.addEventListener('offline', updateStatus);
        updateStatus();
    },

    setupKeyboardShortcuts() {
        window.addEventListener('keydown', (e) => {
            // Ignora se estiver digitando em um input/textarea e não for tecla de função F
            const target = e.target;
            const isTyping = target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable);

            // F2 ou Ctrl + B -> Focar campo de bipagem manual
            if (e.key === 'F2' || (e.ctrlKey && (e.key === 'b' || e.key === 'B'))) {
                e.preventDefault();
                const input = document.getElementById('inputManualBarcode');
                if (input) {
                    if (this.currentView !== 'conferencia') {
                        this.navigate('conferencia');
                    }
                    input.focus();
                    input.select();
                }
                return;
            }

            // F4 -> Finalizar conferência
            if (e.key === 'F4') {
                e.preventDefault();
                const btn = document.getElementById('btnFinalizarConf');
                if (btn && !btn.disabled && this.currentView === 'conferencia') {
                    btn.click();
                }
                return;
            }

            // F7 -> Adicionar Volume
            if (e.key === 'F7') {
                e.preventDefault();
                const btnVol = document.getElementById('btnAbrirModalVolume');
                if (btnVol && this.currentView === 'conferencia') {
                    btnVol.click();
                }
                return;
            }

            // Escape -> Fechar modais
            if (e.key === 'Escape') {
                this.fecharTodosModais();
                return;
            }
        });
    },

    fecharTodosModais() {
        const modais = document.querySelectorAll('.modal-overlay.active, .modal.active');
        modais.forEach(m => m.classList.remove('active'));
    },

    setupFetchInterceptor() {
        const originalFetch = window.fetch;
        const self = this;
        window.fetch = async function(...args) {
            try {
                const res = await originalFetch(...args);
                if (res.status === 401) {
                    // Ignora se for a própria chamada de "me"
                    const url = typeof args[0] === 'string' ? args[0] : (args[0] && args[0].url ? args[0].url : '');
                    if (!url.includes('action=me') && !url.includes('action=login')) {
                        self.exibirLoginScreen();
                    }
                }
                return res;
            } catch (err) {
                console.error("Fetch error:", err);
                throw err;
            }
        };
    },

    async checkSession() {
        try {
            const res = await fetch('api/usuarios.php?action=me');
            const data = await res.json();
            if (data.success && data.user) {
                this.currentUser = data.user;
                this.operador = data.user.nome;
                localStorage.setItem('wms_operador', data.user.nome);
                this.atualizarOperadorHeader();
                this.aplicarPermissoesUI();
                return true;
            }
        } catch (e) {
            console.error('Erro na checagem de sessão:', e);
        }
        return false;
    },

    hasPermission(perm) {
        if (!this.currentUser) return false;
        if (this.currentUser.funcao === 'admin') return true;
        return Array.isArray(this.currentUser.permissoes) && this.currentUser.permissoes.includes(perm);
    },

    aplicarPermissoesUI() {
        if (!this.currentUser) return;

        const navMap = {
            'pedidos': this.hasPermission('pedidos_visualizar'),
            'conferencia': this.hasPermission('conferencia_bipar') || this.hasPermission('pedidos_iniciar_separacao'),
            'recebimento': this.hasPermission('recebimento_visualizar'),
            'historico': this.hasPermission('historico_visualizar'),
            'locais': this.hasPermission('locais_visualizar'),
            'eans': this.hasPermission('eans_visualizar'),
            'usuarios': this.hasPermission('usuarios_visualizar'),
            'config': this.hasPermission('config_visualizar')
        };

        document.querySelectorAll('.nav-btn').forEach(btn => {
            const view = btn.getAttribute('data-view');
            if (view && navMap[view] !== undefined) {
                const parentItem = btn.closest('.nav-main-item');
                if (parentItem) {
                    parentItem.style.display = navMap[view] ? '' : 'none';
                } else {
                    btn.style.display = navMap[view] ? '' : 'none';
                }
            }
        });

        // Se a tela atual não for permitida, navegar para a primeira tela permitida
        if (this.currentView && navMap[this.currentView] === false) {
            const primeiraPermitida = Object.keys(navMap).find(k => navMap[k] === true);
            if (primeiraPermitida) {
                this.navigate(primeiraPermitida);
            }
        }
    },

    carregarDadosIniciais() {
        this.setDefaultDateFilter();
        if (this.hasPermission('config_visualizar')) {
            this.carregarConfiguracoes();
        }
        if (this.hasPermission('pedidos_visualizar')) {
            this.carregarStats();
            this.buscarPedidos();
        }
    },

    atualizarOperadorHeader() {
        const nome = this.operador || 'David';
        const initial = nome.charAt(0).toUpperCase();
        const cor = (this.currentUser && this.currentUser.avatar_cor) ? this.currentUser.avatar_cor : '#3b82f6';

        const lbl = document.getElementById('lblOperadorHeader');
        if (lbl) lbl.textContent = nome;
        const mini = document.getElementById('avatarMiniHeader');
        if (mini) {
            mini.textContent = initial;
            mini.style.background = cor;
        }

        const lblDrop = document.getElementById('lblOperadorDropdownNome');
        if (lblDrop) lblDrop.textContent = nome;
        const lblRole = document.getElementById('lblOperadorDropdownRole');
        if (lblRole && this.currentUser) {
            const roleLabels = { 'admin': 'Administrador', 'supervisor': 'Supervisor', 'conferente': 'Conferente', 'operador': 'Operador' };
            lblRole.textContent = roleLabels[this.currentUser.funcao] || this.currentUser.funcao || 'Operador';
        }
        const miniDrop = document.getElementById('avatarMiniDropdown');
        if (miniDrop) {
            miniDrop.textContent = initial;
            miniDrop.style.background = cor;
        }
    },

    toggleFullscreen() {
        if (!document.fullscreenElement) {
            document.documentElement.requestFullscreen().catch(err => {
                console.warn('Fullscreen error:', err);
            });
            const icon = document.getElementById('iconFullscreen');
            if (icon) icon.className = 'fa-solid fa-compress';
        } else {
            if (document.exitFullscreen) {
                document.exitFullscreen();
            }
            const icon = document.getElementById('iconFullscreen');
            if (icon) icon.className = 'fa-solid fa-expand';
        }
    },

    aplicarFiltroRapido(status) {
        document.querySelectorAll('.filter-chip').forEach(chip => {
            chip.classList.toggle('active', chip.getAttribute('data-status-filter') === status);
        });
        const select = document.getElementById('filtroStatus');
        if (select) select.value = status;
        this.buscarPedidos();
    },

    async copiarTexto(texto) {
        try {
            await navigator.clipboard.writeText(texto);
            this.toast(`Copiado para área de transferência: ${texto}`, 'success');
        } catch (e) {
            this.toast(`Código: ${texto}`, 'info');
        }
    },

    showScanFeedback(msg, type = 'success') {
        const banner = document.getElementById('scanStatusBanner');
        if (!banner) return;
        banner.style.display = 'block';
        if (type === 'success') {
            banner.style.background = 'rgba(16, 185, 129, 0.15)';
            banner.style.border = '1px solid rgba(16, 185, 129, 0.35)';
            banner.style.color = '#34d399';
            banner.innerHTML = `<i class="fa-solid fa-circle-check"></i> ${msg}`;
        } else if (type === 'warning') {
            banner.style.background = 'rgba(245, 158, 11, 0.15)';
            banner.style.border = '1px solid rgba(245, 158, 11, 0.35)';
            banner.style.color = '#fbbf24';
            banner.innerHTML = `<i class="fa-solid fa-triangle-exclamation"></i> ${msg}`;
        } else {
            banner.style.background = 'rgba(239, 68, 68, 0.15)';
            banner.style.border = '1px solid rgba(239, 68, 68, 0.35)';
            banner.style.color = '#f87171';
            banner.innerHTML = `<i class="fa-solid fa-circle-xmark"></i> ${msg}`;
        }
    },

    // --- NAVEGAÇÃO ENTRE TELAS ---
    navigate(viewName) {
        // Checagem de permissão da tela de destino
        if (this.currentUser) {
            const permMap = {
                'pedidos': 'pedidos_visualizar',
                'recebimento': 'recebimento_visualizar',
                'historico': 'historico_visualizar',
                'locais': 'locais_visualizar',
                'eans': 'eans_visualizar',
                'usuarios': 'usuarios_visualizar',
                'config': 'config_visualizar'
            };
            const reqPerm = permMap[viewName];
            if (reqPerm && !this.hasPermission(reqPerm)) {
                this.toast('Acesso restrito: você não tem permissão para acessar esta área.', 'warning');
                return;
            }
        }

        this.currentView = viewName;

        // Fechar sidebar retrátil no mobile ao navegar
        const pageContainer = document.getElementById('page-container');
        if (pageContainer && window.innerWidth < 992) {
            pageContainer.classList.remove('sidebar-o-xs');
        }

        // Atualizar botões do menu
        document.querySelectorAll('.nav-btn').forEach(btn => {
            btn.classList.toggle('active', btn.getAttribute('data-view') === viewName);
        });

        // Atualizar seções
        document.querySelectorAll('.view-section').forEach(sec => {
            sec.classList.toggle('active', sec.id === `view-${viewName}`);
        });

        if (viewName === 'pedidos') {
            this.carregarStats();
            this.buscarPedidos();
            this.stopCamera();
        } else if (viewName === 'conferencia') {
            if (!this.conferenciaAtiva) {
                document.getElementById('conferenciaVazia').style.display = 'block';
                document.getElementById('conferenciaAtivaContainer').style.display = 'none';
            } else {
                document.getElementById('conferenciaVazia').style.display = 'none';
                document.getElementById('conferenciaAtivaContainer').style.display = 'grid';
            }
        } else if (viewName === 'recebimento') {
            this.carregarRecebimentos();
            this.stopCamera();
        } else if (viewName === 'historico') {
            this.carregarHistorico();
            this.stopCamera();
        } else if (viewName === 'locais') {
            this.carregarLocais();
            this.stopCamera();
        } else if (viewName === 'eans') {
            this.carregarEans();
            this.stopCamera();
        } else if (viewName === 'usuarios') {
            this.carregarUsuarios();
            this.stopCamera();
        } else if (viewName === 'config') {
            this.carregarConfiguracoes();
            this.stopCamera();
        }
    },

    // --- DASHBOARD & LISTA DE PEDIDOS ---
    async carregarStats() {
        try {
            const res = await fetch('api/pedidos.php?action=stats');
            const data = await res.json();
            if (data.success && data.stats) {
                const s = data.stats;
                const setEl = (id, val) => {
                    const el = document.getElementById(id);
                    if (el) el.textContent = val;
                };

                setEl('kpiTotalConferencias', s.total_conferencias || 0);
                setEl('kpiConferidosHoje', s.conferidos_hoje || 0);
                setEl('kpiEmSeparacao', s.em_separacao || 0);
                setEl('kpiDivergencias', s.divergencias || 0);
                setEl('kpiTaxaAcuracia', (s.taxa_acuracia !== undefined ? s.taxa_acuracia : 100) + '%');
                setEl('kpiTempoMedio', (s.tempo_medio_minutos || 0) + ' min');
                setEl('kpiItensBipadosHoje', s.itens_bipados_hoje || 0);
                setEl('kpiVolumesHoje', s.volumes_hoje || 0);
            }
        } catch (e) {
            console.error('Erro ao carregar estatísticas:', e);
        }
    },

    async buscarPedidos(e) {
        if (e) e.preventDefault();

        const tbody = document.getElementById('pedidosTableBody');
        tbody.innerHTML = `
            <tr>
                <td colspan="8" style="text-align: center; padding: 2.5rem; color: var(--text-muted);">
                    <i class="fa-solid fa-spinner fa-spin fa-2x"></i><br><br>Consultando SIGE Cloud...
                </td>
            </tr>
        `;

        const codigo = document.getElementById('filtroCodigo')?.value.trim() || '';
        const cliente = document.getElementById('filtroCliente')?.value.trim() || '';
        const status = document.getElementById('filtroStatus')?.value.trim() || '';
        const dataInicial = document.getElementById('filtroDataInicial')?.value || '';
        const dataFinal = document.getElementById('filtroDataFinal')?.value || '';

        const params = new URLSearchParams({
            action: 'list',
            pageSize: '100'
        });

        if (codigo) params.append('codigo', codigo);
        if (cliente) params.append('cliente', cliente);
        if (status) params.append('status', status);
        if (dataInicial) params.append('dataInicial', dataInicial);
        if (dataFinal) params.append('dataFinal', dataFinal);

        try {
            const res = await fetch(`api/pedidos.php?${params.toString()}`);
            const data = await res.json();

            if (!data.success) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 2rem; color: var(--color-danger);">
                            <i class="fa-solid fa-triangle-exclamation fa-2x"></i><br><br>
                            ${data.error || 'Erro ao carregar pedidos.'}
                        </td>
                    </tr>
                `;
                return;
            }

            this.pedidosCache = data.data || [];
            this.pedidosCache.sort((a, b) => {
                const idA = parseInt(a.Codigo || a.ID || a.Id || 0, 10);
                const idB = parseInt(b.Codigo || b.ID || b.Id || 0, 10);
                if (idA === idB) {
                    const dateA = new Date(a.Data || 0).getTime();
                    const dateB = new Date(b.Data || 0).getTime();
                    return dateB - dateA;
                }
                return idB - idA;
            });
            this.renderPedidosTable(this.pedidosCache);
        } catch (err) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="8" style="text-align: center; padding: 2rem; color: var(--color-danger);">
                        <i class="fa-solid fa-circle-exclamation fa-2x"></i><br><br>
                        Falha de comunicação com o servidor local.
                    </td>
                </tr>
            `;
        }
    },

    renderPedidosTable(pedidos) {
        const tbody = document.getElementById('pedidosTableBody');
        if (!pedidos || pedidos.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="8" style="text-align: center; padding: 3rem 1.5rem; color: var(--text-muted);">
                        <div style="display: flex; flex-direction: column; align-items: center; gap: 0.75rem;">
                            <i class="fa-solid fa-box-open fa-3x" style="color: #475569;"></i>
                            <h4 style="color: var(--text-secondary); font-size: 1.05rem;">Nenhum pedido encontrado</h4>
                            <p style="font-size: 0.85rem; max-width: 400px;">Tente alterar os filtros de data, status ou número de pedido.</p>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="App.limparFiltros()">
                                <i class="fa-solid fa-rotate-left"></i> Limpar Filtros
                            </button>
                        </div>
                    </td>
                </tr>
            `;
            return;
        }

        let html = '';
        pedidos.forEach(p => {
            const conf = p.Conferencia || {};
            const numPedido = p.Codigo;
            const dataFormatada = p.Data ? new Date(p.Data).toLocaleDateString('pt-BR') : '-';
            const totalItens = (p.Items || []).length;
            const totalQtd = (p.Items || []).reduce((acc, item) => acc + (parseFloat(item.Quantidade) || 0), 0);
            const clienteNome = p.Cliente || 'Consumidor Final';
            const clienteInitials = clienteNome.substring(0, 2).toUpperCase();

            // Badge Status SIGE
            let badgeSige = '<span class="badge badge-pending">Orçamento</span>';
            const stSige = p.StatusSistema || p.Status || '';
            if (stSige.includes('Aprovado')) badgeSige = '<span class="badge badge-progress"><i class="fa-solid fa-check"></i> Aprovado</span>';
            else if (stSige.includes('Faturado')) badgeSige = '<span class="badge badge-success"><i class="fa-solid fa-truck"></i> Faturado</span>';
            else if (stSige.includes('Cancelado')) badgeSige = '<span class="badge badge-danger"><i class="fa-solid fa-ban"></i> Cancelado</span>';

            // Badge Status WMS
            let badgeWms = '<span class="badge badge-pending"><i class="fa-regular fa-clock"></i> Pendente</span>';
            let btnActionText = '<i class="fa-solid fa-play"></i> Iniciar';
            let btnActionClass = 'btn-primary';

            if (conf.conferencia_status === 'em_separacao') {
                badgeWms = `<span class="badge badge-progress"><i class="fa-solid fa-spinner fa-spin"></i> Em Separação (${conf.quantidade_total_conferida || 0}/${conf.quantidade_total_esperada || totalQtd})</span>`;
                btnActionText = '<i class="fa-solid fa-barcode"></i> Continuar';
                btnActionClass = 'btn-primary';
            } else if (conf.conferencia_status === 'conferido') {
                badgeWms = '<span class="badge badge-success"><i class="fa-solid fa-circle-check"></i> 100% Conferido</span>';
                btnActionText = '<i class="fa-solid fa-eye"></i> Ver';
                btnActionClass = 'btn-secondary';
            } else if (conf.conferencia_status === 'divergencia') {
                badgeWms = '<span class="badge badge-danger"><i class="fa-solid fa-triangle-exclamation"></i> Divergência</span>';
                btnActionText = '<i class="fa-solid fa-triangle-exclamation"></i> Revisar';
                btnActionClass = 'btn-danger';
            } else if (conf.conferencia_status === 'cancelado') {
                badgeWms = '<span class="badge badge-danger"><i class="fa-solid fa-ban"></i> Cancelado</span>';
                btnActionText = '<i class="fa-solid fa-rotate-right"></i> Reiniciar';
                btnActionClass = 'btn-secondary';
            }

            let btnCancelarHtml = '';
            if (conf.conferencia_status === 'em_separacao' || conf.conferencia_status === 'divergencia') {
                btnCancelarHtml = `
                    <button class="btn btn-sm btn-danger" onclick="App.abrirModalCancelarConferencia(${conf.conferencia_id || 0}, ${numPedido})" title="Cancelar Separação do Pedido #${numPedido}">
                        <i class="fa-solid fa-ban"></i>
                    </button>
                `;
            }

            html += `
                <tr>
                    <td>
                        <div style="display: flex; align-items: center; gap: 0.45rem;">
                            <strong class="font-mono" style="font-size: 1.05rem; color: #60a5fa; cursor: pointer;" onclick="App.copiarTexto('${numPedido}')" title="Clique para copiar número">#${numPedido}</strong>
                            <i class="fa-regular fa-copy" style="font-size: 0.75rem; color: var(--text-muted); cursor: pointer;" onclick="App.copiarTexto('${numPedido}')" title="Copiar"></i>
                        </div>
                    </td>
                    <td style="color: var(--text-secondary); font-size: 0.85rem;">${dataFormatada}</td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 0.65rem;">
                            <div style="width: 32px; height: 32px; border-radius: var(--radius-full); background: rgba(59, 130, 246, 0.15); color: #60a5fa; font-weight: 700; font-size: 0.78rem; display: flex; align-items: center; justify-content: center; border: 1px solid rgba(59, 130, 246, 0.3); flex-shrink: 0;">
                                ${clienteInitials}
                            </div>
                            <div>
                                <strong style="color: var(--text-primary); display: block; font-size: 0.9rem;">${clienteNome}</strong>
                                <span style="font-size: 0.75rem; color: var(--text-muted);">${p.ClienteCNPJ || ''}</span>
                            </div>
                        </div>
                    </td>
                    <td>${badgeSige}</td>
                    <td>
                        <div style="display: flex; flex-direction: column;">
                            <strong style="color: var(--text-primary); font-size: 0.88rem;">${totalItens} ${totalItens === 1 ? 'item' : 'itens'}</strong>
                            <span style="color: var(--text-secondary); font-size: 0.78rem;">${totalQtd} un</span>
                        </div>
                    </td>
                    <td>${badgeWms}</td>
                    <td style="color: var(--text-secondary); font-size: 0.85rem;">${conf.operador || '<span style="color: var(--text-muted);">-</span>'}</td>
                    <td style="text-align: right; white-space: nowrap;">
                        <div style="display: inline-flex; gap: 0.4rem;">
                            <button class="btn btn-sm btn-secondary" onclick="App.abrirRomaneio(${numPedido})" title="Visualizar Romaneio do Pedido #${numPedido}">
                                <i class="fa-solid fa-file-invoice"></i> Romaneio
                            </button>
                            ${btnCancelarHtml}
                            <button class="btn btn-sm ${btnActionClass}" onclick="App.iniciarConferencia(${numPedido})">
                                ${btnActionText}
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        });

        tbody.innerHTML = html;
    },

    setDefaultDateFilter() {
        const inputIni = document.getElementById('filtroDataInicial');
        const inputFim = document.getElementById('filtroDataFinal');
        if (inputIni && !inputIni.value) {
            const d = new Date();
            d.setDate(d.getDate() - 30);
            inputIni.value = d.toISOString().split('T')[0];
        }
        if (inputFim && !inputFim.value) {
            const today = new Date().toISOString().split('T')[0];
            inputFim.value = today;
        }
    },

    limparFiltros() {
        document.getElementById('formFiltroPedidos').reset();
        this.setDefaultDateFilter();
        this.buscarPedidos();
    },

    // --- SEPARAÇÃO & CONFERÊNCIA ATIVA ---
    async iniciarConferencia(numeroPedido) {
        this.toast(`Carregando pedido #${numeroPedido}...`, 'info');

        try {
            const res = await fetch('api/conferencia.php?action=iniciar', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    numero_pedido: numeroPedido,
                    operador: this.operador
                })
            });

            const data = await res.json();
            if (!data.success) {
                this.toast(data.error || 'Erro ao iniciar conferência.', 'error');
                return;
            }

            this.conferenciaAtiva = data;
            this.renderConferencia(data);
            this.navigate('conferencia');

            // Focar no campo manual de código
            setTimeout(() => {
                const inp = document.getElementById('inputManualBarcode');
                if (inp) inp.focus();
            }, 300);

        } catch (err) {
            this.toast('Falha ao conectar com o servidor local.', 'error');
        }
    },

    renderConferencia(data) {
        const conf = data.conferencia;
        const itens = data.itens || [];
        const volumes = data.volumes || [];

        document.getElementById('conferenciaVazia').style.display = 'none';
        document.getElementById('conferenciaAtivaContainer').style.display = 'grid';

        // Cabeçalho do Pedido
        document.getElementById('lblNumeroPedido').textContent = conf.numero_pedido;
        document.getElementById('lblClientePedido').textContent = `${conf.cliente} (Operador: ${conf.operador})`;

        // Badge de Status
        const badgeElem = document.getElementById('badgeStatusConferencia');
        if (conf.status === 'conferido') {
            badgeElem.className = 'badge badge-success';
            badgeElem.innerHTML = '<i class="fa-solid fa-check"></i> 100% Conferido';
        } else if (conf.status === 'divergencia') {
            badgeElem.className = 'badge badge-danger';
            badgeElem.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Divergência';
        } else if (conf.status === 'cancelado') {
            badgeElem.className = 'badge badge-danger';
            badgeElem.innerHTML = '<i class="fa-solid fa-ban"></i> Cancelado';
        } else {
            badgeElem.className = 'badge badge-progress';
            badgeElem.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Em Separação';
        }

        // Progresso Total
        const qtdConf = parseFloat(conf.quantidade_total_conferida) || 0;
        const qtdEsp = parseFloat(conf.quantidade_total_esperada) || 0;
        const pct = conf.porcentagem || 0;

        document.getElementById('lblQtdConferidaTotal').textContent = qtdConf;
        document.getElementById('lblQtdEsperadaTotal').textContent = qtdEsp;
        document.getElementById('lblPorcentagemProgresso').textContent = `${pct}%`;
        document.getElementById('progressBarFill').style.width = `${pct}%`;

        // Renderizar Lista de Itens
        this.renderItensList(itens);

        // Renderizar Volumes
        this.renderVolumesList(volumes);
    },

    renderItensList(itens) {
        const container = document.getElementById('itensPickingList');
        if (!itens || itens.length === 0) {
            container.innerHTML = '<p style="text-align: center; color: var(--text-muted); padding: 2rem;">Nenhum item cadastrado no pedido.</p>';
            return;
        }

        let html = '';
        itens.forEach(it => {
            const qtdPed = parseFloat(it.quantidade_pedida);
            const qtdConf = parseFloat(it.quantidade_conferida);
            const isDone = qtdConf >= qtdPed;

            let cardStatusClass = 'status-pendente';
            let statusBadgeHtml = '<span class="badge badge-pending"><i class="fa-regular fa-clock"></i> Pendente</span>';
            if (isDone) {
                cardStatusClass = 'status-conferido';
                statusBadgeHtml = '<span class="badge badge-success"><i class="fa-solid fa-check"></i> Concluído</span>';
            } else if (qtdConf > 0) {
                cardStatusClass = 'status-parcial';
                statusBadgeHtml = `<span class="badge badge-progress"><i class="fa-solid fa-spinner fa-spin"></i> ${qtdConf}/${qtdPed}</span>`;
            }

            const displayQtdEsperada = this.modoCego ? '?' : qtdPed;

            html += `
                <div class="item-picking-card ${cardStatusClass}" id="item-card-${it.codigo_produto}">
                    <div class="item-details">
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; margin-bottom: 0.35rem;">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <h4 class="mb-0">${it.descricao}</h4>
                                ${it.local_codigo ? `<span class="loc-badge loc-badge-picking" title="Localização no Armazém: ${it.local_armazem || 'Principal'} (Rua ${it.local_rua}, Est ${it.local_estante}, Nv ${it.local_nivel}, Vão ${it.local_posicao})"><i class="fa-solid fa-location-dot"></i> ${it.local_codigo}</span>` : '<span class="badge bg-body-secondary text-muted border fs-xs"><i class="fa-solid fa-location-cross"></i> Sem Local</span>'}
                            </div>
                            ${statusBadgeHtml}
                        </div>
                        <div class="item-meta">
                            <span>SKU: <strong class="font-mono" style="cursor: pointer; color: #60a5fa;" onclick="App.copiarTexto('${it.codigo_produto}')" title="Clique para copiar SKU">${it.codigo_produto} <i class="fa-regular fa-copy" style="font-size:0.7rem;"></i></strong></span>
                            <span>EAN: <strong class="font-mono" style="${it.ean ? 'cursor: pointer; color: #34d399;' : ''}" ${it.ean ? `onclick="App.copiarTexto('${it.ean}')" title="Clique para copiar EAN"` : ''}>${it.ean || '<i style="color:var(--text-muted)">Sem EAN</i>'} ${it.ean ? '<i class="fa-regular fa-copy" style="font-size:0.7rem;"></i>' : ''}</strong></span>
                            ${it.categoria ? `<span>Cat: <strong>${it.categoria}</strong></span>` : ''}
                            <span>Un: <strong>${it.unidade || 'UN'}</strong></span>
                        </div>
                    </div>

                    <div class="item-count-box">
                        <span class="conferido-num" style="${isDone ? 'color: var(--color-success);' : ''}">${qtdConf}</span>
                        <span class="separator">/</span>
                        <span class="total-num">${displayQtdEsperada}</span>
                    </div>

                    <div class="item-actions">
                        <button class="btn btn-secondary btn-sm btn-icon" onclick="App.ajustarQtdManual(${it.id}, ${qtdConf - 1})" title="Diminuir 1">
                            <i class="fa-solid fa-minus"></i>
                        </button>
                        <button class="btn btn-primary btn-sm btn-icon" onclick="App.ajustarQtdManual(${it.id}, ${qtdConf + 1})" title="Adicionar 1">
                            <i class="fa-solid fa-plus"></i>
                        </button>
                    </div>
                </div>
            `;
        });

        container.innerHTML = html;
    },

    renderVolumesList(volumes) {
        const container = document.getElementById('listaVolumesContainer');
        document.getElementById('lblTotalVolumes').textContent = volumes.length;

        if (!volumes || volumes.length === 0) {
            container.innerHTML = '<span style="color: var(--text-muted); font-size: 0.8rem; text-align: center; padding: 0.75rem 0.5rem; display: block;">Nenhum volume embalado ainda.</span>';
            return;
        }

        let html = '';
        volumes.forEach(v => {
            html += `
                <div style="background: rgba(15, 23, 42, 0.7); padding: 0.65rem 0.85rem; border-radius: var(--radius-md); border: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; transition: var(--transition);">
                    <div style="display: flex; align-items: center; gap: 0.65rem;">
                        <div style="width: 32px; height: 32px; border-radius: var(--radius-sm); background: rgba(59, 130, 246, 0.15); color: #60a5fa; display: flex; align-items: center; justify-content: center; font-size: 0.85rem;">
                            <i class="fa-solid fa-box"></i>
                        </div>
                        <div>
                            <strong style="font-size: 0.88rem; color: var(--text-primary);">Volume ${v.numero_volume}/${v.total_volumes}</strong>
                            <span style="font-size: 0.75rem; color: var(--text-secondary); display: block;">${v.peso_kg} KG ${v.dimensoes ? '• ' + v.dimensoes : ''}</span>
                        </div>
                    </div>
                    <div style="display: flex; gap: 0.35rem;">
                        <button class="btn btn-secondary btn-sm btn-icon" onclick="App.imprimirEtiquetaVolume(${v.id})" title="Imprimir Etiqueta">
                            <i class="fa-solid fa-print"></i>
                        </button>
                        <button class="btn btn-danger btn-sm btn-icon" onclick="App.removerVolume(${v.id})" title="Excluir Volume">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </div>
                </div>
            `;
        });

        container.innerHTML = html;
    },

    // --- LEITURA & BIPAGEM ---
    biparManual() {
        const inp = document.getElementById('inputManualBarcode');
        const code = inp.value.trim();
        if (code) {
            this.handleBarcodeScan(code, 'manual');
            inp.value = '';
        }
    },

    async handleBarcodeScan(code, type = 'camera') {
        // Se estiver na tela de recebimento ativo
        if (this.currentView === 'recebimento' && this.recebimentoAtivo) {
            this.biparRecebimento(code, type);
            return;
        }

        if (!this.conferenciaAtiva || !this.conferenciaAtiva.conferencia) {
            this.toast('Abra um pedido ou recebimento para bipar itens.', 'warning');
            this.showScanFeedback('Abra um pedido para bipar itens.', 'warning');
            return;
        }

        const confId = this.conferenciaAtiva.conferencia.id;

        try {
            const res = await fetch('api/conferencia.php?action=bipar', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    conferencia_id: confId,
                    codigo_bipado: code,
                    quantidade: 1,
                    tipo_leitura: type,
                    operador: this.operador
                })
            });

            const data = await res.json();

            if (!data.success) {
                window.soundEngine.playError();
                const msg = data.message || `Código não confere: ${code}`;
                this.toast(msg, 'error');
                this.showScanFeedback(msg, 'error');
                return;
            }

            // Sucesso!
            this.conferenciaAtiva = data;
            this.renderConferencia(data);

            const feedbackMsg = `Bipado com sucesso: ${code}`;
            this.showScanFeedback(feedbackMsg, 'success');

            // Animar o item conferido
            if (data.item_bipado) {
                const elem = document.getElementById(`item-card-${data.item_bipado}`);
                if (elem) {
                    elem.classList.add('just-scanned');
                    elem.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    setTimeout(() => elem.classList.remove('just-scanned'), 700);
                }
            }

            // Verificar se o pedido todo foi concluído
            const conf = data.conferencia;
            if (conf.quantidade_total_conferida >= conf.quantidade_total_esperada && conf.quantidade_total_esperada > 0) {
                window.soundEngine.playOrderComplete();
                this.toast('🎉 Parabéns! Pedido 100% conferido e separado!', 'success');
                this.showScanFeedback('🎉 Pedido 100% conferido!', 'success');
            } else if (data.item_concluido) {
                window.soundEngine.playItemDone();
                this.toast(`Item ${data.item_bipado || ''} concluído!`, 'success');
            } else {
                window.soundEngine.playSuccess();
            }

        } catch (e) {
            console.error('Erro na requisição de bipagem:', e);
            window.soundEngine.playError();
            this.toast('Erro de comunicação com o servidor.', 'error');
            this.showScanFeedback('Erro de comunicação ao validar código.', 'error');
        }
    },

    async ajustarQtdManual(itemId, novaQtd) {
        if (novaQtd < 0) novaQtd = 0;

        try {
            const res = await fetch('api/conferencia.php?action=ajustar_item', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    item_id: itemId,
                    quantidade: novaQtd,
                    operador: this.operador
                })
            });

            const data = await res.json();
            if (data.success) {
                this.conferenciaAtiva = data;
                this.renderConferencia(data);
                window.soundEngine.playSuccess();
                this.toast(data.message, 'info');
            } else {
                this.toast(data.error || 'Erro ao ajustar quantidade.', 'error');
            }
        } catch (e) {
            this.toast('Erro ao ajustar quantidade.', 'error');
        }
    },

    // --- CONTROLES DA CÂMERA ---
    async startCamera() {
        document.getElementById('btnStartCam').style.display = 'none';
        document.getElementById('btnStopCam').style.display = 'block';
        document.getElementById('scanLaser').style.display = 'block';

        this.camerasList = await this.scanner.getCameras();
        
        let camId = null;
        if (this.camerasList.length > 0) {
            // Se for a primeira inicialização da câmera, tenta detectar a câmera traseira automaticamente
            if (this.currentCameraIndex === 0) {
                const backKeywords = ['traseira', 'traseiro', 'back', 'rear', 'environment', 'principal', 'main'];
                const foundIndex = this.camerasList.findIndex(cam => {
                    const label = (cam.label || '').toLowerCase();
                    return backKeywords.some(keyword => label.includes(keyword));
                });

                if (foundIndex !== -1) {
                    this.currentCameraIndex = foundIndex;
                    camId = this.camerasList[this.currentCameraIndex].id;
                } else {
                    // Fallback para null (facingMode: environment) se não houver correspondência ou se as labels estiverem vazias
                    camId = null;
                }
            } else {
                camId = this.camerasList[this.currentCameraIndex % this.camerasList.length].id;
            }
        }

        const ok = await this.scanner.startCamera('reader', camId);
        if (!ok) {
            this.toast('Não foi possível acessar a câmera do dispositivo.', 'error');
            this.stopCamera();
        }
    },

    async stopCamera() {
        if (this.scanner) {
            await this.scanner.stopCamera();
        }
        const btnStart = document.getElementById('btnStartCam');
        const btnStop = document.getElementById('btnStopCam');
        const laser = document.getElementById('scanLaser');

        if (btnStart) btnStart.style.display = 'block';
        if (btnStop) btnStop.style.display = 'none';
        if (laser) laser.style.display = 'none';
    },

    async switchCamera() {
        if (this.camerasList.length <= 1) {
            this.camerasList = await this.scanner.getCameras();
        }
        if (this.camerasList.length > 1) {
            this.currentCameraIndex++;
            await this.startCamera();
        } else {
            this.toast('Apenas uma câmera detectada neste dispositivo.', 'info');
        }
    },

    async toggleTorch() {
        const on = await this.scanner.toggleTorch();
        const btn = document.getElementById('btnTorch');
        if (btn) {
            btn.classList.toggle('btn-primary', on);
            btn.classList.toggle('btn-secondary', !on);
        }
    },

    // --- VOLUMES & EMBALAGENS ---
    modalNovoVolume() {
        if (!this.conferenciaAtiva) {
            this.toast('Nenhum pedido ativo.', 'warning');
            return;
        }
        document.getElementById('modalVolume').classList.add('active');
        document.getElementById('volPeso').focus();
    },

    async salvarVolume(e) {
        e.preventDefault();
        const confId = this.conferenciaAtiva.conferencia.id;
        const peso = document.getElementById('volPeso').value;
        const dimensoes = document.getElementById('volDimensoes').value;

        try {
            const res = await fetch('api/conferencia.php?action=adicionar_volume', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    conferencia_id: confId,
                    peso_kg: peso,
                    dimensoes: dimensoes
                })
            });

            const data = await res.json();
            if (data.success) {
                this.conferenciaAtiva = data;
                this.renderConferencia(data);
                this.fecharModais();
                this.toast(data.message, 'success');
                document.getElementById('formVolume').reset();
            } else {
                this.toast(data.error || 'Erro ao adicionar volume.', 'error');
            }
        } catch (e) {
            this.toast('Erro ao conectar ao servidor.', 'error');
        }
    },

    async removerVolume(volumeId) {
        if (!confirm('Deseja realmente excluir este volume?')) return;

        try {
            const res = await fetch('api/conferencia.php?action=remover_volume', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ volume_id: volumeId })
            });

            const data = await res.json();
            if (data.success) {
                this.conferenciaAtiva = data;
                this.renderConferencia(data);
                this.toast(data.message, 'info');
            }
        } catch (e) {
            this.toast('Erro ao remover volume.', 'error');
        }
    },

    // --- FINALIZAÇÃO & AUDITORIA ---
    async finalizarConferencia() {
        if (!this.conferenciaAtiva) return;
        const conf = this.conferenciaAtiva.conferencia;

        const pendentes = conf.quantidade_total_esperada - conf.quantidade_total_conferida;
        let obs = '';

        if (pendentes > 0) {
            if (!confirm(`Atenção: Existem ${pendentes} itens não conferidos! Deseja finalizar mesmo assim como DIVERGÊNCIA?`)) {
                return;
            }
            obs = prompt('Informe uma observação sobre a divergência:') || 'Finalizado com divergência pelo operador';
        } else {
            if (!confirm(`Confirmar finalização da conferência do Pedido #${conf.numero_pedido}?`)) {
                return;
            }
        }

        try {
            const res = await fetch('api/conferencia.php?action=finalizar', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    conferencia_id: conf.id,
                    observacoes: obs,
                    operador: this.operador
                })
            });

            const data = await res.json();
            if (data.success) {
                this.toast(data.message, 'success');
                this.conferenciaAtiva = data;
                this.renderConferencia(data);
                this.carregarStats();
            }
        } catch (e) {
            this.toast('Erro ao finalizar conferência.', 'error');
        }
    },

    abrirModalCancelarConferencia(confId = null, numeroPedido = null) {
        const id = confId || (this.conferenciaAtiva?.conferencia?.id ?? null);
        const numPed = numeroPedido || (this.conferenciaAtiva?.conferencia?.numero_pedido ?? '---');

        if (!id && !numeroPedido) {
            this.toast('Nenhuma conferência selecionada para cancelar.', 'warning');
            return;
        }

        document.getElementById('cancelarConferenciaId').value = id || '';
        document.getElementById('cancelarNumeroPedido').value = (numPed !== '---') ? numPed : '';
        document.getElementById('lblCancelarNumeroPedido').textContent = `#${numPed}`;
        document.getElementById('cancelarMotivo').selectedIndex = 0;
        document.getElementById('cancelarObservacoes').value = '';

        document.getElementById('modalCancelarSeparacao').classList.add('active');
    },

    async confirmarCancelamentoSeparacao(event) {
        if (event) event.preventDefault();

        const confId = document.getElementById('cancelarConferenciaId').value;
        const numPedido = document.getElementById('cancelarNumeroPedido').value;
        const motivo = document.getElementById('cancelarMotivo').value;
        const observacoes = document.getElementById('cancelarObservacoes').value.trim();

        this.fecharModais();
        this.toast('Processando cancelamento...', 'info');

        try {
            const res = await fetch('api/conferencia.php?action=cancelar', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    conferencia_id: confId ? parseInt(confId) : 0,
                    numero_pedido: numPedido ? parseInt(numPedido) : 0,
                    motivo: motivo,
                    observacoes: observacoes,
                    operador: this.operador
                })
            });

            const data = await res.json();
            if (data.success) {
                this.toast(data.message, 'success');
                if (typeof AudioSystem !== 'undefined') {
                    AudioSystem.playAlert();
                }

                if (this.conferenciaAtiva && (this.conferenciaAtiva.conferencia.id == confId || this.conferenciaAtiva.conferencia.numero_pedido == numPedido)) {
                    this.conferenciaAtiva = data;
                    this.renderConferencia(data);
                }

                this.carregarStats();
                if (document.getElementById('view-pedidos').classList.contains('active')) {
                    this.buscarPedidos();
                } else if (document.getElementById('view-historico').classList.contains('active')) {
                    this.carregarHistorico();
                }
            } else {
                this.toast(data.error || 'Erro ao cancelar conferência.', 'error');
            }
        } catch (e) {
            this.toast('Erro de comunicação ao cancelar separação.', 'error');
        }
    },

    async reiniciarConferencia() {
        if (!this.conferenciaAtiva) return;
        if (!confirm('Tem certeza que deseja ZERAR toda a contagem deste pedido e reiniciar a separação?')) return;

        try {
            const res = await fetch('api/conferencia.php?action=reiniciar', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ conferencia_id: this.conferenciaAtiva.conferencia.id })
            });
            const data = await res.json();
            if (data.success) {
                this.conferenciaAtiva = data;
                this.renderConferencia(data);
                this.toast(data.message, 'info');
            }
        } catch (e) {
            this.toast('Erro ao reiniciar conferência.', 'error');
        }
    },

    // --- IMPRESSÃO & VISUALIZAÇÃO DE ROMANEIO & ETIQUETAS ---
    gerarRomaneioHTML(dados, svgPrefix = 'modal') {
        const conf = dados.conferencia || {};
        const itens = dados.itens || [];
        const vols = dados.volumes || [];

        const dataEmissao = new Date().toLocaleString('pt-BR');
        const numPedido = conf.numero_pedido || '0';
        const barcodeId = `barcodeRomaneio_${svgPrefix}`;
        
        let statusBadge = 'Em Separação';
        let statusBg = '#f59e0b';
        let statusColor = '#ffffff';
        if (conf.status === 'conferido') {
            statusBadge = '100% Conferido';
            statusBg = '#10b981';
        } else if (conf.status === 'divergencia') {
            statusBadge = 'Com Divergência';
            statusBg = '#ef4444';
        } else if (conf.status === 'cancelado') {
            statusBadge = 'Cancelado';
            statusBg = '#ef4444';
        } else if (conf.status === 'pendente') {
            statusBadge = 'Pendente';
            statusBg = '#64748b';
        }

        const totalQtdEsperada = conf.quantidade_total_esperada || itens.reduce((a, b) => a + (parseFloat(b.quantidade_pedida) || 0), 0);
        const totalQtdConferida = conf.quantidade_total_conferida || itens.reduce((a, b) => a + (parseFloat(b.quantidade_conferida) || 0), 0);

        const html = `
            <div class="romaneio-doc" style="font-family: Arial, Helvetica, sans-serif; color: #0f172a; background: #ffffff; padding: 20px; line-height: 1.4;">
                <!-- Header do Romaneio -->
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #0f172a; padding-bottom: 12px; margin-bottom: 16px;">
                    <div>
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                            <span style="background: #2563eb; color: #ffffff; font-weight: 800; font-size: 13px; padding: 3px 8px; border-radius: 4px; letter-spacing: 0.5px;">WMS LOGÍSTICA</span>
                            <span style="font-size: 12px; color: #64748b; font-weight: 600;">INTEGRAÇÃO SIGE CLOUD</span>
                        </div>
                        <h2 style="margin: 0; font-size: 20px; font-weight: 800; color: #0f172a;">ROMANEIO DE CONFERÊNCIA & EXPEDIÇÃO</h2>
                    </div>
                    <div style="text-align: right;">
                        <svg id="${barcodeId}"></svg>
                    </div>
                </div>

                <!-- Grid de Informações do Pedido -->
                <table style="width: 100%; border-collapse: collapse; margin-bottom: 18px; font-size: 13px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px;">
                    <tr>
                        <td style="padding: 8px 12px; border-bottom: 1px solid #e2e8f0; width: 33%;">
                            <span style="display: block; font-size: 10px; text-transform: uppercase; color: #64748b; font-weight: 700;">Número do Pedido</span>
                            <strong style="font-size: 16px; color: #0f172a;">#${numPedido}</strong>
                        </td>
                        <td style="padding: 8px 12px; border-bottom: 1px solid #e2e8f0; width: 34%;">
                            <span style="display: block; font-size: 10px; text-transform: uppercase; color: #64748b; font-weight: 700;">Cliente / Destinatário</span>
                            <strong style="color: #0f172a;">${conf.cliente || 'Consumidor Final'}</strong>
                        </td>
                        <td style="padding: 8px 12px; border-bottom: 1px solid #e2e8f0; width: 33%;">
                            <span style="display: block; font-size: 10px; text-transform: uppercase; color: #64748b; font-weight: 700;">Status Conferência</span>
                            <span style="display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 700; background: ${statusBg}; color: ${statusColor};">
                                ${statusBadge}
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 12px;">
                            <span style="display: block; font-size: 10px; text-transform: uppercase; color: #64748b; font-weight: 700;">Operador</span>
                            <span>${conf.operador || 'David'}</span>
                        </td>
                        <td style="padding: 8px 12px;">
                            <span style="display: block; font-size: 10px; text-transform: uppercase; color: #64748b; font-weight: 700;">Unidades Conferidas</span>
                            <strong>${totalQtdConferida} / ${totalQtdEsperada} unidades</strong>
                        </td>
                        <td style="padding: 8px 12px;">
                            <span style="display: block; font-size: 10px; text-transform: uppercase; color: #64748b; font-weight: 700;">Data & Hora</span>
                            <span>${dataEmissao}</span>
                        </td>
                    </tr>
                </table>

                <!-- Tabela de Itens -->
                <div style="margin-bottom: 18px;">
                    <h3 style="font-size: 13px; font-weight: 700; color: #0f172a; text-transform: uppercase; margin: 0 0 6px 0; border-bottom: 1.5px solid #0f172a; padding-bottom: 3px;">
                        Itens do Pedido (${itens.length} produto${itens.length === 1 ? '' : 's'})
                    </h3>
                    <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                        <thead>
                            <tr style="background: #f1f5f9; color: #0f172a;">
                                <th style="border: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; width: 15%;">SKU</th>
                                <th style="border: 1px solid #cbd5e1; padding: 6px 8px; text-align: left;">Descrição do Produto</th>
                                <th style="border: 1px solid #cbd5e1; padding: 6px 8px; text-align: center; width: 18%;">EAN / Cód. Barras</th>
                                <th style="border: 1px solid #cbd5e1; padding: 6px 8px; text-align: center; width: 10%;">Qtd Ped.</th>
                                <th style="border: 1px solid #cbd5e1; padding: 6px 8px; text-align: center; width: 10%;">Qtd Conf.</th>
                                <th style="border: 1px solid #cbd5e1; padding: 6px 8px; text-align: center; width: 12%;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${itens.map((it, idx) => {
                                const bg = idx % 2 === 0 ? '#ffffff' : '#f8fafc';
                                const st = (it.status || 'pendente').toUpperCase();
                                let corSt = '#64748b';
                                if (st === 'CONFERIDO') corSt = '#16a34a';
                                else if (st === 'PARCIAL') corSt = '#d97706';
                                return `
                                    <tr style="background: ${bg};">
                                        <td style="border: 1px solid #cbd5e1; padding: 5px 8px; font-family: monospace; font-weight: 700;">${it.codigo_produto}</td>
                                        <td style="border: 1px solid #cbd5e1; padding: 5px 8px;">${it.descricao}</td>
                                        <td style="border: 1px solid #cbd5e1; padding: 5px 8px; text-align: center; font-family: monospace;">${it.ean || '-'}</td>
                                        <td style="border: 1px solid #cbd5e1; padding: 5px 8px; text-align: center;">${it.quantidade_pedida}</td>
                                        <td style="border: 1px solid #cbd5e1; padding: 5px 8px; text-align: center; font-weight: 700;">${it.quantidade_conferida}</td>
                                        <td style="border: 1px solid #cbd5e1; padding: 5px 8px; text-align: center; font-weight: 700; color: ${corSt};">${st}</td>
                                    </tr>
                                `;
                            }).join('')}
                        </tbody>
                    </table>
                </div>

                <!-- Volumes Registrados (se houver) -->
                ${vols.length > 0 ? `
                    <div style="margin-bottom: 18px;">
                        <h3 style="font-size: 13px; font-weight: 700; color: #0f172a; text-transform: uppercase; margin: 0 0 6px 0; border-bottom: 1.5px solid #0f172a; padding-bottom: 3px;">
                            Volumes / Caixas Embaladas (${vols.length} volume${vols.length === 1 ? '' : 's'})
                        </h3>
                        <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                            <thead>
                                <tr style="background: #f1f5f9; color: #0f172a;">
                                    <th style="border: 1px solid #cbd5e1; padding: 5px 8px; text-align: center; width: 80px;">Volume</th>
                                    <th style="border: 1px solid #cbd5e1; padding: 5px 8px; text-align: left;">Etiqueta / Rastreio</th>
                                    <th style="border: 1px solid #cbd5e1; padding: 5px 8px; text-align: center;">Peso (KG)</th>
                                    <th style="border: 1px solid #cbd5e1; padding: 5px 8px; text-align: left;">Dimensões</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${vols.map((v, idx) => `
                                    <tr style="background: ${idx % 2 === 0 ? '#ffffff' : '#f8fafc'};">
                                        <td style="border: 1px solid #cbd5e1; padding: 5px 8px; text-align: center; font-weight: 700;">${v.numero_volume} / ${v.total_volumes}</td>
                                        <td style="border: 1px solid #cbd5e1; padding: 5px 8px; font-family: monospace;">${v.etiqueta_codigo || '-'}</td>
                                        <td style="border: 1px solid #cbd5e1; padding: 5px 8px; text-align: center;">${v.peso_kg ? v.peso_kg + ' kg' : '-'}</td>
                                        <td style="border: 1px solid #cbd5e1; padding: 5px 8px;">${v.dimensoes || '-'}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                ` : ''}

                <!-- Assinaturas -->
                <div style="margin-top: 35px; display: grid; grid-template-columns: 1fr 1fr; gap: 40px;">
                    <div style="border-top: 1px solid #0f172a; text-align: center; padding-top: 6px;">
                        <div style="font-weight: 700; font-size: 12px; color: #0f172a;">${conf.operador || 'Conferente Responsável'}</div>
                        <div style="font-size: 11px; color: #64748b;">Assinatura do Conferente / Operador</div>
                    </div>
                    <div style="border-top: 1px solid #0f172a; text-align: center; padding-top: 6px;">
                        <div style="font-weight: 700; font-size: 12px; color: #0f172a;">Transportadora / Motorista</div>
                        <div style="font-size: 11px; color: #64748b;">Nome Legível e Assinatura</div>
                    </div>
                </div>
            </div>
        `;

        return { html, barcodeId, numPedido };
    },

    async abrirRomaneio(numeroPedido = null) {
        let dadosRomaneio = null;

        if (numeroPedido && (!this.conferenciaAtiva || this.conferenciaAtiva.conferencia.numero_pedido != numeroPedido)) {
            this.toast(`Carregando romaneio do pedido #${numeroPedido}...`, 'info');
            try {
                const res = await fetch(`api/conferencia.php?action=romaneio&numero_pedido=${numeroPedido}`);
                const data = await res.json();
                if (data.success && data.conferencia) {
                    dadosRomaneio = data;
                } else {
                    this.toast(data.error || 'Não foi possível carregar dados do romaneio.', 'error');
                    return;
                }
            } catch (e) {
                this.toast('Erro ao buscar dados do romaneio.', 'error');
                return;
            }
        } else if (this.conferenciaAtiva) {
            dadosRomaneio = this.conferenciaAtiva;
        } else {
            this.toast('Selecione ou inicie um pedido para visualizar o romaneio.', 'warning');
            return;
        }

        this.ultimoRomaneioDados = dadosRomaneio;

        // Renderizar no modal e na área de impressão
        const modalDiv = document.getElementById('modalRomaneioConteudo');
        const printDiv = document.getElementById('printArea');

        const { html: modalHtml, barcodeId: modalBarcodeId, numPedido } = this.gerarRomaneioHTML(dadosRomaneio, 'modal');
        const { html: printHtml, barcodeId: printBarcodeId } = this.gerarRomaneioHTML(dadosRomaneio, 'print');

        if (modalDiv) modalDiv.innerHTML = modalHtml;
        if (printDiv) printDiv.innerHTML = printHtml;

        // Gerar código de barras
        if (window.JsBarcode) {
            try {
                JsBarcode(`#${modalBarcodeId}`, String(numPedido), {
                    format: 'CODE128',
                    height: 32,
                    width: 1.4,
                    displayValue: true,
                    fontSize: 11
                });
                JsBarcode(`#${printBarcodeId}`, String(numPedido), {
                    format: 'CODE128',
                    height: 32,
                    width: 1.4,
                    displayValue: true,
                    fontSize: 11
                });
            } catch (err) {
                console.warn('JsBarcode barcode error:', err);
            }
        }

        // Abrir Modal
        document.getElementById('modalRomaneio').classList.add('active');
    },

    imprimirRomaneio(numeroPedido = null) {
        this.abrirRomaneio(numeroPedido);
    },

    imprimirRomaneioAtual() {
        if (!this.ultimoRomaneioDados && !this.conferenciaAtiva) {
            this.toast('Nenhum romaneio carregado para impressão.', 'warning');
            return;
        }
        window.print();
    },

    imprimirEtiquetaVolume(volumeId) {
        if (!this.conferenciaAtiva) return;
        const conf = this.conferenciaAtiva.conferencia;
        const vols = this.conferenciaAtiva.volumes || [];
        const vol = vols.find(v => v.id === volumeId);
        if (!vol) return;

        const printDiv = document.getElementById('printArea');
        printDiv.innerHTML = `
            <div style="font-family: Arial, sans-serif; padding: 15px; width: 100mm; color: #000; background: #fff; border: 2px dashed #000;">
                <div style="text-align: center; border-bottom: 2px solid #000; padding-bottom: 8px; margin-bottom: 10px;">
                    <h2 style="margin: 0; font-size: 18px;">ETIQUETA DE EXPEDIÇÃO</h2>
                    <span style="font-size: 11px;">PRIME PRO LOGÍSTICA</span>
                </div>

                <div style="margin-bottom: 10px; font-size: 13px;">
                    <p style="margin: 3px 0;"><strong>PEDIDO:</strong> #${conf.numero_pedido}</p>
                    <p style="margin: 3px 0;"><strong>DESTINATÁRIO:</strong> ${conf.cliente}</p>
                    <p style="margin: 3px 0; font-size: 16px;"><strong>VOLUME:</strong> ${vol.numero_volume} / ${vol.total_volumes}</p>
                    <p style="margin: 3px 0;"><strong>PESO:</strong> ${vol.peso_kg} KG ${vol.dimensoes ? '(' + vol.dimensoes + ')' : ''}</p>
                    <p style="margin: 3px 0;"><strong>OPERADOR:</strong> ${conf.operador}</p>
                </div>

                <div style="text-align: center; margin-top: 15px;">
                    <svg id="barcodeVolumeEtiqueta"></svg>
                </div>
            </div>
        `;

        if (window.JsBarcode) {
            JsBarcode('#barcodeVolumeEtiqueta', vol.etiqueta_codigo || `PED-${conf.numero_pedido}-VOL-${vol.numero_volume}`, {
                format: 'CODE128',
                height: 45,
                width: 2,
                displayValue: true,
                fontSize: 13
            });
        }

        window.print();
    },

    // --- HISTÓRICO ---
    async carregarHistorico() {
        const tbody = document.getElementById('historicoTableBody');
        const busca = document.getElementById('filtroHistoricoBusca')?.value.trim() || '';
        const status = document.getElementById('filtroHistoricoStatus')?.value.trim() || '';

        try {
            const params = new URLSearchParams({ action: 'historico' });
            if (busca) params.append('busca', busca);
            if (status) params.append('status', status);

            const res = await fetch(`api/conferencia.php?${params.toString()}`);
            const data = await res.json();

            if (!data.success || !data.data || data.data.length === 0) {
                tbody.innerHTML = `<tr><td colspan="9" style="text-align: center; padding: 2rem; color: var(--text-muted);">Nenhum registro de conferência encontrado.</td></tr>`;
                return;
            }

            let html = '';
            data.data.forEach(c => {
                let badge = '<span class="badge badge-progress">Em Separação</span>';
                if (c.status === 'conferido') badge = '<span class="badge badge-success">100% Conferido</span>';
                else if (c.status === 'divergencia') badge = '<span class="badge badge-danger">Divergência</span>';
                else if (c.status === 'cancelado') badge = '<span class="badge badge-danger">Cancelado</span>';

                html += `
                    <tr>
                        <td><strong class="font-mono">#${c.numero_pedido}</strong></td>
                        <td>${c.cliente}</td>
                        <td>${c.operador}</td>
                        <td>${badge}</td>
                        <td>${c.quantidade_total_conferida} / ${c.quantidade_total_esperada}</td>
                        <td>${c.total_volumes_registrados || 0}</td>
                        <td style="font-size: 0.8rem; color: var(--text-muted);">${c.data_inicio ? new Date(c.data_inicio).toLocaleString('pt-BR') : '-'}</td>
                        <td style="font-size: 0.8rem; color: var(--text-muted);">${c.data_fim ? new Date(c.data_fim).toLocaleString('pt-BR') : '-'}</td>
                        <td style="text-align: right; white-space: nowrap;">
                            <button class="btn btn-sm btn-secondary" onclick="App.abrirRomaneio(${c.numero_pedido})" title="Visualizar Romaneio">
                                <i class="fa-solid fa-file-invoice"></i> Romaneio
                            </button>
                            <button class="btn btn-sm btn-primary" onclick="App.iniciarConferencia(${c.numero_pedido})" title="Abrir Separação">
                                <i class="fa-solid fa-folder-open"></i> Abrir
                            </button>
                        </td>
                    </tr>
                `;
            });

            tbody.innerHTML = html;
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="9" style="text-align: center; color: var(--color-danger);">Erro ao carregar histórico.</td></tr>`;
        }
    },

    // --- DE-PARA EAN ---
    async carregarEans() {
        const tbody = document.getElementById('eansTableBody');
        try {
            const res = await fetch('api/configuracoes.php?action=list_eans');
            const data = await res.json();

            if (!data.success || !data.data || data.data.length === 0) {
                tbody.innerHTML = `<tr><td colspan="5" style="text-align: center; padding: 2rem; color: var(--text-muted);">Nenhum mapeamento customizado cadastrado.</td></tr>`;
                return;
            }

            let html = '';
            data.data.forEach(e => {
                html += `
                    <tr>
                        <td><strong class="font-mono">${e.codigo_produto}</strong></td>
                        <td><strong class="font-mono" style="color: var(--color-primary);">${e.ean_adicional}</strong></td>
                        <td>${e.descricao || '-'}</td>
                        <td style="font-size: 0.8rem; color: var(--text-muted);">${new Date(e.criado_em).toLocaleDateString('pt-BR')}</td>
                        <td style="text-align: right;">
                            <button class="btn btn-danger btn-sm btn-icon" onclick="App.excluirEan(${e.id})">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="5" style="text-align: center; color: var(--color-danger);">Erro ao listar códigos.</td></tr>`;
        }
    },

    modalNovoEan() {
        document.getElementById('modalEan').classList.add('active');
        document.getElementById('eanSku').focus();
    },

    async salvarNovoEan(e) {
        e.preventDefault();
        const sku = document.getElementById('eanSku').value.trim();
        const ean = document.getElementById('eanCodigoBarra').value.trim();
        const desc = document.getElementById('eanDescricao').value.trim();

        try {
            const res = await fetch('api/configuracoes.php?action=save_ean', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    codigo_produto: sku,
                    ean_adicional: ean,
                    descricao: desc
                })
            });
            const data = await res.json();
            if (data.success) {
                this.toast(data.message, 'success');
                this.fecharModais();
                document.getElementById('formEan').reset();
                this.carregarEans();
            } else {
                this.toast(data.error || 'Erro ao salvar vínculo.', 'error');
            }
        } catch (e) {
            this.toast('Erro de comunicação.', 'error');
        }
    },

    async excluirEan(id) {
        if (!confirm('Deseja excluir este vínculo de código de barras?')) return;
        try {
            const res = await fetch('api/configuracoes.php?action=delete_ean', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id })
            });
            const data = await res.json();
            if (data.success) {
                this.toast(data.message, 'info');
                this.carregarEans();
            }
        } catch (e) {
            this.toast('Erro ao excluir vínculo.', 'error');
        }
    },

    // --- CONFIGURAÇÕES ---
    async carregarConfiguracoes() {
        try {
            const res = await fetch('api/configuracoes.php?action=get');
            const data = await res.json();
            if (data.success && data.configuracoes) {
                const c = data.configuracoes;
                if (document.getElementById('cfgToken')) document.getElementById('cfgToken').value = c.sige_token || '';
                if (document.getElementById('cfgUser')) document.getElementById('cfgUser').value = c.sige_user || '';
                if (document.getElementById('cfgApp')) document.getElementById('cfgApp').value = c.sige_app || '';
                if (document.getElementById('cfgOperadorPadrao')) document.getElementById('cfgOperadorPadrao').value = c.operador_padrao || 'David';
                if (document.getElementById('cfgModoCego')) document.getElementById('cfgModoCego').value = c.modo_cego || '0';

                this.modoCego = (c.modo_cego === '1');
            }

            this.carregarListaBackups();
        } catch (e) {
            console.error('Erro ao carregar configurações:', e);
        }
    },

    async salvarConfiguracoes(e) {
        e.preventDefault();
        const payload = {
            sige_token: document.getElementById('cfgToken').value.trim(),
            sige_user: document.getElementById('cfgUser').value.trim(),
            sige_app: document.getElementById('cfgApp').value.trim(),
            operador_padrao: document.getElementById('cfgOperadorPadrao').value.trim(),
            modo_cego: document.getElementById('cfgModoCego').value
        };

        try {
            const res = await fetch('api/configuracoes.php?action=save', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (data.success) {
                this.toast(data.message, 'success');
                this.modoCego = (payload.modo_cego === '1');
            }
        } catch (e) {
            this.toast('Erro ao salvar configurações.', 'error');
        }
    },

    async testarConexaoSige() {
        this.toast('Testando autenticação no SIGE Cloud...', 'info');
        try {
            const res = await fetch('api/configuracoes.php?action=test_sige', { method: 'POST' });
            const data = await res.json();
            if (data.success) {
                this.toast(data.message, 'success');
            } else {
                this.toast(data.message || 'Falha na conexão com SIGE Cloud.', 'error');
            }
        } catch (e) {
            this.toast('Erro ao testar conexão.', 'error');
        }
    },

    async gerarBackupDb() {
        this.toast('Gerando snapshot do banco de dados...', 'info');
        try {
            const res = await fetch('api/configuracoes.php?action=backup_db', { method: 'POST' });
            const data = await res.json();
            if (data.success) {
                this.toast(`${data.message} (${data.size_kb} KB)`, 'success');
                this.carregarListaBackups();
            } else {
                this.toast(data.error || 'Falha ao gerar backup.', 'error');
            }
        } catch (e) {
            this.toast('Erro de comunicação ao gerar backup.', 'error');
        }
    },

    async carregarListaBackups() {
        const container = document.getElementById('listaBackupsBody');
        if (!container) return;

        try {
            const res = await fetch('api/configuracoes.php?action=list_backups');
            const data = await res.json();
            if (data.success && data.backups) {
                if (data.backups.length === 0) {
                    container.innerHTML = '<span style="color: var(--text-muted);">Nenhum arquivo de backup gerado ainda.</span>';
                    return;
                }

                let html = '<div style="display: flex; flex-direction: column; gap: 0.5rem;">';
                data.backups.forEach(b => {
                    html += `
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.4rem 0.6rem; background: rgba(255,255,255,0.03); border-radius: var(--radius-sm); border: 1px solid rgba(255,255,255,0.05);">
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <i class="fa-solid fa-file-shield" style="color: var(--accent-green);"></i>
                                <span class="font-mono" style="color: var(--text-primary); font-weight: 500;">${b.filename}</span>
                                <span class="badge" style="font-size: 0.7rem; background: rgba(59,130,246,0.2); color: #60a5fa;">${b.size_kb} KB</span>
                            </div>
                            <span style="color: var(--text-muted); font-size: 0.75rem;">${b.created_at}</span>
                        </div>
                    `;
                });
                html += '</div>';
                container.innerHTML = html;
            }
        } catch (e) {
            container.innerHTML = '<span style="color: var(--danger-color);">Erro ao carregar histórico de backups.</span>';
        }
    },

    // --- GERENCIAMENTO DE USUÁRIOS (CRUD) ---
    async carregarUsuarios() {
        const tbody = document.getElementById('usuariosTableBody');
        const busca = document.getElementById('filtroUsuariosBusca')?.value.trim() || '';
        const funcao = document.getElementById('filtroUsuariosFuncao')?.value || '';
        const status = document.getElementById('filtroUsuariosStatus')?.value || '';

        try {
            const params = new URLSearchParams({ action: 'list' });
            if (busca) params.append('q', busca);
            if (funcao) params.append('funcao', funcao);
            if (status) params.append('status', status);

            const res = await fetch(`api/usuarios.php?${params.toString()}`);
            const data = await res.json();

            if (!data.success) {
                tbody.innerHTML = `<tr><td colspan="6" style="text-align: center; color: var(--color-danger); padding: 2rem;">${data.error || 'Erro ao listar usuários.'}</td></tr>`;
                return;
            }

            // Atualizar KPIs
            if (data.stats) {
                document.getElementById('kpiTotalUsuarios').textContent = data.stats.total || 0;
                document.getElementById('kpiUsuariosAtivos').textContent = data.stats.ativos || 0;
                document.getElementById('kpiUsuariosOperadores').textContent = data.stats.operadores || 0;
                document.getElementById('kpiUsuariosAdmins').textContent = data.stats.admins || 0;
            }

            this.renderTabelaUsuarios(data.data || []);
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="6" style="text-align: center; color: var(--color-danger); padding: 2rem;">Erro de conexão ao carregar usuários.</td></tr>`;
        }
    },

    renderTabelaUsuarios(usuarios) {
        const tbody = document.getElementById('usuariosTableBody');
        if (!usuarios || usuarios.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="7" style="text-align: center; padding: 2.5rem; color: var(--text-muted);">
                        <i class="fa-solid fa-user-slash fa-2x" style="margin-bottom: 0.5rem;"></i><br>
                        Nenhum usuário encontrado com os filtros selecionados.
                    </td>
                </tr>
            `;
            return;
        }

        let html = '';
        usuarios.forEach(u => {
            // Obter iniciais do nome
            const nomes = (u.nome || '').trim().split(/\s+/);
            let iniciais = '';
            if (nomes.length >= 2) {
                iniciais = (nomes[0][0] + nomes[nomes.length - 1][0]).toUpperCase();
            } else if (nomes.length === 1 && nomes[0]) {
                iniciais = nomes[0].substring(0, 2).toUpperCase();
            } else {
                iniciais = 'US';
            }

            const avatarCor = u.avatar_cor || '#3b82f6';

            // Badge da função
            let roleBadge = '';
            if (u.funcao === 'admin') {
                roleBadge = '<span class="badge-role badge-role-admin"><i class="fa-solid fa-shield"></i> Administrador</span>';
            } else if (u.funcao === 'supervisor') {
                roleBadge = '<span class="badge-role badge-role-supervisor"><i class="fa-solid fa-user-tie"></i> Supervisor</span>';
            } else if (u.funcao === 'conferente') {
                roleBadge = '<span class="badge-role badge-role-conferente"><i class="fa-solid fa-barcode"></i> Conferente</span>';
            } else {
                roleBadge = '<span class="badge-role badge-role-operador"><i class="fa-solid fa-dolly"></i> Operador</span>';
            }

            // Badge de Permissões
            let permBadge = '';
            if (u.is_admin || u.funcao === 'admin') {
                permBadge = `<span class="perm-badge-pill full-admin" title="Acesso total a todas as permissões"><i class="fa-solid fa-shield-halved"></i> Acesso Total</span>`;
            } else if (u.tem_customizacao) {
                permBadge = `<span class="perm-badge-pill custom-badge" title="Permissões personalizadas"><i class="fa-solid fa-sliders"></i> ${u.total_permissoes} / ${u.total_catalogo}</span>`;
            } else {
                permBadge = `<span class="perm-badge-pill default-badge" title="Permissões padrão do cargo (${u.funcao})"><i class="fa-solid fa-wand-magic-sparkles"></i> ${u.total_permissoes} / ${u.total_catalogo}</span>`;
            }

            // Status Pill com toggle rápido
            const isAtivo = (u.status === 'ativo');
            const statusPill = `
                <span class="status-pill ${isAtivo ? 'ativo' : 'inativo'}" onclick="App.alternarStatusUsuario(${u.id}, '${u.status}')" title="Clique para alternar status">
                    <span class="dot"></span> ${isAtivo ? 'Ativo' : 'Inativo'}
                </span>
            `;

            // Data de cadastro formatada
            const dataCriado = u.criado_em ? new Date(u.criado_em).toLocaleDateString('pt-BR') : '-';

            // PIN mascarado
            const pinDisplay = u.has_pin ? `<span class="font-mono" style="color: var(--text-secondary); background: var(--bg-primary); padding: 2px 6px; border-radius: 4px;">••••</span>` : '<span style="color: var(--text-muted);">-</span>';

            html += `
                <tr>
                    <td>
                        <div class="user-cell">
                            <div class="user-avatar" style="background: ${avatarCor};">
                                ${iniciais}
                            </div>
                            <div class="user-info-text">
                                <span class="user-name">${u.nome}</span>
                                <span class="user-email">${u.email}</span>
                            </div>
                        </div>
                    </td>
                    <td>${roleBadge}</td>
                    <td>${permBadge}</td>
                    <td>${statusPill}</td>
                    <td>${pinDisplay}</td>
                    <td style="font-size: 0.825rem; color: var(--text-muted);">${dataCriado}</td>
                    <td style="text-align: right; white-space: nowrap;">
                        <button class="btn btn-secondary btn-sm" onclick="App.modalPermissoesUsuario(${u.id})" title="Gerenciar Permissões" style="margin-right: 0.25rem;">
                            <i class="fa-solid fa-shield-halved"></i>
                        </button>
                        <button class="btn btn-secondary btn-sm" onclick="App.modalEditarUsuario(${u.id})" title="Editar Usuário" style="margin-right: 0.25rem;">
                            <i class="fa-solid fa-pen-to-square"></i>
                        </button>
                        <button class="btn btn-danger btn-sm" onclick="App.confirmarExclusaoUsuario(${u.id}, '${u.nome.replace(/'/g, "\\'")}')" title="Excluir Usuário">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </td>
                </tr>
            `;
        });

        tbody.innerHTML = html;
    },

    // --- GERENCIAMENTO DE PERMISSÕES ---
    async modalPermissoesUsuario(id) {
        try {
            const res = await fetch(`api/usuarios.php?action=get_permissions&id=${id}`);
            const data = await res.json();

            if (!data.success || !data.usuario) {
                this.toast(data.error || 'Erro ao carregar permissões do usuário.', 'error');
                return;
            }

            this.permData = data;
            const u = data.usuario;

            document.getElementById('permUserId').value = u.id;
            document.getElementById('permUserName').textContent = u.nome;
            document.getElementById('permUserEmail').innerHTML = `<i class="fa-solid fa-envelope"></i> ${u.email}`;

            // Avatar
            const avatar = document.getElementById('permUserAvatar');
            if (avatar) {
                avatar.style.background = u.avatar_cor || '#3b82f6';
                const nomes = (u.nome || '').trim().split(/\s+/);
                avatar.textContent = (nomes.length >= 2 ? nomes[0][0] + nomes[nomes.length - 1][0] : (nomes[0]?.substring(0, 2) || 'US')).toUpperCase();
            }

            // Role badge & description
            const roleBadge = document.getElementById('permUserRoleBadge');
            const roleNameDesc = document.getElementById('permRoleNameDesc');
            const roleLabels = { 'admin': 'Administrador', 'supervisor': 'Supervisor', 'conferente': 'Conferente', 'operador': 'Operador' };
            const roleNome = roleLabels[u.funcao] || u.funcao;
            if (roleBadge) {
                roleBadge.textContent = roleNome;
                roleBadge.className = `role-badge badge-role-${u.funcao}`;
            }
            if (roleNameDesc) roleNameDesc.textContent = roleNome;

            // Definir modo
            const isCustom = !u.usar_padrao;
            const rCustom = document.getElementById('permModeCustom');
            const rDefault = document.getElementById('permModeDefault');
            if (rCustom) rCustom.checked = isCustom;
            if (rDefault) rDefault.checked = !isCustom;

            const lblCustom = document.getElementById('lblPermModeCustom');
            const lblDefault = document.getElementById('lblPermModeDefault');
            if (lblCustom) lblCustom.classList.toggle('active', isCustom);
            if (lblDefault) lblDefault.classList.toggle('active', !isCustom);

            // Renderizar permissões
            this.renderCategoriasPermissoes(u.permissoes_efetivas, !isCustom);
            this.atualizarContagemPermissoes();

            // Limpar busca
            const searchInput = document.getElementById('inputBuscaPermissoes');
            if (searchInput) searchInput.value = '';

            document.getElementById('modalPermissoesUsuario').classList.add('active');
        } catch (e) {
            console.error('Erro ao abrir permissões:', e);
            this.toast('Erro ao obter permissões do usuário.', 'error');
        }
    },

    renderCategoriasPermissoes(permissoesAtivas, isDisabled = false) {
        const container = document.getElementById('permCategoriesContainer');
        if (!container || !this.permData || !this.permData.categorias) return;

        let html = '';
        const categorias = this.permData.categorias;

        for (const [catNome, itens] of Object.entries(categorias)) {
            const totalCat = itens.length;
            const ativasCat = itens.filter(it => permissoesAtivas.includes(it.id)).length;
            const isAllActive = (ativasCat === totalCat);

            let catIcon = 'fa-solid fa-folder';
            if (catNome === 'Pedidos') catIcon = 'fa-solid fa-clipboard-list';
            else if (catNome === 'Separação') catIcon = 'fa-solid fa-barcode';
            else if (catNome === 'Histórico') catIcon = 'fa-solid fa-clock-rotate-left';
            else if (catNome === 'De-Para EAN') catIcon = 'fa-solid fa-tags';
            else if (catNome === 'Usuários') catIcon = 'fa-solid fa-users';
            else if (catNome === 'Ajustes') catIcon = 'fa-solid fa-gear';

            html += `
                <div class="perm-category-card" data-cat-name="${catNome}">
                    <div class="perm-category-header">
                        <div class="perm-category-title">
                            <i class="${catIcon}" style="color: var(--color-primary);"></i> ${catNome}
                        </div>
                        <span class="perm-category-badge ${isAllActive ? 'all-active' : ''}" id="badgeCat_${catNome}">
                            ${ativasCat} de ${totalCat}
                        </span>
                    </div>
                    <div class="perm-category-grid">
            `;

            itens.forEach(it => {
                const isChecked = permissoesAtivas.includes(it.id);
                html += `
                    <div class="perm-item-card ${isChecked ? 'active' : ''} ${isDisabled ? 'disabled-mode' : ''}" 
                         data-perm-id="${it.id}" 
                         onclick="App.togglePermissaoItem('${it.id}')">
                        <div class="perm-switch-wrapper" onclick="event.stopPropagation()">
                            <input type="checkbox" 
                                   id="chkPerm_${it.id}" 
                                   class="perm-checkbox" 
                                   data-perm-id="${it.id}" 
                                   ${isChecked ? 'checked' : ''} 
                                   ${isDisabled ? 'disabled' : ''}
                                   onchange="App.onPermCheckboxChange('${it.id}')">
                            <span class="perm-switch-slider"></span>
                        </div>
                        <div class="perm-item-info">
                            <div class="perm-item-title">
                                <i class="${it.icone || 'fa-solid fa-check'}" style="color: var(--text-secondary); font-size: 0.8rem;"></i>
                                <span>${it.nome}</span>
                            </div>
                            <div class="perm-item-desc">${it.descricao}</div>
                        </div>
                    </div>
                `;
            });

            html += `
                    </div>
                </div>
            `;
        }

        container.innerHTML = html;
    },

    alternarModoPermissao(modo) {
        const isCustom = (modo === 'custom');
        const rCustom = document.getElementById('permModeCustom');
        const rDefault = document.getElementById('permModeDefault');
        if (rCustom) rCustom.checked = isCustom;
        if (rDefault) rDefault.checked = !isCustom;

        const lblCustom = document.getElementById('lblPermModeCustom');
        const lblDefault = document.getElementById('lblPermModeDefault');
        if (lblCustom) lblCustom.classList.toggle('active', isCustom);
        if (lblDefault) lblDefault.classList.toggle('active', !isCustom);

        if (!this.permData) return;

        let permissoesParaExibir = [];
        if (!isCustom) {
            // Modo padrão da função
            const funcao = this.permData.usuario.funcao || 'operador';
            permissoesParaExibir = this.permData.presets[funcao] || [];
        } else {
            // Modo customizado: usar as que já estão marcadas
            permissoesParaExibir = this.obterPermissoesSelecionadas();
            if (permissoesParaExibir.length === 0) {
                permissoesParaExibir = this.permData.usuario.permissoes_efetivas || [];
            }
        }

        this.renderCategoriasPermissoes(permissoesParaExibir, !isCustom);
        this.atualizarContagemPermissoes();
    },

    aplicarPresetPermissoes(preset) {
        if (!this.permData || !this.permData.presets) return;

        const lista = this.permData.presets[preset];
        if (!lista) return;

        // Se estiver em modo padrão, forçar mudança para modo personalizado
        const rCustom = document.getElementById('permModeCustom');
        if (rCustom && !rCustom.checked) {
            this.alternarModoPermissao('custom');
        }

        document.querySelectorAll('.perm-checkbox').forEach(chk => {
            const permId = chk.getAttribute('data-perm-id');
            chk.checked = lista.includes(permId);
            const card = chk.closest('.perm-item-card');
            if (card) card.classList.toggle('active', chk.checked);
        });

        this.atualizarContagemPermissoes();
        this.toast(`Perfil '${preset.toUpperCase()}' aplicado com sucesso!`, 'info');
    },

    marcarTodasPermissoes(marcar) {
        // Se estiver em modo padrão, forçar mudança para modo personalizado
        const rCustom = document.getElementById('permModeCustom');
        if (rCustom && !rCustom.checked) {
            this.alternarModoPermissao('custom');
        }

        document.querySelectorAll('.perm-checkbox').forEach(chk => {
            chk.checked = marcar;
            const card = chk.closest('.perm-item-card');
            if (card) card.classList.toggle('active', marcar);
        });

        this.atualizarContagemPermissoes();
    },

    togglePermissaoItem(permId) {
        const chk = document.getElementById(`chkPerm_${permId}`);
        if (!chk || chk.disabled) return;

        chk.checked = !chk.checked;
        this.onPermCheckboxChange(permId);
    },

    onPermCheckboxChange(permId) {
        const chk = document.getElementById(`chkPerm_${permId}`);
        if (!chk) return;

        const card = chk.closest('.perm-item-card');
        if (card) card.classList.toggle('active', chk.checked);

        // Se o usuário mexer na permissão enquanto estiver no modo padrão, mudar para personalizado
        const rCustom = document.getElementById('permModeCustom');
        if (rCustom && !rCustom.checked) {
            this.alternarModoPermissao('custom');
        }

        this.atualizarContagemPermissoes();
    },

    obterPermissoesSelecionadas() {
        const selecionadas = [];
        document.querySelectorAll('.perm-checkbox:checked').forEach(chk => {
            const id = chk.getAttribute('data-perm-id');
            if (id) selecionadas.push(id);
        });
        return selecionadas;
    },

    atualizarContagemPermissoes() {
        if (!this.permData) return;

        const selecionadas = this.obterPermissoesSelecionadas();
        const totalSelecionadas = selecionadas.length;
        const totalCatalogo = Object.keys(this.permData.catalogo || {}).length;

        const lblTotal = document.getElementById('permTotalSelecionadas');
        if (lblTotal) lblTotal.textContent = totalSelecionadas;

        const badge = document.getElementById('permCountBadge');
        if (badge) {
            badge.innerHTML = `<i class="fa-solid fa-key"></i> ${totalSelecionadas} de ${totalCatalogo} ativas`;
        }

        // Atualizar badges por categoria
        if (this.permData.categorias) {
            for (const [catNome, itens] of Object.entries(this.permData.categorias)) {
                const totalCat = itens.length;
                const ativasCat = itens.filter(it => selecionadas.includes(it.id)).length;
                const badgeCat = document.getElementById(`badgeCat_${catNome}`);
                if (badgeCat) {
                    badgeCat.textContent = `${ativasCat} de ${totalCat}`;
                    badgeCat.classList.toggle('all-active', ativasCat === totalCat);
                }
            }
        }
    },

    filtrarPermissoesUI(termo) {
        const q = (termo || '').trim().toLowerCase();

        document.querySelectorAll('.perm-category-card').forEach(catCard => {
            let visiveisNaCategoria = 0;
            catCard.querySelectorAll('.perm-item-card').forEach(itemCard => {
                const permId = itemCard.getAttribute('data-perm-id') || '';
                const title = itemCard.querySelector('.perm-item-title')?.textContent.toLowerCase() || '';
                const desc = itemCard.querySelector('.perm-item-desc')?.textContent.toLowerCase() || '';

                const matches = !q || permId.toLowerCase().includes(q) || title.includes(q) || desc.includes(q);
                itemCard.style.display = matches ? 'flex' : 'none';
                if (matches) visiveisNaCategoria++;
            });

            catCard.style.display = visiveisNaCategoria > 0 ? 'block' : 'none';
        });
    },

    async salvarPermissoesUsuario() {
        if (!this.permData || !this.permData.usuario) return;

        const id = this.permData.usuario.id;
        const usarPadrao = document.getElementById('permModeDefault')?.checked || false;
        const permissoes = usarPadrao ? [] : this.obterPermissoesSelecionadas();

        const btn = document.getElementById('btnSalvarPermissoes');
        const btnOriginal = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Salvando...';
        btn.disabled = true;

        try {
            const res = await fetch('api/usuarios.php?action=save_permissions', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id,
                    usar_padrao: usarPadrao,
                    permissoes
                })
            });
            const data = await res.json();

            if (!data.success) {
                this.toast(data.error || 'Erro ao salvar permissões.', 'error');
                return;
            }

            this.toast(data.message || 'Permissões atualizadas com sucesso!', 'success');
            this.fecharModais();
            this.carregarUsuarios();

            // Se alterou as permissões do próprio usuário logado, atualizar na sessão
            if (this.currentUser && this.currentUser.id === id) {
                this.currentUser.permissoes = data.permissoes_efetivas || [];
                this.aplicarPermissoesUI();
            }
        } catch (e) {
            console.error('Erro ao salvar permissões:', e);
            this.toast('Erro de conexão ao salvar permissões.', 'error');
        } finally {
            btn.innerHTML = btnOriginal;
            btn.disabled = false;
        }
    },

    limparFiltrosUsuarios() {
        if (document.getElementById('filtroUsuariosBusca')) document.getElementById('filtroUsuariosBusca').value = '';
        if (document.getElementById('filtroUsuariosFuncao')) document.getElementById('filtroUsuariosFuncao').value = '';
        if (document.getElementById('filtroUsuariosStatus')) document.getElementById('filtroUsuariosStatus').value = '';
        this.carregarUsuarios();
    },

    selecionarCorAvatar(hex) {
        document.getElementById('usuarioAvatarCor').value = hex;
        document.querySelectorAll('#usuarioAvatarColorSwatches .color-swatch-opt').forEach(el => {
            el.classList.toggle('active', el.getAttribute('data-color') === hex);
        });
    },

    modalNovoUsuario() {
        document.getElementById('usuarioId').value = '';
        document.getElementById('formUsuario').reset();
        document.getElementById('usuarioId').value = '';
        document.getElementById('usuarioStatus').value = 'ativo';
        document.getElementById('usuarioFuncao').value = 'operador';
        document.getElementById('usuarioPin').placeholder = 'Digite a senha';
        this.selecionarCorAvatar('#3b82f6');
        document.getElementById('modalUsuarioTitulo').innerHTML = '<i class="fa-solid fa-user-plus"></i> Novo Usuário';
        document.getElementById('btnSalvarUsuario').innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Salvar Usuário';
        document.getElementById('modalUsuario').classList.add('active');
        document.getElementById('usuarioNome').focus();
    },

    async modalEditarUsuario(id) {
        try {
            const res = await fetch(`api/usuarios.php?action=get&id=${id}`);
            const data = await res.json();

            if (!data.success || !data.usuario) {
                this.toast(data.error || 'Usuário não encontrado.', 'error');
                return;
            }

            const u = data.usuario;
            document.getElementById('usuarioId').value = u.id;
            document.getElementById('usuarioNome').value = u.nome || '';
            document.getElementById('usuarioEmail').value = u.email || '';
            document.getElementById('usuarioFuncao').value = u.funcao || 'operador';
            document.getElementById('usuarioStatus').value = u.status || 'ativo';
            document.getElementById('usuarioPin').value = '';
            document.getElementById('usuarioPin').placeholder = 'Deixe em branco para manter a senha';
            this.selecionarCorAvatar(u.avatar_cor || '#3b82f6');

            document.getElementById('modalUsuarioTitulo').innerHTML = `<i class="fa-solid fa-user-pen"></i> Editar Usuário: ${u.nome}`;
            document.getElementById('btnSalvarUsuario').innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Atualizar Usuário';
            document.getElementById('modalUsuario').classList.add('active');
            document.getElementById('usuarioNome').focus();
        } catch (e) {
            this.toast('Erro ao obter dados do usuário.', 'error');
        }
    },

    async salvarUsuario(e) {
        e.preventDefault();
        const id = document.getElementById('usuarioId').value;
        const nome = document.getElementById('usuarioNome').value.trim();
        const email = document.getElementById('usuarioEmail').value.trim();
        const funcao = document.getElementById('usuarioFuncao').value;
        const status = document.getElementById('usuarioStatus').value;
        const pin = document.getElementById('usuarioPin').value.trim();
        const avatar_cor = document.getElementById('usuarioAvatarCor').value;

        const action = id ? 'update' : 'create';
        const payload = {
            id,
            nome,
            email,
            funcao,
            status,
            pin,
            avatar_cor
        };

        try {
            const res = await fetch(`api/usuarios.php?action=${action}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();

            if (!data.success) {
                this.toast(data.error || 'Erro ao salvar usuário.', 'error');
                return;
            }

            this.toast(data.message || 'Usuário salvo com sucesso!', 'success');
            this.fecharModais();
            this.carregarUsuarios();

            // Se o usuário editado for o mesmo operador atualmente selecionado, atualizar cabeçalho
            if (id && this.operador === nome) {
                document.getElementById('lblOperadorHeader').textContent = nome;
            }
        } catch (err) {
            this.toast('Erro de conexão ao salvar usuário.', 'error');
        }
    },

    async alternarStatusUsuario(id, statusAtual) {
        try {
            const res = await fetch('api/usuarios.php?action=toggle_status', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            });
            const data = await res.json();

            if (!data.success) {
                this.toast(data.error || 'Erro ao alternar status do usuário.', 'error');
                return;
            }

            this.toast(data.message, 'success');
            this.carregarUsuarios();
        } catch (e) {
            this.toast('Erro ao alterar status.', 'error');
        }
    },

    confirmarExclusaoUsuario(id, nome) {
        document.getElementById('excluirUsuarioId').value = id;
        document.getElementById('lblExcluirUsuarioNome').textContent = nome;
        document.getElementById('modalExcluirUsuario').classList.add('active');
    },

    async executarExclusaoUsuario() {
        const id = document.getElementById('excluirUsuarioId').value;
        if (!id) return;

        try {
            const res = await fetch('api/usuarios.php?action=delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            });
            const data = await res.json();

            if (!data.success) {
                this.toast(data.error || 'Erro ao excluir usuário.', 'error');
                return;
            }

            this.toast(data.message, 'success');
            this.fecharModais();
            this.carregarUsuarios();
        } catch (e) {
            this.toast('Erro ao excluir usuário.', 'error');
        }
    },

    // --- PREFERÊNCIAS ---

    toggleSound() {
        this.soundEnabled = !this.soundEnabled;
        localStorage.setItem('wms_sound', this.soundEnabled ? '1' : '0');
        window.soundEngine.setEnabled(this.soundEnabled);
        this.updateSoundIcon();
        if (this.soundEnabled) window.soundEngine.playSuccess();
    },

    updateSoundIcon() {
        const icon = document.getElementById('iconSound');
        if (icon) {
            icon.className = this.soundEnabled ? 'fa-solid fa-volume-high' : 'fa-solid fa-volume-xmark';
        }
    },

    fecharModais() {
        document.querySelectorAll('.modal-overlay').forEach(m => m.classList.remove('active'));
    },

    exibirLoginScreen() {
        const loginScreen = document.getElementById('login-screen');
        if (loginScreen) {
            loginScreen.classList.add('active');
        }
        const email = document.getElementById('loginEmail');
        const senha = document.getElementById('loginSenha');
        const errMsg = document.getElementById('loginErrorMessage');
        if (email) email.value = '';
        if (senha) senha.value = '';
        if (errMsg) errMsg.textContent = '';
        if (email) email.focus();
    },

    ocultarLoginScreen() {
        const loginScreen = document.getElementById('login-screen');
        if (loginScreen) {
            loginScreen.classList.remove('active');
        }
    },

    async efetuarLogin(e) {
        if (e) e.preventDefault();

        const email = document.getElementById('loginEmail').value.trim();
        const senha = document.getElementById('loginSenha').value.trim();
        const errMsg = document.getElementById('loginErrorMessage');

        if (!email || !senha) {
            if (errMsg) errMsg.textContent = 'E-mail e senha são obrigatórios.';
            return;
        }

        if (errMsg) errMsg.textContent = '';

        try {
            const res = await fetch('api/usuarios.php?action=login', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ email, senha })
            });
            const data = await res.json();

            if (data.success && data.user) {
                this.currentUser = data.user;
                this.operador = data.user.nome;
                localStorage.setItem('wms_operador', data.user.nome);
                this.atualizarOperadorHeader();
                this.aplicarPermissoesUI();
                this.ocultarLoginScreen();
                this.carregarDadosIniciais();
                this.toast('Login realizado com sucesso!', 'success');
            } else {
                if (errMsg) errMsg.textContent = data.error || 'E-mail ou senha incorretos.';
                window.soundEngine.playError();
            }
        } catch (err) {
            console.error('Erro de login:', err);
            if (errMsg) errMsg.textContent = 'Erro ao se conectar ao servidor.';
            window.soundEngine.playError();
        }
    },

    async logout() {
        if (!confirm('Deseja realmente sair do sistema?')) return;

        try {
            const res = await fetch('api/usuarios.php?action=logout', {
                method: 'POST'
            });
            const data = await res.json();
            if (data.success) {
                this.currentUser = null;
                localStorage.removeItem('wms_operador');
                this.exibirLoginScreen();
                this.toast('Você saiu do sistema.', 'info');
            }
        } catch (e) {
            console.error('Erro ao realizar logout:', e);
            this.currentUser = null;
            localStorage.removeItem('wms_operador');
            this.exibirLoginScreen();
        }
    },

    // =========================================================================
    // MÓDULO 7: ENDEREÇAMENTO DE ARMAZÉM & SLOTTING (LOCAIS)
    // =========================================================================
    locaisCache: [],

    async carregarLocais() {
        const busca = document.getElementById('filtroLocaisBusca')?.value || '';
        const armazem = document.getElementById('filtroLocaisArmazem')?.value || '';
        const rua = document.getElementById('filtroLocaisRua')?.value || '';
        const tipo = document.getElementById('filtroLocaisTipo')?.value || '';

        const tbody = document.getElementById('locaisTableBody');
        if (tbody) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="7" class="text-center py-4 text-muted">
                        <i class="fa-solid fa-spinner fa-spin fa-2x mb-2"></i><br>Carregando posições de armazém...
                    </td>
                </tr>
            `;
        }

        try {
            const params = new URLSearchParams({
                busca, armazem, rua, tipo, limite: 200
            });
            const res = await fetch(`api/locais.php?action=listar&${params.toString()}`);
            const data = await res.json();

            if (!data.success) {
                if (tbody) tbody.innerHTML = `<tr><td colspan="7" class="text-center py-4 text-danger">${data.error || 'Erro ao carregar locais.'}</td></tr>`;
                return;
            }

            this.locaisCache = data.locais || [];

            // Preencher selects de filtros se vazios
            const selArm = document.getElementById('filtroLocaisArmazem');
            if (selArm && selArm.options.length <= 1 && data.filtro_armazens) {
                data.filtro_armazens.forEach(a => {
                    const opt = document.createElement('option');
                    opt.value = a;
                    opt.textContent = a;
                    selArm.appendChild(opt);
                });
            }

            const selRua = document.getElementById('filtroLocaisRua');
            if (selRua && selRua.options.length <= 1 && data.filtro_ruas) {
                data.filtro_ruas.forEach(r => {
                    const opt = document.createElement('option');
                    opt.value = r;
                    opt.textContent = `Rua ${r}`;
                    selRua.appendChild(opt);
                });
            }

            // Atualizar KPIs
            let totalLocais = this.locaisCache.length;
            let totalPicking = 0;
            let totalPulmao = 0;
            let totalSkus = 0;

            this.locaisCache.forEach(l => {
                if (l.tipo === 'picking') totalPicking++;
                if (l.tipo === 'pulmao') totalPulmao++;
                totalSkus += parseInt(l.total_skus || 0);
            });

            const setVal = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
            setVal('kpiTotalLocais', totalLocais);
            setVal('kpiLocaisPicking', totalPicking);
            setVal('kpiLocaisPulmao', totalPulmao);
            setVal('kpiSkusAlocados', totalSkus);

            if (!this.locaisCache || this.locaisCache.length === 0) {
                if (tbody) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">
                                <i class="fa-solid fa-warehouse fa-2x mb-2 opacity-50"></i><br>Nenhuma posição de armazém encontrada.
                            </td>
                        </tr>
                    `;
                }
                return;
            }

            let html = '';
            this.locaisCache.forEach(l => {
                const tipoBadgeClass = {
                    'picking': 'loc-badge-picking',
                    'pulmao': 'loc-badge-pulmao',
                    'quarentena': 'loc-badge-quarentena',
                    'avarias': 'loc-badge-avarias'
                }[l.tipo] || 'badge-pending';

                const tipoLabel = {
                    'picking': 'Picking',
                    'pulmao': 'Pulmão',
                    'quarentena': 'Quarentena',
                    'avarias': 'Avarias'
                }[l.tipo] || l.tipo;

                const statusHtml = (l.status === 'ativo') 
                    ? '<span class="badge bg-success-light text-success">Ativo</span>' 
                    : '<span class="badge bg-danger-light text-danger">Inativo</span>';

                html += `
                    <tr>
                        <td>
                            <span class="loc-badge ${tipoBadgeClass}">
                                <i class="fa-solid fa-location-dot"></i> ${l.codigo}
                            </span>
                        </td>
                        <td>
                            <strong>${l.armazem || 'Principal'}</strong><br>
                            <span class="text-muted fs-xs">Rua ${l.rua} • Est ${l.estante} • Nv ${l.nivel} • Vão ${l.posicao}</span>
                        </td>
                        <td>
                            <span class="badge ${tipoBadgeClass}">${tipoLabel}</span>
                        </td>
                        <td>
                            <span class="fw-bold">${l.total_skus || 0}</span> SKU(s)
                        </td>
                        <td>
                            <strong>${parseFloat(l.total_unidades || 0)}</strong> un
                            ${parseFloat(l.capacidade_maxima || 0) > 0 ? `<span class="text-muted fs-xs">/ ${l.capacidade_maxima}</span>` : ''}
                        </td>
                        <td>${statusHtml}</td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-alt-primary" onclick="App.modalAtribuirProdutoLocal(${l.id}, '${l.codigo}')" title="Vincular / Ajustar SKU">
                                    <i class="fa-solid fa-tags"></i>
                                </button>
                                <button class="btn btn-alt-secondary" onclick="App.imprimirEtiquetaUnicaLocal(${l.id}, '${l.codigo}', '${l.armazem}', '${l.rua}')" title="Imprimir Etiqueta">
                                    <i class="fa-solid fa-print"></i>
                                </button>
                                <button class="btn btn-alt-secondary" onclick="App.modalEditarLocal(${l.id})" title="Editar Posição">
                                    <i class="fa-solid fa-pencil"></i>
                                </button>
                                <button class="btn btn-alt-danger" onclick="App.excluirLocal(${l.id}, '${l.codigo}')" title="Excluir Posição">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            });

            if (tbody) tbody.innerHTML = html;
        } catch (e) {
            console.error('Erro ao carregar locais:', e);
            if (tbody) tbody.innerHTML = `<tr><td colspan="7" class="text-center py-4 text-danger">Erro de conexão ao carregar posições.</td></tr>`;
        }
    },

    limparFiltrosLocais() {
        const b = document.getElementById('filtroLocaisBusca'); if (b) b.value = '';
        const a = document.getElementById('filtroLocaisArmazem'); if (a) a.value = '';
        const r = document.getElementById('filtroLocaisRua'); if (r) r.value = '';
        const t = document.getElementById('filtroLocaisTipo'); if (t) t.value = '';
        this.carregarLocais();
    },

    modalNovoLocal(id = null) {
        document.getElementById('formLocal')?.reset();
        document.getElementById('localId').value = '';
        document.getElementById('modalLocalTitulo').innerHTML = '<i class="fa-solid fa-warehouse text-primary"></i> Nova Posição de Armazém';
        document.getElementById('modalLocal').classList.add('active');
        document.getElementById('localRua')?.focus();
    },

    modalEditarLocal(id) {
        const local = this.locaisCache.find(l => l.id == id);
        if (!local) return;

        document.getElementById('localId').value = local.id;
        document.getElementById('localArmazem').value = local.armazem || 'Principal';
        document.getElementById('localTipo').value = local.tipo || 'picking';
        document.getElementById('localRua').value = local.rua || '';
        document.getElementById('localEstante').value = local.estante || '';
        document.getElementById('localNivel').value = local.nivel || '';
        document.getElementById('localPosicao').value = local.posicao || '';
        document.getElementById('localCodigo').value = local.codigo || '';
        document.getElementById('localCapacidade').value = local.capacidade_maxima || '';
        document.getElementById('localPesoMax').value = local.peso_max_kg || '';
        document.getElementById('localObservacoes').value = local.observacoes || '';

        document.getElementById('modalLocalTitulo').innerHTML = `<i class="fa-solid fa-pencil text-primary"></i> Editar Posição: ${local.codigo}`;
        document.getElementById('modalLocal').classList.add('active');
    },

    atualizarCodigoPreview() {
        const rua = (document.getElementById('localRua')?.value || '').trim().toUpperCase();
        const est = (document.getElementById('localEstante')?.value || '').trim().padStart(2, '0');
        const niv = (document.getElementById('localNivel')?.value || '').trim().padStart(2, '0');
        const pos = (document.getElementById('localPosicao')?.value || '').trim().padStart(2, '0');

        if (rua && est && niv && pos) {
            const codInput = document.getElementById('localCodigo');
            if (codInput) codInput.value = `${rua}-${est}-${niv}-${pos}`;
        }
    },

    async salvarLocal(e) {
        if (e) e.preventDefault();

        const id = document.getElementById('localId').value;
        const armazem = document.getElementById('localArmazem').value.trim();
        const tipo = document.getElementById('localTipo').value;
        const rua = document.getElementById('localRua').value.trim();
        const estante = document.getElementById('localEstante').value.trim();
        const nivel = document.getElementById('localNivel').value.trim();
        const posicao = document.getElementById('localPosicao').value.trim();
        const codigo = document.getElementById('localCodigo').value.trim();
        const capacidade_maxima = document.getElementById('localCapacidade').value;
        const peso_max_kg = document.getElementById('localPesoMax').value;
        const observacoes = document.getElementById('localObservacoes').value.trim();

        try {
            const res = await fetch('api/locais.php?action=salvar', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id, armazem, tipo, rua, estante, nivel, posicao, codigo,
                    capacidade_maxima, peso_max_kg, observacoes
                })
            });
            const data = await res.json();

            if (data.success) {
                this.toast(data.message || 'Posição salva com sucesso!', 'success');
                this.fecharModais();
                this.carregarLocais();
            } else {
                this.toast(data.error || 'Erro ao salvar posição.', 'error');
            }
        } catch (err) {
            console.error('Erro ao salvar local:', err);
            this.toast('Erro de conexão ao salvar posição.', 'error');
        }
    },

    modalGerarLocaisLote() {
        document.getElementById('formGerarLocaisLote')?.reset();
        document.getElementById('modalGerarLocaisLote').classList.add('active');
    },

    async executarGerarLocaisLote(e) {
        if (e) e.preventDefault();

        const armazem = document.getElementById('loteArmazem').value.trim();
        const tipo = document.getElementById('loteTipo').value;
        const rua_inicio = document.getElementById('loteRuaInicio').value.trim();
        const rua_fim = document.getElementById('loteRuaFim').value.trim();
        const estante_fim = document.getElementById('loteEstanteFim').value;
        const nivel_fim = document.getElementById('loteNivelFim').value;
        const posicao_fim = document.getElementById('lotePosicaoFim').value;

        try {
            const res = await fetch('api/locais.php?action=gerar_lote', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    armazem, tipo, rua_inicio, rua_fim,
                    estante_inicio: 1, estante_fim,
                    nivel_inicio: 1, nivel_fim,
                    posicao_inicio: 1, posicao_fim
                })
            });
            const data = await res.json();

            if (data.success) {
                this.toast(data.message || 'Posições geradas com sucesso!', 'success');
                this.fecharModais();
                this.carregarLocais();
            } else {
                this.toast(data.error || 'Erro ao gerar posições.', 'error');
            }
        } catch (err) {
            console.error('Erro ao gerar posições em lote:', err);
            this.toast('Erro ao gerar posições em lote.', 'error');
        }
    },

    modalAtribuirProdutoLocal(localId, localCodigo) {
        document.getElementById('formAtribuirProduto')?.reset();
        document.getElementById('atribuirLocalId').value = localId;
        document.getElementById('lblAtribuirLocalCodigo').textContent = localCodigo;
        document.getElementById('modalAtribuirProdutoLocal').classList.add('active');
        document.getElementById('atribuirSku')?.focus();
    },

    async salvarAtribuicaoProdutoLocal(e) {
        if (e) e.preventDefault();

        const local_id = document.getElementById('atribuirLocalId').value;
        const codigo_produto = document.getElementById('atribuirSku').value.trim();
        const nome_produto = document.getElementById('atribuirNome').value.trim();
        const tipo = document.getElementById('atribuirTipo').value;
        const quantidade = document.getElementById('atribuirQuantidade').value;

        try {
            const res = await fetch('api/locais.php?action=atribuir_produto', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ local_id, codigo_produto, nome_produto, tipo, quantidade })
            });
            const data = await res.json();

            if (data.success) {
                this.toast(data.message || 'Produto vinculado à posição com sucesso!', 'success');
                this.fecharModais();
                this.carregarLocais();
            } else {
                this.toast(data.error || 'Erro ao vincular produto.', 'error');
            }
        } catch (err) {
            console.error('Erro ao vincular produto:', err);
            this.toast('Erro ao vincular produto ao local.', 'error');
        }
    },

    async excluirLocal(id, codigo) {
        if (!confirm(`Deseja realmente excluir a posição ${codigo}?`)) return;

        try {
            const res = await fetch('api/locais.php?action=excluir', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            });
            const data = await res.json();

            if (data.success) {
                this.toast(data.message || 'Posição excluída com sucesso.', 'success');
                this.carregarLocais();
            } else {
                this.toast(data.error || 'Não foi possível excluir a posição.', 'error');
            }
        } catch (err) {
            console.error('Erro ao excluir local:', err);
            this.toast('Erro ao excluir posição.', 'error');
        }
    },

    modalImprimirEtiquetasLocais() {
        const selArm = document.getElementById('etqArmazem');
        if (selArm) {
            selArm.innerHTML = '<option value="">Todos os Armazéns</option>';
            const armazens = [...new Set(this.locaisCache.map(l => l.armazem).filter(Boolean))];
            armazens.forEach(a => {
                const opt = document.createElement('option');
                opt.value = a; opt.textContent = a;
                selArm.appendChild(opt);
            });
        }

        const selRua = document.getElementById('etqRua');
        if (selRua) {
            selRua.innerHTML = '<option value="">Todas as Ruas</option>';
            const ruas = [...new Set(this.locaisCache.map(l => l.rua).filter(Boolean))];
            ruas.forEach(r => {
                const opt = document.createElement('option');
                opt.value = r; opt.textContent = `Rua ${r}`;
                selRua.appendChild(opt);
            });
        }

        this.carregarPreviaEtiquetas();
        document.getElementById('modalEtiquetasLocais').classList.add('active');
    },

    carregarPreviaEtiquetas() {
        const armazem = document.getElementById('etqArmazem')?.value || '';
        const rua = document.getElementById('etqRua')?.value || '';

        const filtrados = this.locaisCache.filter(l => {
            if (armazem && l.armazem !== armazem) return false;
            if (rua && l.rua !== rua) return false;
            return true;
        });

        const container = document.getElementById('etiquetasPreviaContainer');
        if (container) {
            if (filtrados.length === 0) {
                container.innerHTML = '<span class="text-muted fs-xs">Nenhuma etiqueta encontrada para os filtros.</span>';
                return;
            }

            let html = `<p class="fs-xs fw-bold text-muted mb-2">${filtrados.length} etiqueta(s) pronta(s) para impressão:</p><div class="d-flex flex-wrap justify-content-center">`;
            filtrados.slice(0, 12).forEach(l => {
                html += `
                    <div class="shelf-label-card" style="width: 140px; padding: 6px; margin: 4px;">
                        <div class="shelf-label-code" style="font-size: 0.9rem;">${l.codigo}</div>
                        <div class="shelf-label-meta" style="font-size: 0.6rem;">${l.armazem || 'Principal'} • Rua ${l.rua}</div>
                        <div class="shelf-label-barcode" style="font-size: 0.7rem;">*${l.codigo}*</div>
                    </div>
                `;
            });
            if (filtrados.length > 12) {
                html += `<div class="w-100 text-muted fs-xs mt-2">+ mais ${filtrados.length - 12} posições na impressão.</div>`;
            }
            html += '</div>';
            container.innerHTML = html;
        }
    },

    imprimirEtiquetasLocais() {
        const armazem = document.getElementById('etqArmazem')?.value || '';
        const rua = document.getElementById('etqRua')?.value || '';

        const filtrados = this.locaisCache.filter(l => {
            if (armazem && l.armazem !== armazem) return false;
            if (rua && l.rua !== rua) return false;
            return true;
        });

        if (filtrados.length === 0) {
            this.toast('Nenhum local para imprimir.', 'warning');
            return;
        }

        const printArea = document.getElementById('printArea');
        if (!printArea) return;

        let html = '<div style="display:flex; flex-wrap:wrap; justify-content:flex-start; padding: 20px;">';
        filtrados.forEach(l => {
            html += `
                <div class="shelf-label-card" style="width: 220px; padding: 10px; margin: 8px; border: 2px solid #000; text-align: center; font-family: sans-serif;">
                    <div style="font-size: 10px; font-weight: bold; text-transform: uppercase; color: #555;">WMS PRIME • ${l.armazem || 'DEPÓSITO'}</div>
                    <div style="font-size: 22px; font-weight: 900; font-family: monospace; letter-spacing: 1px; margin: 6px 0;">${l.codigo}</div>
                    <div style="font-size: 11px; font-weight: 600;">Rua ${l.rua} | Estante ${l.estante} | Nível ${l.nivel} | Vão ${l.posicao}</div>
                    <div style="font-family: monospace; font-size: 14px; background: #eee; padding: 4px; margin-top: 6px; letter-spacing: 2px; font-weight: bold;">*${l.codigo}*</div>
                </div>
            `;
        });
        html += '</div>';

        printArea.innerHTML = html;
        window.print();
    },

    imprimirEtiquetaUnicaLocal(id, codigo, armazem, rua) {
        const printArea = document.getElementById('printArea');
        if (!printArea) return;

        printArea.innerHTML = `
            <div style="padding: 30px; display: flex; justify-content: center;">
                <div class="shelf-label-card" style="width: 320px; padding: 18px; border: 3px solid #000; text-align: center; font-family: sans-serif;">
                    <div style="font-size: 12px; font-weight: bold; text-transform: uppercase; color: #555;">WMS PRIME • ${armazem || 'DEPÓSITO'}</div>
                    <div style="font-size: 32px; font-weight: 900; font-family: monospace; letter-spacing: 2px; margin: 10px 0;">${codigo}</div>
                    <div style="font-size: 14px; font-weight: bold;">RUA ${rua}</div>
                    <div style="font-family: monospace; font-size: 18px; background: #eee; padding: 6px; margin-top: 10px; letter-spacing: 3px; font-weight: bold;">*${codigo}*</div>
                </div>
            </div>
        `;
        window.print();
    },

    // =========================================================================
    // MÓDULO 8: RECEBIMENTO & INBOUND (ENTRADA DE MERCADORIAS)
    // =========================================================================
    recebimentosCache: [],
    recebimentoAtivo: null,
    scannerRec: null,

    async carregarRecebimentos() {
        const busca = document.getElementById('filtroRecBusca')?.value || '';
        const status = document.getElementById('filtroRecStatus')?.value || '';

        const tbody = document.getElementById('recebimentosTableBody');
        if (tbody) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="7" class="text-center py-4 text-muted">
                        <i class="fa-solid fa-spinner fa-spin fa-2x mb-2"></i><br>Carregando notas e recebimentos...
                    </td>
                </tr>
            `;
        }

        try {
            const params = new URLSearchParams({ busca, status, limite: 100 });
            const res = await fetch(`api/recebimento.php?action=listar&${params.toString()}`);
            const data = await res.json();

            if (!data.success) {
                if (tbody) tbody.innerHTML = `<tr><td colspan="7" class="text-center py-4 text-danger">${data.error || 'Erro ao carregar recebimentos.'}</td></tr>`;
                return;
            }

            this.recebimentosCache = data.recebimentos || [];

            // Atualizar KPIs
            if (data.stats) {
                const setVal = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
                setVal('kpiRecTotal', data.stats.total || 0);
                setVal('kpiRecPendentes', data.stats.pendente || 0);
                setVal('kpiRecEmConferencia', data.stats.em_conferencia || 0);
                setVal('kpiRecConferidos', (data.stats.conferido || 0) + (data.stats.armazenado || 0));
            }

            if (!this.recebimentosCache || this.recebimentosCache.length === 0) {
                if (tbody) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">
                                <i class="fa-solid fa-truck-ramp-box fa-2x mb-2 opacity-50"></i><br>Nenhuma ordem de recebimento encontrada.
                            </td>
                        </tr>
                    `;
                }
                return;
            }

            let html = '';
            this.recebimentosCache.forEach(r => {
                const statusBadge = {
                    'pendente': '<span class="badge badge-pending"><i class="fa-regular fa-clock me-1"></i> Pendente</span>',
                    'em_conferencia': '<span class="badge badge-progress"><i class="fa-solid fa-spinner fa-spin me-1"></i> Em Conferência</span>',
                    'conferido': '<span class="badge badge-success"><i class="fa-solid fa-check me-1"></i> Conferido</span>',
                    'divergencia': '<span class="badge bg-warning-light text-warning"><i class="fa-solid fa-triangle-exclamation me-1"></i> Divergência</span>',
                    'armazenado': '<span class="badge bg-primary-light text-primary"><i class="fa-solid fa-boxes-packing me-1"></i> Guardado (Putaway)</span>'
                }[r.status] || `<span class="badge badge-pending">${r.status}</span>`;

                const dataFormatada = r.data_emissao ? new Date(r.data_emissao).toLocaleDateString('pt-BR') : '---';

                html += `
                    <tr>
                        <td>
                            <strong class="font-mono">NF #${r.numero_documento || 'S/N'}</strong>
                            ${r.chave_nfe ? `<br><span class="text-muted fs-xs font-mono" title="${r.chave_nfe}">${r.chave_nfe.substring(0, 18)}...</span>` : ''}
                        </td>
                        <td>
                            <strong>${r.fornecedor_nome || 'Fornecedor'}</strong><br>
                            <span class="text-muted fs-xs">${r.fornecedor_cnpj || ''} ${r.fornecedor_uf ? `(${r.fornecedor_uf})` : ''}</span>
                        </td>
                        <td>
                            <span class="fs-xs">${dataFormatada}</span>
                        </td>
                        <td>
                            <div class="d-flex justify-content-between align-items-center mb-1 fs-xs">
                                <span>${r.quantidade_total_conferida || 0} / ${r.quantidade_total_esperada || 0} un</span>
                                <span class="fw-bold">${r.porcentagem || 0}%</span>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar bg-success" style="width: ${r.porcentagem || 0}%;"></div>
                            </div>
                        </td>
                        <td>${statusBadge}</td>
                        <td>
                            <span class="fs-xs text-muted"><i class="fa-solid fa-user me-1"></i> ${r.operador || 'Pendente'}</span>
                        </td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-primary" onclick="App.iniciarConferenciaRecebimento(${r.id})" title="Conferir Entrada / Bipar">
                                    <i class="fa-solid fa-barcode me-1"></i> Conferir
                                </button>
                                ${r.status === 'conferido' || r.status === 'armazenado' ? `
                                    <button class="btn btn-alt-info" onclick="App.abrirPutawayRecebimento(${r.id})" title="Guia de Guarda / Putaway">
                                        <i class="fa-solid fa-boxes-packing"></i>
                                    </button>
                                ` : ''}
                            </div>
                        </td>
                    </tr>
                `;
            });

            if (tbody) tbody.innerHTML = html;
        } catch (e) {
            console.error('Erro ao carregar recebimentos:', e);
            if (tbody) tbody.innerHTML = `<tr><td colspan="7" class="text-center py-4 text-danger">Erro ao carregar recebimentos.</td></tr>`;
        }
    },

    limparFiltrosRecebimento() {
        const b = document.getElementById('filtroRecBusca'); if (b) b.value = '';
        const s = document.getElementById('filtroRecStatus'); if (s) s.value = '';
        this.carregarRecebimentos();
    },

    modalUploadXmlNfe() {
        document.getElementById('formUploadXmlNfe')?.reset();
        document.getElementById('lblXmlNomeArquivo').textContent = 'Nenhum arquivo selecionado';
        document.getElementById('modalUploadXmlNfe').classList.add('active');
    },

    onArquivoXmlSelecionado(input) {
        if (input.files && input.files[0]) {
            document.getElementById('lblXmlNomeArquivo').textContent = input.files[0].name;
        }
    },

    async executarUploadXmlNfe(e) {
        if (e) e.preventDefault();

        const fileInput = document.getElementById('fileXmlNfe');
        const txtXml = document.getElementById('txtXmlNfe')?.value.trim();

        const formData = new FormData();
        if (fileInput && fileInput.files && fileInput.files[0]) {
            formData.append('xml_file', fileInput.files[0]);
        } else if (txtXml) {
            formData.append('xml_string', txtXml);
        } else {
            this.toast('Selecione um arquivo XML ou cole o código XML da NF-e.', 'warning');
            return;
        }

        const btn = document.getElementById('btnProcessarXmlNfe');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Processando XML...';
        }

        try {
            const res = await fetch('api/recebimento.php?action=upload_xml', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            if (data.success) {
                this.toast(data.message || 'NF-e importada com sucesso!', 'success');
                this.fecharModais();
                this.carregarRecebimentos();
                if (data.recebimento_id) {
                    this.iniciarConferenciaRecebimento(data.recebimento_id);
                }
            } else {
                this.toast(data.error || 'Erro ao importar XML.', 'error');
            }
        } catch (err) {
            console.error('Erro no upload do XML:', err);
            this.toast('Erro de conexão ao processar XML da NF-e.', 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-bolt me-1"></i> Processar & Importar Itens';
            }
        }
    },

    modalNovoRecebimentoManual() {
        document.getElementById('formRecebimentoManual')?.reset();
        const tbody = document.getElementById('tabelaItensManualBody');
        if (tbody) {
            tbody.innerHTML = '';
            this.adicionarLinhaItemManual();
        }
        document.getElementById('modalRecebimentoManual').classList.add('active');
        document.getElementById('manNumDoc')?.focus();
    },

    adicionarLinhaItemManual() {
        const tbody = document.getElementById('tabelaItensManualBody');
        if (!tbody) return;

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><input type="text" class="form-control form-control-sm font-mono item-cod" placeholder="SKU (ex: RP001)" required></td>
            <td><input type="text" class="form-control form-control-sm font-mono item-ean" placeholder="EAN-13"></td>
            <td><input type="text" class="form-control form-control-sm item-desc" placeholder="Nome do produto"></td>
            <td><input type="number" class="form-control form-control-sm item-qtd" value="1" min="1" step="1" required></td>
            <td class="text-center">
                <button type="button" class="btn btn-xs btn-alt-danger" onclick="this.closest('tr').remove()" title="Remover"><i class="fa-solid fa-trash"></i></button>
            </td>
        `;
        tbody.appendChild(tr);
    },

    async salvarRecebimentoManual(e) {
        if (e) e.preventDefault();

        const numDoc = document.getElementById('manNumDoc').value.trim();
        const fornNome = document.getElementById('manFornNome').value.trim();

        const rows = document.querySelectorAll('#tabelaItensManualBody tr');
        const itens = [];

        rows.forEach(r => {
            const cod = r.querySelector('.item-cod')?.value.trim();
            const ean = r.querySelector('.item-ean')?.value.trim();
            const desc = r.querySelector('.item-desc')?.value.trim();
            const qtd = parseFloat(r.querySelector('.item-qtd')?.value || 0);

            if (cod && qtd > 0) {
                itens.push({ codigo_produto: cod, ean, descricao: desc || cod, quantidade: qtd });
            }
        });

        if (itens.length === 0) {
            this.toast('Adicione pelo menos um item válido na ordem.', 'warning');
            return;
        }

        try {
            const res = await fetch('api/recebimento.php?action=criar_manual', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ numero_documento: numDoc, fornecedor_nome: fornNome, itens })
            });
            const data = await res.json();

            if (data.success) {
                this.toast(data.message || 'Recebimento criado com sucesso!', 'success');
                this.fecharModais();
                this.carregarRecebimentos();
                if (data.recebimento_id) {
                    this.iniciarConferenciaRecebimento(data.recebimento_id);
                }
            } else {
                this.toast(data.error || 'Erro ao criar recebimento.', 'error');
            }
        } catch (err) {
            console.error('Erro ao salvar recebimento manual:', err);
            this.toast('Erro ao criar recebimento.', 'error');
        }
    },

    async iniciarConferenciaRecebimento(recId) {
        try {
            const res = await fetch('api/recebimento.php?action=iniciar', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: recId, operador: this.operador })
            });
            const data = await res.json();

            if (data.success) {
                this.recebimentoAtivo = data;
                this.renderizarConferenciaRecebimento(data);
                document.getElementById('recebimentoListaContainer').style.display = 'none';
                document.getElementById('recebimentoAtivoContainer').style.display = 'block';
                document.getElementById('inputManualBarcodeRec')?.focus();
            } else {
                this.toast(data.error || 'Erro ao abrir recebimento.', 'error');
            }
        } catch (err) {
            console.error('Erro ao iniciar recebimento:', err);
            this.toast('Erro ao iniciar conferência de entrada.', 'error');
        }
    },

    fecharConferenciaRecebimento() {
        this.recebimentoAtivo = null;
        this.stopCamera();
        document.getElementById('recebimentoAtivoContainer').style.display = 'none';
        document.getElementById('recebimentoListaContainer').style.display = 'block';
        this.carregarRecebimentos();
    },

    renderizarConferenciaRecebimento(data) {
        const rec = data.recebimento;
        const itens = data.itens || [];
        const logs = data.logs || [];

        document.getElementById('lblRecNumDoc').textContent = rec.numero_documento || '---';
        document.getElementById('lblRecFornecedor').textContent = `${rec.fornecedor_nome || 'Fornecedor'} (${rec.fornecedor_cnpj || ''})`;
        document.getElementById('lblRecQtdConferida').textContent = rec.quantidade_total_conferida || 0;
        document.getElementById('lblRecQtdEsperada').textContent = rec.quantidade_total_esperada || 0;
        document.getElementById('lblRecPorcentagem').textContent = `${rec.porcentagem || 0}%`;

        const fill = document.getElementById('recProgressBarFill');
        if (fill) fill.style.width = `${rec.porcentagem || 0}%`;

        const badge = document.getElementById('lblRecStatusBadge');
        if (badge) {
            badge.textContent = (rec.status === 'armazenado') ? 'Guardado (Putaway)' : ((rec.status === 'conferido') ? 'Conferido' : 'Em Conferência');
            badge.className = `badge ${rec.status === 'conferido' || rec.status === 'armazenado' ? 'badge-success' : 'badge-progress'} fs-xs`;
        }

        // Renderizar Itens da Entrada
        const container = document.getElementById('itensRecebimentoList');
        if (container) {
            let html = '';
            itens.forEach(it => {
                const qtdEsp = parseFloat(it.quantidade_esperada);
                const qtdConf = parseFloat(it.quantidade_conferida);
                const isDone = qtdConf >= qtdEsp;

                let cardClass = 'status-pendente';
                let statusBadge = '<span class="badge badge-pending"><i class="fa-regular fa-clock"></i> Pendente</span>';

                if (isDone) {
                    cardClass = (qtdConf > qtdEsp) ? 'status-divergencia' : 'status-conferido';
                    statusBadge = (qtdConf > qtdEsp)
                        ? '<span class="badge bg-warning-light text-warning"><i class="fa-solid fa-triangle-exclamation"></i> Excedido</span>'
                        : '<span class="badge badge-success"><i class="fa-solid fa-check"></i> Conferido</span>';
                } else if (qtdConf > 0) {
                    cardClass = 'status-parcial';
                    statusBadge = `<span class="badge badge-progress">${qtdConf}/${qtdEsp}</span>`;
                }

                html += `
                    <div class="item-picking-card ${cardClass}" id="rec-item-${it.codigo_produto}">
                        <div class="item-details">
                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; margin-bottom: 0.35rem;">
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <h4 class="mb-0">${it.descricao}</h4>
                                    ${it.local_sugerido_codigo ? `<span class="loc-badge loc-badge-pulmao" title="Guarda Sugerida"><i class="fa-solid fa-location-dot"></i> Guarda: ${it.local_sugerido_codigo}</span>` : '<span class="badge bg-body-secondary text-muted border fs-xs">Sem Local Sugerido</span>'}
                                </div>
                                ${statusBadge}
                            </div>
                            <div class="item-meta">
                                <span>SKU: <strong class="font-mono text-primary">${it.codigo_produto}</strong></span>
                                <span>EAN: <strong class="font-mono text-success">${it.ean || '---'}</strong></span>
                                <span>Un: <strong>${it.unidade || 'UN'}</strong></span>
                                ${it.lote ? `<span>Lote: <strong>${it.lote}</strong></span>` : ''}
                                ${it.data_validade ? `<span>Val: <strong>${it.data_validade}</strong></span>` : ''}
                            </div>
                        </div>
                        <div class="item-count-box">
                            <span class="conferido-num" style="${isDone ? 'color: var(--color-success);' : ''}">${qtdConf}</span>
                            <span class="separator">/</span>
                            <span class="total-num">${qtdEsp}</span>
                        </div>
                    </div>
                `;
            });
            container.innerHTML = html;
        }

        // Renderizar Logs
        const logContainer = document.getElementById('listaLogsRecebimentoContainer');
        if (logContainer) {
            if (logs.length === 0) {
                logContainer.innerHTML = '<span class="text-muted fs-xs text-center d-block py-2">Nenhuma bipagem registrada ainda.</span>';
            } else {
                let logHtml = '<ul class="list-unstyled fs-xs mb-0">';
                logs.forEach(l => {
                    const icon = (l.resultado === 'sucesso') ? '<i class="fa-solid fa-check text-success me-1"></i>' : '<i class="fa-solid fa-xmark text-danger me-1"></i>';
                    logHtml += `<li class="py-1 border-bottom d-flex justify-content-between"><span>${icon}<strong>${l.codigo_bipado}</strong></span><span class="text-muted">${l.timestamp?.substring(11, 19) || ''}</span></li>`;
                });
                logHtml += '</ul>';
                logContainer.innerHTML = logHtml;
            }
        }
    },

    async biparRecebimento(codigoBipado, tipoLeitura = 'camera') {
        if (!this.recebimentoAtivo || !this.recebimentoAtivo.recebimento) return;

        const recId = this.recebimentoAtivo.recebimento.id;
        const banner = document.getElementById('scanStatusBannerRec');

        try {
            const res = await fetch('api/recebimento.php?action=bipar', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    recebimento_id: recId,
                    codigo_bipado: codigoBipado,
                    tipo_leitura: tipoLeitura,
                    operador: this.operador
                })
            });
            const data = await res.json();

            if (!data.success) {
                window.soundEngine.playError();
                this.toast(data.error || 'Código incorreto ou produto não pertence à nota.', 'error');
                if (banner) {
                    banner.style.display = 'block';
                    banner.className = 'alert alert-danger fs-xs py-2 px-3 mb-2 text-center';
                    banner.innerHTML = `<i class="fa-solid fa-xmark"></i> ${data.error || 'Código não pertence à nota!'}`;
                }
                return;
            }

            window.soundEngine.playSuccess();
            this.recebimentoAtivo = data;
            this.renderizarConferenciaRecebimento(data);

            if (banner) {
                banner.style.display = 'block';
                banner.className = 'alert alert-success fs-xs py-2 px-3 mb-2 text-center';
                banner.innerHTML = `<i class="fa-solid fa-check"></i> ${data.message || 'Item conferido!'}`;
            }

            // Animar o card do produto
            const card = document.getElementById(`rec-item-${codigoBipado}`);
            if (card) {
                card.classList.add('just-scanned');
                card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                setTimeout(() => card.classList.remove('just-scanned'), 700);
            }
        } catch (err) {
            console.error('Erro na bipagem de recebimento:', err);
            this.toast('Erro ao registrar bipagem de entrada.', 'error');
        }
    },

    biparRecebimentoManual() {
        const inp = document.getElementById('inputManualBarcodeRec');
        const code = inp?.value.trim();
        if (code) {
            this.biparRecebimento(code, 'manual');
            inp.value = '';
        }
    },

    async finalizarConferenciaRecebimento() {
        if (!this.recebimentoAtivo || !this.recebimentoAtivo.recebimento) return;
        const recId = this.recebimentoAtivo.recebimento.id;

        try {
            const res = await fetch('api/recebimento.php?action=finalizar', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: recId })
            });
            const data = await res.json();

            if (data.success) {
                window.soundEngine.playOrderComplete();
                this.toast(data.message || 'Conferência finalizada com sucesso! Abra o Putaway.', 'success');
                this.recebimentoAtivo = data;
                this.renderizarConferenciaRecebimento(data);
                this.modalPutawayGuarda();
            } else {
                this.toast(data.error || 'Erro ao finalizar recebimento.', 'error');
            }
        } catch (err) {
            console.error('Erro ao finalizar recebimento:', err);
            this.toast('Erro ao finalizar conferência de entrada.', 'error');
        }
    },

    abrirPutawayRecebimento(recId) {
        this.iniciarConferenciaRecebimento(recId).then(() => {
            this.modalPutawayGuarda();
        });
    },

    async modalPutawayGuarda() {
        if (!this.recebimentoAtivo || !this.recebimentoAtivo.itens) {
            this.toast('Nenhum recebimento ativo.', 'warning');
            return;
        }

        const tbody = document.getElementById('tabelaPutawayBody');
        if (!tbody) return;

        // Buscar opções de locais para o select
        let locaisOpts = '<option value="">-- Selecionar Posição --</option>';
        if (this.locaisCache.length === 0) {
            await this.carregarLocais();
        }
        this.locaisCache.forEach(l => {
            locaisOpts += `<option value="${l.id}">[${l.tipo.toUpperCase()}] ${l.codigo} (Rua ${l.rua})</option>`;
        });

        let html = '';
        this.recebimentoAtivo.itens.forEach(it => {
            const sugeridoId = it.local_sugerido_id || '';
            html += `
                <tr data-item-id="${it.id}">
                    <td>
                        <strong>${it.descricao}</strong><br>
                        <span class="text-muted fs-xs">SKU: <code class="font-mono">${it.codigo_produto}</code> • EAN: ${it.ean || '---'}</span>
                    </td>
                    <td>
                        <span class="fw-bold text-success fs-sm">${it.quantidade_conferida || it.quantidade_esperada}</span> ${it.unidade || 'UN'}
                    </td>
                    <td>
                        ${it.local_sugerido_codigo ? `<span class="loc-badge loc-badge-picking"><i class="fa-solid fa-location-dot"></i> ${it.local_sugerido_codigo}</span>` : '<span class="text-muted fs-xs">Nenhum</span>'}
                    </td>
                    <td>
                        <select class="form-select form-select-sm putaway-local-select">
                            ${locaisOpts}
                        </select>
                    </td>
                </tr>
            `;
        });

        tbody.innerHTML = html;

        // Pré-selecionar o local sugerido
        const rows = tbody.querySelectorAll('tr');
        rows.forEach((r, idx) => {
            const it = this.recebimentoAtivo.itens[idx];
            if (it && it.local_sugerido_id) {
                const sel = r.querySelector('.putaway-local-select');
                if (sel) sel.value = it.local_sugerido_id;
            }
        });

        document.getElementById('modalPutaway').classList.add('active');
    },

    async confirmarPutaway() {
        if (!this.recebimentoAtivo || !this.recebimentoAtivo.recebimento) return;
        const recId = this.recebimentoAtivo.recebimento.id;

        const rows = document.querySelectorAll('#tabelaPutawayBody tr');
        const alocacoes = [];

        rows.forEach(r => {
            const itemId = r.getAttribute('data-item-id');
            const localId = r.querySelector('.putaway-local-select')?.value;
            if (itemId && localId) {
                alocacoes.push({ item_id: itemId, local_id: localId });
            }
        });

        if (alocacoes.length === 0) {
            this.toast('Selecione as posições de prateleira para guardar os itens.', 'warning');
            return;
        }

        try {
            const res = await fetch('api/recebimento.php?action=armazenar', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ recebimento_id: recId, itens: alocacoes })
            });
            const data = await res.json();

            if (data.success) {
                this.toast(data.message || 'Itens guardados e saldos de estoque atualizados nas prateleiras!', 'success');
                this.fecharModais();
                this.recebimentoAtivo = data;
                this.renderizarConferenciaRecebimento(data);
                this.carregarLocais();
            } else {
                this.toast(data.error || 'Erro ao confirmar armazenagem.', 'error');
            }
        } catch (err) {
            console.error('Erro no putaway:', err);
            this.toast('Erro ao processar armazenagem.', 'error');
        }
    },

    async startCameraRecebimento() {
        const btnStart = document.getElementById('btnStartCamRec');
        const btnStop = document.getElementById('btnStopCamRec');
        const laser = document.getElementById('scanLaserRec');

        const started = await this.scanner.startCamera('readerRec');
        if (started) {
            if (btnStart) btnStart.style.display = 'none';
            if (btnStop) btnStop.style.display = 'block';
            if (laser) laser.style.display = 'block';
        } else {
            this.toast('Não foi possível iniciar a câmera para conferência de entrada.', 'error');
        }
    },

    toast(mensagem, tipo = 'info') {
        const container = document.getElementById('toastContainer');
        if (!container) return;
        const toast = document.createElement('div');
        toast.className = `wms-toast toast-${tipo}`;

        let icon = '<i class="fa-solid fa-circle-info text-primary"></i>';
        if (tipo === 'success') icon = '<i class="fa-solid fa-circle-check text-success"></i>';
        else if (tipo === 'error') icon = '<i class="fa-solid fa-triangle-exclamation text-danger"></i>';
        else if (tipo === 'warning') icon = '<i class="fa-solid fa-circle-exclamation text-warning"></i>';

        toast.innerHTML = `${icon} <span>${mensagem}</span>`;
        container.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(16px)';
            toast.style.transition = 'all 0.25s ease-out';
            setTimeout(() => toast.remove(), 260);
        }, 3500);
    }
};

// Inicializar aplicação ao carregar a página
document.addEventListener('DOMContentLoaded', () => {
    App.init();
});
