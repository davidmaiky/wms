/**
 * ScannerManager - Gerencia a leitura de códigos de barra via Câmera (HTML5-QRCode) e Pistola USB/Bluetooth
 */
class ScannerManager {
    constructor(onScanCallback) {
        this.onScan = onScanCallback || (() => {});
        this.html5QrCode = null;
        this.isScanning = false;
        this.currentCameraId = null;
        this.cameras = [];
        this.torchOn = false;
        this.lastScannedCode = null;
        this.lastScanTime = 0;
        this.scanDebounceMs = 900; // Evitar leitura repetida acidental em menos de 900ms

        this.initPistolaListener();
    }

    // --- LEITURA VIA PISTOLA USB / BLUETOOTH (KEYBOARD WEDGE) ---
    initPistolaListener() {
        let buffer = '';
        let lastKeyTime = Date.now();

        window.addEventListener('keydown', (e) => {
            const currentTime = Date.now();
            const timeDiff = currentTime - lastKeyTime;
            lastKeyTime = currentTime;

            // Se o usuário estiver digitando em um campo de texto explicitamente focado
            const activeTag = document.activeElement ? document.activeElement.tagName.toLowerCase() : '';
            const activeId = document.activeElement ? document.activeElement.id : '';
            const isManualSearchInput = (activeTag === 'input' && activeId === 'inputManualBarcode');

            if (e.key === 'Enter') {
                if (buffer.length >= 2) {
                    const scanned = buffer.trim();
                    buffer = '';
                    this.triggerScan(scanned, 'pistola');
                    if (!isManualSearchInput) {
                        e.preventDefault();
                    }
                } else if (isManualSearchInput) {
                    // Se foi Enter no input manual
                    const val = document.activeElement.value.trim();
                    if (val) {
                        this.triggerScan(val, 'manual');
                        document.activeElement.value = '';
                        e.preventDefault();
                    }
                }
                return;
            }

            // Se o intervalo entre teclas for muito rápido (< 60ms), é um leitor de código de barras físico
            if (e.key.length === 1) {
                if (timeDiff > 100 && !isManualSearchInput) {
                    // Se passou muito tempo desde o último caractere e não estamos em um input, reiniciar buffer
                    buffer = '';
                }
                buffer += e.key;
            }
        });
    }

    // --- LEITURA VIA CÂMERA ---
    async getCameras() {
        if (!window.Html5Qrcode) return [];
        try {
            this.cameras = await Html5Qrcode.getCameras();
            return this.cameras;
        } catch (e) {
            console.warn('Erro ao obter câmeras:', e);
            return [];
        }
    }

    async startCamera(elementId = 'reader', cameraId = null) {
        if (!window.Html5Qrcode) {
            console.error('Biblioteca Html5Qrcode não carregada.');
            return false;
        }

        if (this.isScanning) {
            await this.stopCamera();
        }

        try {
            this.html5QrCode = new Html5Qrcode(elementId);
            
            // Preferir câmera traseira
            const cameraConfig = cameraId 
                ? { deviceId: { exact: cameraId } } 
                : { facingMode: "environment" };

            const qrConfig = {
                fps: 15,
                qrbox: (viewfinderWidth, viewfinderHeight) => {
                    const minEdge = Math.min(viewfinderWidth, viewfinderHeight);
                    return {
                        width: Math.floor(viewfinderWidth * 0.85),
                        height: Math.floor(Math.min(viewfinderHeight * 0.55, 260))
                    };
                },
                aspectRatio: 1.777778,
                formatsToSupport: [
                    Html5QrcodeSupportedFormats.EAN_13,
                    Html5QrcodeSupportedFormats.EAN_8,
                    Html5QrcodeSupportedFormats.CODE_128,
                    Html5QrcodeSupportedFormats.CODE_39,
                    Html5QrcodeSupportedFormats.CODE_93,
                    Html5QrcodeSupportedFormats.UPC_A,
                    Html5QrcodeSupportedFormats.UPC_E,
                    Html5QrcodeSupportedFormats.QR_CODE,
                    Html5QrcodeSupportedFormats.DATA_MATRIX
                ]
            };

            await this.html5QrCode.start(
                cameraConfig,
                qrConfig,
                (decodedText) => {
                    this.triggerScan(decodedText, 'camera');
                },
                () => {
                    // Frame de busca sem código (ignorar para não poluir console)
                }
            );

            this.isScanning = true;
            this.currentCameraId = cameraId;
            return true;
        } catch (err) {
            console.error('Erro ao iniciar câmera:', err);
            this.isScanning = false;
            return false;
        }
    }

    async stopCamera() {
        if (this.html5QrCode && this.isScanning) {
            try {
                await this.html5QrCode.stop();
                this.html5QrCode.clear();
            } catch (e) {
                console.warn('Erro ao parar câmera:', e);
            }
            this.isScanning = false;
        }
    }

    async toggleTorch() {
        if (!this.isScanning || !this.html5QrCode) return false;
        try {
            this.torchOn = !this.torchOn;
            await this.html5QrCode.applyVideoConstraints({
                advanced: [{ torch: this.torchOn }]
            });
            return this.torchOn;
        } catch (e) {
            console.warn('Lanterna não suportada:', e);
            this.torchOn = false;
            return false;
        }
    }

    triggerScan(code, type = 'manual') {
        if (!code) return;
        const cleanCode = code.trim();
        const now = Date.now();

        // Evitar bip duplo no mesmo código se lido em milissegundos pela câmera
        if (cleanCode === this.lastScannedCode && (now - this.lastScanTime) < this.scanDebounceMs) {
            return;
        }

        this.lastScannedCode = cleanCode;
        this.lastScanTime = now;

        if (typeof this.onScan === 'function') {
            this.onScan(cleanCode, type);
        }
    }
}

window.ScannerManager = ScannerManager;
