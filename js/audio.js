/**
 * AudioEngine - Gerador de áudio e feedback sonoro/tátil para WMS via Web Audio API
 */
class AudioEngine {
    constructor() {
        this.ctx = null;
        this.enabled = true;
    }

    init() {
        if (!this.ctx) {
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            if (AudioContext) {
                this.ctx = new AudioContext();
            }
        }
        if (this.ctx && this.ctx.state === 'suspended') {
            this.ctx.resume();
        }
    }

    setEnabled(val) {
        this.enabled = !!val;
    }

    // Beep de Sucesso (Item bipado corretamente)
    playSuccess() {
        if (!this.enabled) return;
        this.init();
        if (!this.ctx) return;

        try {
            const osc = this.ctx.createOscillator();
            const gain = this.ctx.createGain();

            osc.type = 'sine';
            const now = this.ctx.currentTime;

            // Frequência rápida ascendente de 1300Hz para 1800Hz (beep de caixa/leitor industrial)
            osc.frequency.setValueAtTime(1300, now);
            osc.frequency.exponentialRampToValueAtTime(1800, now + 0.08);

            gain.gain.setValueAtTime(0.3, now);
            gain.gain.exponentialRampToValueAtTime(0.01, now + 0.09);

            osc.connect(gain);
            gain.connect(this.ctx.destination);

            osc.start(now);
            osc.stop(now + 0.09);

            // Feedback tátil em dispositivos móveis
            if (navigator.vibrate) {
                navigator.vibrate(60);
            }
        } catch (e) {
            console.warn('Erro ao tocar áudio de sucesso:', e);
        }
    }

    // Beep de Erro / Alerta Grave (Item não pertence ou quantidade excedida)
    playError() {
        if (!this.enabled) return;
        this.init();
        if (!this.ctx) return;

        try {
            const osc = this.ctx.createOscillator();
            const gain = this.ctx.createGain();

            osc.type = 'sawtooth';
            const now = this.ctx.currentTime;

            // Frequência grave de erro tipo buzina
            osc.frequency.setValueAtTime(220, now);
            osc.frequency.setValueAtTime(180, now + 0.15);

            gain.gain.setValueAtTime(0.4, now);
            gain.gain.exponentialRampToValueAtTime(0.01, now + 0.35);

            osc.connect(gain);
            gain.connect(this.ctx.destination);

            osc.start(now);
            osc.stop(now + 0.35);

            // Vibração dupla para erro
            if (navigator.vibrate) {
                navigator.vibrate([120, 80, 200]);
            }
        } catch (e) {
            console.warn('Erro ao tocar áudio de erro:', e);
        }
    }

    // Alerta de Item Concluído
    playItemDone() {
        if (!this.enabled) return;
        this.init();
        if (!this.ctx) return;

        try {
            const now = this.ctx.currentTime;
            [1046.5, 1318.5].forEach((freq, idx) => {
                const osc = this.ctx.createOscillator();
                const gain = this.ctx.createGain();
                osc.type = 'triangle';
                osc.frequency.setValueAtTime(freq, now + idx * 0.08);

                gain.gain.setValueAtTime(0.3, now + idx * 0.08);
                gain.gain.exponentialRampToValueAtTime(0.01, now + idx * 0.08 + 0.12);

                osc.connect(gain);
                gain.connect(this.ctx.destination);

                osc.start(now + idx * 0.08);
                osc.stop(now + idx * 0.08 + 0.12);
            });
        } catch (e) {
            console.warn(e);
        }
    }

    // Fanfarra de Pedido 100% Concluído
    playOrderComplete() {
        if (!this.enabled) return;
        this.init();
        if (!this.ctx) return;

        try {
            const notes = [523.25, 659.25, 783.99, 1046.5]; // C5, E5, G5, C6
            const now = this.ctx.currentTime;

            notes.forEach((freq, idx) => {
                const osc = this.ctx.createOscillator();
                const gain = this.ctx.createGain();

                osc.type = 'sine';
                const startTime = now + (idx * 0.11);
                const duration = (idx === notes.length - 1) ? 0.4 : 0.12;

                osc.frequency.setValueAtTime(freq, startTime);
                gain.gain.setValueAtTime(0.35, startTime);
                gain.gain.exponentialRampToValueAtTime(0.01, startTime + duration);

                osc.connect(gain);
                gain.connect(this.ctx.destination);

                osc.start(startTime);
                osc.stop(startTime + duration);
            });

            if (navigator.vibrate) {
                navigator.vibrate([100, 50, 100, 50, 250]);
            }
        } catch (e) {
            console.warn(e);
        }
    }
}

window.soundEngine = new AudioEngine();
