/**
 * WMS PRIME PRO - Aplicação Principal de Separação & Conferência
 */

const App = {
    currentView: 'pedidos',
    operador: localStorage.getItem('wms_operador') || 'David',
    conferenciaAtiva: null,
    scanner: null,
    soundEnabled: true,
    modoCego: false,
    pedidosCache: [],
    camerasList: [],
    currentCameraIndex: 0,

    init() {
        // Inicializar áudio e leitor
        this.soundEnabled = localStorage.getItem('wms_sound') !== '0';
        window.soundEngine.setEnabled(this.soundEnabled);
        this.updateSoundIcon();

        document.getElementById('lblOperadorHeader').textContent = this.operador;

        this.scanner = new ScannerManager((code, type) => {
            this.handleBarcodeScan(code, type);
        });

        // Carregar configurações do servidor
        this.carregarConfiguracoes();

        // Carregar estatísticas e pedidos iniciais
        this.carregarStats();
        this.buscarPedidos();

        // Registrar listener de enter no input manual
        const inputManual = document.getElementById('inputManualBarcode');
        if (inputManual) {
            inputManual.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    this.biparManual();
                }
            });
        }
    },

    // --- NAVEGAÇÃO ENTRE TELAS ---
    navigate(viewName) {
        this.currentView = viewName;

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
        } else if (viewName === 'historico') {
            this.carregarHistorico();
            this.stopCamera();
        } else if (viewName === 'eans') {
            this.carregarEans();
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
                document.getElementById('kpiTotalConferencias').textContent = data.stats.total_conferencias || 0;
                document.getElementById('kpiConferidosHoje').textContent = data.stats.conferidos_hoje || 0;
                document.getElementById('kpiEmSeparacao').textContent = data.stats.em_separacao || 0;
                document.getElementById('kpiDivergencias').textContent = data.stats.divergencias || 0;
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
            pageSize: '40'
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
                    <td colspan="8" style="text-align: center; padding: 2.5rem; color: var(--text-muted);">
                        <i class="fa-solid fa-box-open fa-2x"></i><br><br>Nenhum pedido encontrado no SIGE Cloud com os filtros informados.
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

            // Badge Status SIGE
            let badgeSige = '<span class="badge badge-pending">Orçamento</span>';
            const stSige = p.StatusSistema || p.Status || '';
            if (stSige.includes('Aprovado')) badgeSige = '<span class="badge badge-progress">Aprovado</span>';
            else if (stSige.includes('Faturado')) badgeSige = '<span class="badge badge-success">Faturado</span>';
            else if (stSige.includes('Cancelado')) badgeSige = '<span class="badge badge-danger">Cancelado</span>';

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
                btnActionText = '<i class="fa-solid fa-eye"></i> Visualizar';
                btnActionClass = 'btn-secondary';
            } else if (conf.conferencia_status === 'divergencia') {
                badgeWms = '<span class="badge badge-danger"><i class="fa-solid fa-triangle-exclamation"></i> Divergência</span>';
                btnActionText = '<i class="fa-solid fa-triangle-exclamation"></i> Revisar';
                btnActionClass = 'btn-danger';
            }

            html += `
                <tr>
                    <td><strong class="font-mono" style="font-size: 1.05rem; color: #fff;">#${numPedido}</strong></td>
                    <td style="color: var(--text-secondary);">${dataFormatada}</td>
                    <td>
                        <strong style="color: var(--text-primary); display: block;">${p.Cliente || 'Consumidor Final'}</strong>
                        <span style="font-size: 0.75rem; color: var(--text-muted);">${p.ClienteCNPJ || ''}</span>
                    </td>
                    <td>${badgeSige}</td>
                    <td>
                        <span>${totalItens} itens</span>
                        <span style="color: var(--text-muted); font-size: 0.75rem; display: block;">(${totalQtd} un)</span>
                    </td>
                    <td>${badgeWms}</td>
                    <td style="color: var(--text-secondary);">${conf.operador || '-'}</td>
                    <td style="text-align: right;">
                        <button class="btn btn-sm ${btnActionClass}" onclick="App.iniciarConferencia(${numPedido})">
                            ${btnActionText}
                        </button>
                    </td>
                </tr>
            `;
        });

        tbody.innerHTML = html;
    },

    limparFiltros() {
        document.getElementById('formFiltroPedidos').reset();
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
            container.innerHTML = '<p style="text-align: center; color: var(--text-muted);">Nenhum item no pedido.</p>';
            return;
        }

        let html = '';
        itens.forEach(it => {
            const qtdPed = parseFloat(it.quantidade_pedida);
            const qtdConf = parseFloat(it.quantidade_conferida);
            const isDone = qtdConf >= qtdPed;

            let cardStatusClass = 'status-pendente';
            if (isDone) cardStatusClass = 'status-conferido';
            else if (qtdConf > 0) cardStatusClass = 'status-parcial';

            const displayQtdEsperada = this.modoCego ? '?' : qtdPed;

            html += `
                <div class="item-picking-card ${cardStatusClass}" id="item-card-${it.codigo_produto}">
                    <div class="item-details">
                        <h4>${it.descricao}</h4>
                        <div class="item-meta">
                            <span>SKU: <strong class="font-mono">${it.codigo_produto}</strong></span>
                            <span>EAN: <strong class="font-mono">${it.ean || '<i style="color:var(--text-muted)">Sem EAN</i>'}</strong></span>
                            ${it.categoria ? `<span>Cat: <strong>${it.categoria}</strong></span>` : ''}
                            <span>Unidade: <strong>${it.unidade || 'UN'}</strong></span>
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
            container.innerHTML = '<span style="color: var(--text-muted); font-size: 0.8rem; text-align: center; padding: 0.5rem;">Nenhum volume embalado ainda.</span>';
            return;
        }

        let html = '';
        volumes.forEach(v => {
            html += `
                <div style="background: var(--bg-card); padding: 0.5rem 0.75rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <strong style="font-size: 0.85rem;">Volume ${v.numero_volume}/${v.total_volumes}</strong>
                        <span style="font-size: 0.75rem; color: var(--text-muted); display: block;">${v.peso_kg} KG ${v.dimensoes ? '• ' + v.dimensoes : ''}</span>
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
        if (!this.conferenciaAtiva || !this.conferenciaAtiva.conferencia) {
            this.toast('Abra um pedido para bipar itens.', 'warning');
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
                this.toast(data.message || 'Código não confere!', 'error');
                return;
            }

            // Sucesso!
            this.conferenciaAtiva = data;
            this.renderConferencia(data);

            // Animar o item conferido
            if (data.item_bipado) {
                const elem = document.getElementById(`item-card-${data.item_bipado}`);
                if (elem) {
                    elem.classList.add('just-scanned');
                    elem.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    setTimeout(() => elem.classList.remove('just-scanned'), 600);
                }
            }

            // Verificar se o pedido todo foi concluído
            const conf = data.conferencia;
            if (conf.quantidade_total_conferida >= conf.quantidade_total_esperada && conf.quantidade_total_esperada > 0) {
                window.soundEngine.playOrderComplete();
                this.toast('🎉 Parabéns! Pedido 100% conferido e separado!', 'success');
            } else if (data.item_concluido) {
                window.soundEngine.playItemDone();
                this.toast(data.message, 'success');
            } else {
                window.soundEngine.playSuccess();
                this.toast(data.message, 'success');
            }

        } catch (e) {
            window.soundEngine.playError();
            this.toast('Erro na comunicação do leitor com o servidor.', 'error');
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
        const camId = this.camerasList.length > 0 ? this.camerasList[this.currentCameraIndex % this.camerasList.length].id : null;

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

    // --- IMPRESSÃO DE ROMANEIO & ETIQUETAS ---
    imprimirRomaneio() {
        if (!this.conferenciaAtiva) return;
        const conf = this.conferenciaAtiva.conferencia;
        const itens = this.conferenciaAtiva.itens || [];
        const vols = this.conferenciaAtiva.volumes || [];

        const printDiv = document.getElementById('printArea');
        printDiv.innerHTML = `
            <div style="font-family: Arial, sans-serif; padding: 20px; color: #000; background: #fff;">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 15px;">
                    <div>
                        <h2 style="margin: 0; font-size: 20px;">ROMANEIO DE CONFERÊNCIA & EXPEDIÇÃO</h2>
                        <span style="font-size: 13px;">WMS PRIME PRO - SIGE CLOUD</span>
                    </div>
                    <div style="text-align: right;">
                        <svg id="barcodeRomaneioPedido"></svg>
                    </div>
                </div>

                <table style="width: 100%; font-size: 13px; margin-bottom: 15px; border-collapse: collapse;">
                    <tr>
                        <td style="padding: 4px 0;"><strong>Pedido Nº:</strong> #${conf.numero_pedido}</td>
                        <td style="padding: 4px 0;"><strong>Data Conferência:</strong> ${new Date().toLocaleString('pt-BR')}</td>
                    </tr>
                    <tr>
                        <td style="padding: 4px 0;"><strong>Cliente:</strong> ${conf.cliente}</td>
                        <td style="padding: 4px 0;"><strong>Operador:</strong> ${conf.operador || 'David'}</td>
                    </tr>
                    <tr>
                        <td style="padding: 4px 0;"><strong>Status:</strong> ${conf.status.toUpperCase()}</td>
                        <td style="padding: 4px 0;"><strong>Total Volumes:</strong> ${vols.length} volume(s)</td>
                    </tr>
                </table>

                <h3 style="font-size: 15px; margin: 15px 0 8px 0; border-bottom: 1px solid #ccc; padding-bottom: 4px;">ITENS CONFERIDOS</h3>
                <table style="width: 100%; border-collapse: collapse; font-size: 12px; margin-bottom: 20px;">
                    <thead>
                        <tr style="background: #f0f0f0;">
                            <th style="border: 1px solid #000; padding: 6px; text-align: left;">SKU</th>
                            <th style="border: 1px solid #000; padding: 6px; text-align: left;">Descrição</th>
                            <th style="border: 1px solid #000; padding: 6px; text-align: center;">EAN</th>
                            <th style="border: 1px solid #000; padding: 6px; text-align: center;">Pedida</th>
                            <th style="border: 1px solid #000; padding: 6px; text-align: center;">Conferida</th>
                            <th style="border: 1px solid #000; padding: 6px; text-align: center;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${itens.map(it => `
                            <tr>
                                <td style="border: 1px solid #000; padding: 6px;">${it.codigo_produto}</td>
                                <td style="border: 1px solid #000; padding: 6px;">${it.descricao}</td>
                                <td style="border: 1px solid #000; padding: 6px; text-align: center;">${it.ean || '-'}</td>
                                <td style="border: 1px solid #000; padding: 6px; text-align: center;">${it.quantidade_pedida}</td>
                                <td style="border: 1px solid #000; padding: 6px; text-align: center; font-weight: bold;">${it.quantidade_conferida}</td>
                                <td style="border: 1px solid #000; padding: 6px; text-align: center;">${it.status.toUpperCase()}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>

                <div style="margin-top: 40px; display: flex; justify-content: space-between;">
                    <div style="width: 45%; border-top: 1px solid #000; text-align: center; padding-top: 5px; font-size: 12px;">
                        Assinatura do Conferente / Operador
                    </div>
                    <div style="width: 45%; border-top: 1px solid #000; text-align: center; padding-top: 5px; font-size: 12px;">
                        Assinatura do Motorista / Transportadora
                    </div>
                </div>
            </div>
        `;

        // Gerar código de barras
        if (window.JsBarcode) {
            JsBarcode('#barcodeRomaneioPedido', String(conf.numero_pedido), {
                format: 'CODE128',
                height: 35,
                width: 1.5,
                displayValue: true,
                fontSize: 12
            });
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
                        <td style="text-align: right;">
                            <button class="btn btn-sm btn-primary" onclick="App.iniciarConferencia(${c.numero_pedido})">
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

    // --- OPERADOR & PREFERÊNCIAS ---
    modalOperador() {
        document.getElementById('inputNomeOperador').value = this.operador;
        document.getElementById('modalOperador').classList.add('active');
        document.getElementById('inputNomeOperador').focus();
    },

    salvarOperadorAtual() {
        const nome = document.getElementById('inputNomeOperador').value.trim();
        if (nome) {
            this.operador = nome;
            localStorage.setItem('wms_operador', nome);
            document.getElementById('lblOperadorHeader').textContent = nome;
            this.fecharModais();
            this.toast(`Operador alterado para: ${nome}`, 'info');
        }
    },

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

    // --- TOAST NOTIFICATIONS ---
    toast(mensagem, tipo = 'info') {
        const container = document.getElementById('toastContainer');
        const toast = document.createElement('div');
        toast.className = `toast toast-${tipo}`;

        let icon = '<i class="fa-solid fa-circle-info"></i>';
        if (tipo === 'success') icon = '<i class="fa-solid fa-circle-check"></i>';
        else if (tipo === 'error') icon = '<i class="fa-solid fa-triangle-exclamation"></i>';
        else if (tipo === 'warning') icon = '<i class="fa-solid fa-circle-exclamation"></i>';

        toast.innerHTML = `${icon} <span>${mensagem}</span>`;
        container.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(100%)';
            toast.style.transition = 'all 0.3s ease-out';
            setTimeout(() => toast.remove(), 300);
        }, 3500);
    }
};

// Inicializar aplicação ao carregar a página
document.addEventListener('DOMContentLoaded', () => {
    App.init();
});
