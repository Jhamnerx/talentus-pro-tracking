/**
 * Conversación bidireccional JT1078: micrófono del navegador → parlante del equipo.
 *
 * Captura el micrófono, lo remuestrea a 8 kHz, lo codifica en el códec del equipo (G.711A = 6, G.711U = 7,
 * G.726 = 8) y lo envía al tracker cada 200 ms como tramas [tipo 1B][largo 2B][datos]. El tracker las reenvía
 * como paquetes RTP JT1078 por la conexión de audio que abre el equipo.
 */
(function (root) {
    'use strict';

    var SAMPLE_RATE = 8000, SEND_MS = 200;

    // ---- G.711 (algoritmo de referencia de Sun, g711.c) ----

    var SEG_AEND = [0x1F, 0x3F, 0x7F, 0xFF, 0x1FF, 0x3FF, 0x7FF, 0xFFF],
        SEG_UEND = [0x3F, 0x7F, 0xFF, 0x1FF, 0x3FF, 0x7FF, 0xFFF, 0x1FFF];

    function segment(value, table) {
        for (var i = 0; i < table.length; i++) {
            if (value <= table[i]) return i;
        }
        return table.length;
    }

    function linearToAlaw(sample) {
        var pcm = sample >> 3, mask;

        if (pcm >= 0) {
            mask = 0xD5;
        } else {
            mask = 0x55;
            pcm = -pcm - 1;
        }

        var seg = segment(pcm, SEG_AEND);
        if (seg >= 8) return 0x7F ^ mask;

        var aval = seg << 4;
        aval |= seg < 2 ? (pcm >> 1) & 0x0F : (pcm >> seg) & 0x0F;
        return aval ^ mask;
    }

    function linearToUlaw(sample) {
        var pcm = sample >> 2, mask;

        if (pcm < 0) {
            pcm = -pcm;
            mask = 0x7F;
        } else {
            mask = 0xFF;
        }
        if (pcm > 8159) pcm = 8159;
        pcm += 0x84 >> 2;

        var seg = segment(pcm, SEG_UEND);
        if (seg >= 8) return 0x7F ^ mask;

        return ((seg << 4) | ((pcm >> (seg + 1)) & 0x0F)) ^ mask;
    }

    // ---- Remuestreo a 8 kHz: promedio por ventana (filtro de caja), suficiente para voz ----

    function Resampler(inputRate) {
        this.ratio = inputRate / SAMPLE_RATE;
        this.elapsed = 0; // muestras de entrada desde la última salida (fraccionario si la relación no es entera)
        this.sum = 0;
        this.count = 0;
    }

    /** @return {Int16Array} */
    Resampler.prototype.process = function (input) {
        var out = [];

        for (var i = 0; i < input.length; i++) {
            this.sum += input[i];
            this.count++;
            this.elapsed++;

            if (this.elapsed >= this.ratio) {
                var value = Math.max(-1, Math.min(1, this.sum / this.count));
                out.push(value < 0 ? value * 0x8000 : value * 0x7FFF);
                this.sum = 0;
                this.count = 0;
                this.elapsed -= this.ratio;
            }
        }

        return Int16Array.from(out);
    };

    // ---- Tramas ----

    function Framer(options) {
        this.codec = options.codec;
        this.frameSamples = options.frameSamples || 320;
        this.hisilicon = options.hisilicon !== false;
        this.packing = options.g726Packing || 'rfc3551';
        this.g726 = this.codec === 8 && root.G726 ? root.G726.createEncoder(options.g726Bits || 5) : null;
        this.pending = new Int16Array(0);
    }

    Framer.prototype.encode = function (samples) {
        if (this.codec === 8 && this.g726) return this.g726.encode(samples, this.packing);

        var out = new Uint8Array(samples.length), encode = this.codec === 7 ? linearToUlaw : linearToAlaw;
        for (var i = 0; i < samples.length; i++) out[i] = encode(samples[i]);
        return out;
    };

    /**
     * Agrega muestras y devuelve las tramas completas como [tipo][largo 2B][cabecera HiSilicon opcional + datos].
     * @return {Uint8Array|null}
     */
    Framer.prototype.push = function (samples) {
        var merged = new Int16Array(this.pending.length + samples.length);
        merged.set(this.pending);
        merged.set(samples, this.pending.length);

        var frames = [], total = 0, offset = 0;

        while (merged.length - offset >= this.frameSamples) {
            var data = this.encode(merged.subarray(offset, offset + this.frameSamples)),
                header = this.hisilicon ? 4 : 0,
                frame = new Uint8Array(3 + header + data.length),
                length = header + data.length;

            frame[0] = this.codec;
            frame[1] = (length >> 8) & 0xFF;
            frame[2] = length & 0xFF;
            if (this.hisilicon) {
                // 00 01 <largo en palabras de 16 bits> 00, igual a la que el equipo pone en el audio que envía
                frame[3] = 0x00;
                frame[4] = 0x01;
                frame[5] = (data.length >> 1) & 0xFF;
                frame[6] = 0x00;
            }
            frame.set(data, 3 + header);

            frames.push(frame);
            total += frame.length;
            offset += this.frameSamples;
        }

        this.pending = merged.slice(offset);

        if (!frames.length) return null;

        var body = new Uint8Array(total), position = 0;
        frames.forEach(function (frame) {
            body.set(frame, position);
            position += frame.length;
        });
        return body;
    };

    // ---- Sesión de micrófono ----

    /**
     * @param {Object} options talkUrl, codec, frameSamples, hisilicon, g726Bits, g726Packing,
     *                         onState(state, detail) con state = 'live' | 'waiting' | 'expired' | 'error'
     */
    function Talk(options) {
        this.options = options;
        this.stream = null;
        this.context = null;
        this.processor = null;
        this.timer = null;
        this.queue = [];
        this.sending = false;
    }

    Talk.prototype.start = function () {
        var self = this, options = this.options;

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia)
            return Promise.reject(new Error('microphone'));

        return navigator.mediaDevices.getUserMedia({
            audio: {channelCount: 1, echoCancellation: true, noiseSuppression: true, autoGainControl: true}
        }).then(function (stream) {
            var Context = root.AudioContext || root.webkitAudioContext,
                context = new Context(),
                source = context.createMediaStreamSource(stream),
                processor = context.createScriptProcessor(4096, 1, 1),
                resampler = new Resampler(context.sampleRate),
                framer = new Framer(options);

            processor.onaudioprocess = function (event) {
                var body = framer.push(resampler.process(event.inputBuffer.getChannelData(0)));
                if (body) self.queue.push(body);
            };

            // ScriptProcessor solo procesa si está conectado a la salida; su salida queda en silencio.
            source.connect(processor);
            processor.connect(context.destination);

            self.stream = stream;
            self.context = context;
            self.processor = processor;
            self.timer = setInterval(function () { self.flush(); }, SEND_MS);
        });
    };

    Talk.prototype.flush = function () {
        var self = this;

        if (this.sending || !this.queue.length) return;

        var total = 0, position = 0;
        this.queue.forEach(function (part) { total += part.length; });

        var body = new Uint8Array(total);
        this.queue.forEach(function (part) {
            body.set(part, position);
            position += part.length;
        });
        this.queue = [];
        this.sending = true;

        fetch(this.options.talkUrl, {method: 'POST', body: body, headers: {'Content-Type': 'application/octet-stream'}})
            .then(function (response) {
                // 409: el equipo todavía no abrió su conexión de audio; se descartan estas tramas y se sigue.
                if (response.status === 409) return self.state('waiting');
                if (response.status === 403) return self.state('expired');
                if (!response.ok) return self.state('error');
                self.state('live');
            })
            .catch(function () { self.state('error'); })
            .then(function () { self.sending = false; });
    };

    Talk.prototype.state = function (state) {
        if (this.options.onState) this.options.onState(state);
    };

    Talk.prototype.stop = function () {
        clearInterval(this.timer);
        this.timer = null;
        this.queue = [];

        try { this.processor && this.processor.disconnect(); } catch (e) {}
        try { this.context && this.context.close(); } catch (e) {}
        if (this.stream) this.stream.getTracks().forEach(function (track) { track.stop(); });

        this.stream = this.context = this.processor = null;
    };

    var DeviceTalk = {
        Talk: Talk,
        Framer: Framer,
        Resampler: Resampler,
        linearToAlaw: linearToAlaw,
        linearToUlaw: linearToUlaw
    };

    if (typeof module !== 'undefined' && module.exports) module.exports = DeviceTalk;
    else root.DeviceTalk = DeviceTalk;
})(typeof window !== 'undefined' ? window : this);
