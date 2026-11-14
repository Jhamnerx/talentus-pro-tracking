/**
 * G.726 ADPCM (16/24/32/40 kbit/s, 8 kHz) para el audio de cámaras JT1078.
 *
 * Basado en el algoritmo de referencia ITU-T G.726 (implementación de dominio público de Sun Microsystems,
 * g72x.c / g721.c / g723_24.c / g723_40.c) y las tablas de 16 kbit/s de spandsp.
 *
 * Uso:
 *   var decoder = G726.createDecoder(bits);           // bits por muestra: 2, 3, 4 o 5
 *   var pcm = decoder.decode(bytes, 'rfc3551');        // Int16Array; empaquetado 'rfc3551' (LSB primero) o 'aal2'
 */
(function (root) {
    'use strict';

    var POWER2 = [1, 2, 4, 8, 0x10, 0x20, 0x40, 0x80, 0x100, 0x200, 0x400, 0x800, 0x1000, 0x2000, 0x4000];

    var RATES = {
        2: {
            qtab: [261],
            dqlntab: [116, 365, 365, 116],
            witab: [-704, 14048, 14048, -704],
            fitab: [0, 0xE00, 0xE00, 0],
            sign: 2, srMask: 0x3FFF, wiShift: 0
        },
        3: {
            qtab: [8, 218, 331],
            dqlntab: [-2048, 135, 273, 373, 373, 273, 135, -2048],
            witab: [-128, 960, 4384, 18624, 18624, 4384, 960, -128],
            fitab: [0, 0x200, 0x400, 0xE00, 0xE00, 0x400, 0x200, 0],
            sign: 4, srMask: 0x3FFF, wiShift: 0
        },
        4: {
            qtab: [-124, 80, 178, 246, 300, 349, 400],
            dqlntab: [-2048, 4, 135, 213, 273, 323, 373, 425, 425, 373, 323, 273, 213, 135, 4, -2048],
            witab: [-12, 18, 41, 64, 112, 198, 355, 1122, 1122, 355, 198, 112, 64, 41, 18, -12],
            fitab: [0, 0, 0, 0x200, 0x200, 0x200, 0x600, 0xE00, 0xE00, 0x600, 0x200, 0x200, 0x200, 0, 0, 0],
            sign: 8, srMask: 0x3FFF, wiShift: 5
        },
        5: {
            qtab: [-122, -16, 68, 139, 198, 250, 298, 339, 378, 413, 445, 475, 502, 528, 553],
            dqlntab: [-2048, -66, 28, 104, 169, 224, 274, 318, 358, 395, 429, 459, 488, 514, 539, 566,
                566, 539, 514, 488, 459, 429, 395, 358, 318, 274, 224, 169, 104, 28, -66, -2048],
            witab: [448, 448, 768, 1248, 1280, 1312, 1856, 3200, 4512, 5728, 7008, 8960, 11456, 14080, 16928, 22272,
                22272, 16928, 14080, 11456, 8960, 7008, 5728, 4512, 3200, 1856, 1312, 1280, 1248, 768, 448, 448],
            fitab: [0, 0, 0, 0, 0, 0x200, 0x200, 0x200, 0x200, 0x200, 0x400, 0x600, 0x800, 0xA00, 0xC00, 0xC00,
                0xC00, 0xC00, 0xA00, 0x800, 0x600, 0x400, 0x200, 0x200, 0x200, 0x200, 0x200, 0, 0, 0, 0, 0],
            sign: 0x10, srMask: 0x7FFF, wiShift: 0
        }
    };

    function quan(val, table, size) {
        var i;
        for (i = 0; i < size; i++) {
            if (val < table[i]) break;
        }
        return i;
    }

    function fmult(an, srn) {
        var anmag = an > 0 ? an : ((-an) & 0x1FFF),
            anexp = quan(anmag, POWER2, 15) - 6,
            anmant = anmag === 0 ? 32 : (anexp >= 0 ? anmag >> anexp : anmag << -anexp),
            wanexp = anexp + ((srn >> 6) & 0xF) - 13,
            wanmant = (anmant * (srn & 0x3F) + 0x30) >> 4,
            retval = wanexp >= 0 ? ((wanmant << wanexp) & 0x7FFF) : (wanmant >> -wanexp);

        return (an ^ srn) < 0 ? -retval : retval;
    }

    function State() {
        this.yl = 34816;
        this.yu = 544;
        this.dms = 0;
        this.dml = 0;
        this.ap = 0;
        this.a = [0, 0];
        this.b = [0, 0, 0, 0, 0, 0];
        this.pk = [0, 0];
        this.dq = [32, 32, 32, 32, 32, 32];
        this.sr = [32, 32];
        this.td = 0;
    }

    State.prototype.predictorZero = function () {
        var sezi = 0;
        for (var i = 0; i < 6; i++) sezi += fmult(this.b[i] >> 2, this.dq[i]);
        return sezi;
    };

    State.prototype.predictorPole = function () {
        return fmult(this.a[1] >> 2, this.sr[1]) + fmult(this.a[0] >> 2, this.sr[0]);
    };

    State.prototype.stepSize = function () {
        if (this.ap >= 256) return this.yu;

        var y = this.yl >> 6, dif = this.yu - y, al = this.ap >> 2;
        if (dif > 0) y += (dif * al) >> 6;
        else if (dif < 0) y += (dif * al + 0x3F) >> 6;
        return y;
    };

    function reconstruct(sign, dqln, y) {
        var dql = dqln + (y >> 2);
        if (dql < 0) return sign ? -0x8000 : 0;

        var dex = (dql >> 7) & 15, dqt = 128 + (dql & 127), dq = (dqt << 7) >> (14 - dex);
        return sign ? dq - 0x8000 : dq;
    }

    function floatValue(value, negative) {
        var exp = quan(value, POWER2, 15), result = (exp << 6) + ((value << 6) >> exp);
        return negative ? result - 0x400 : result;
    }

    State.prototype.update = function (codeSize, y, wi, fi, dq, sr, dqsez) {
        var s = this, pk0 = dqsez < 0 ? 1 : 0, mag = dq & 0x7FFF, a2p = 0, cnt;

        // Transición: detecta tonos para reiniciar los predictores
        var ylint = s.yl >> 15, ylfrac = (s.yl >> 10) & 0x1F, thr1 = (32 + ylfrac) << ylint,
            thr2 = ylint > 9 ? 31 << 10 : thr1, dqthr = (thr2 + (thr2 >> 1)) >> 1,
            tr = s.td === 0 ? 0 : (mag <= dqthr ? 0 : 1);

        // Adaptación del factor de escala
        s.yu = y + ((wi - y) >> 5);
        if (s.yu < 544) s.yu = 544;
        else if (s.yu > 5120) s.yu = 5120;
        s.yl += s.yu + ((-s.yl) >> 6);

        if (tr === 1) {
            s.a[0] = s.a[1] = 0;
            for (cnt = 0; cnt < 6; cnt++) s.b[cnt] = 0;
        } else {
            var pks1 = pk0 ^ s.pk[0];

            a2p = s.a[1] - (s.a[1] >> 7);
            if (dqsez !== 0) {
                var fa1 = pks1 ? s.a[0] : -s.a[0];
                if (fa1 < -8191) a2p -= 0x100;
                else if (fa1 > 8191) a2p += 0xFF;
                else a2p += fa1 >> 5;

                if (pk0 ^ s.pk[1]) {
                    if (a2p <= -12160) a2p = -12288;
                    else if (a2p >= 12416) a2p = 12288;
                    else a2p -= 0x80;
                } else if (a2p <= -12416) a2p = -12288;
                else if (a2p >= 12160) a2p = 12288;
                else a2p += 0x80;
            }
            s.a[1] = a2p;

            s.a[0] -= s.a[0] >> 8;
            if (dqsez !== 0) s.a[0] += pks1 === 0 ? 192 : -192;

            var a1ul = 15360 - a2p;
            if (s.a[0] < -a1ul) s.a[0] = -a1ul;
            else if (s.a[0] > a1ul) s.a[0] = a1ul;

            for (cnt = 0; cnt < 6; cnt++) {
                s.b[cnt] -= codeSize === 5 ? s.b[cnt] >> 9 : s.b[cnt] >> 8;
                if (dq & 0x7FFF) s.b[cnt] += (dq ^ s.dq[cnt]) >= 0 ? 128 : -128;
            }
        }

        for (cnt = 5; cnt > 0; cnt--) s.dq[cnt] = s.dq[cnt - 1];
        s.dq[0] = mag === 0 ? (dq >= 0 ? 0x20 : -0x3E0) : floatValue(mag, dq < 0);

        s.sr[1] = s.sr[0];
        if (sr === 0) s.sr[0] = 0x20;
        else if (sr > 0) s.sr[0] = floatValue(sr, false);
        else if (sr > -32768) s.sr[0] = floatValue(-sr, true);
        else s.sr[0] = -0x3E0;

        s.pk[1] = s.pk[0];
        s.pk[0] = pk0;

        s.td = tr === 1 ? 0 : (a2p < -11776 ? 1 : 0);

        // Control de velocidad de adaptación
        s.dms += (fi - s.dms) >> 5;
        s.dml += ((fi << 2) - s.dml) >> 7;

        if (tr === 1) s.ap = 256;
        else if (y < 1536 || s.td === 1 || Math.abs((s.dms << 2) - s.dml) >= (s.dml >> 3)) s.ap += (0x200 - s.ap) >> 4;
        else s.ap += (-s.ap) >> 4;
    };

    function quantize(d, y, table, bits) {
        var size = table.length, dqm = Math.abs(d), exp = quan(dqm >> 1, POWER2, 15),
            mant = ((dqm << 7) >> exp) & 0x7F, dl = (exp << 7) + mant, dln = dl - (y >> 2),
            i = quan(dln, table, size);

        if (d < 0) return (size << 1) + 1 - i;
        // Regla de 1988 (evita el código 0); a 16 kbit/s los 4 estados son pares y no aplica (spandsp).
        if (i === 0 && bits !== 2) return (size << 1) + 1;
        return i;
    }

    function Codec(bits) {
        if (!RATES[bits]) throw new Error('G.726: bits por muestra no soportados: ' + bits);

        this.bits = bits;
        this.rate = RATES[bits];
        this.state = new State();
    }

    /** Un código ADPCM → muestra lineal de 16 bits. */
    Codec.prototype.decodeSample = function (code) {
        var s = this.state, r = this.rate;

        code &= (1 << this.bits) - 1;

        var sezi = s.predictorZero(), sez = sezi >> 1, se = (sezi + s.predictorPole()) >> 1,
            y = s.stepSize(),
            dq = reconstruct(code & r.sign, r.dqlntab[code], y),
            sr = dq < 0 ? se - (dq & r.srMask) : se + dq,
            dqsez = sr - se + sez;

        s.update(this.bits, y, r.witab[code] << r.wiShift, r.fitab[code], dq, sr, dqsez);

        var out = sr << 2;
        return out > 32767 ? 32767 : (out < -32768 ? -32768 : out);
    };

    /** Muestra lineal de 16 bits → código ADPCM. */
    Codec.prototype.encodeSample = function (sample) {
        var s = this.state, r = this.rate, sl = sample >> 2;

        var sezi = s.predictorZero(), sez = sezi >> 1, se = (sezi + s.predictorPole()) >> 1,
            d = sl - se,
            y = s.stepSize(),
            code = quantize(d, y, r.qtab, this.bits),
            dq = reconstruct(code & r.sign, r.dqlntab[code], y),
            sr = dq < 0 ? se - (dq & r.srMask) : se + dq,
            dqsez = sr + sez - se;

        s.update(this.bits, y, r.witab[code] << r.wiShift, r.fitab[code], dq, sr, dqsez);

        return code;
    };

    /**
     * @param {Uint8Array} bytes códigos empaquetados
     * @param {string} packing 'rfc3551' (primer código en los bits menos significativos) o 'aal2' (más significativos)
     * @return {Int16Array}
     */
    Codec.prototype.decode = function (bytes, packing) {
        var bits = this.bits, mask = (1 << bits) - 1, count = Math.floor(bytes.length * 8 / bits),
            out = new Int16Array(count), acc = 0, accBits = 0, n = 0, aal2 = packing === 'aal2';

        for (var i = 0; i < bytes.length && n < count; i++) {
            if (aal2) {
                acc = ((acc << 8) | bytes[i]) & 0xFFFF;
                accBits += 8;
                while (accBits >= bits && n < count) {
                    accBits -= bits;
                    out[n++] = this.decodeSample((acc >> accBits) & mask);
                }
            } else {
                acc |= bytes[i] << accBits;
                accBits += 8;
                while (accBits >= bits && n < count) {
                    out[n++] = this.decodeSample(acc & mask);
                    acc >>= bits;
                    accBits -= bits;
                }
            }
        }

        return out;
    };

    /**
     * @param {Int16Array} samples
     * @param {string} packing 'rfc3551' o 'aal2'
     * @return {Uint8Array}
     */
    Codec.prototype.encode = function (samples, packing) {
        var bits = this.bits, out = new Uint8Array(Math.ceil(samples.length * bits / 8)),
            acc = 0, accBits = 0, n = 0, aal2 = packing === 'aal2';

        for (var i = 0; i < samples.length; i++) {
            var code = this.encodeSample(samples[i]);

            if (aal2) {
                acc = ((acc << bits) | code) & 0xFFFF;
                accBits += bits;
                while (accBits >= 8) {
                    accBits -= 8;
                    out[n++] = (acc >> accBits) & 0xFF;
                }
            } else {
                acc |= code << accBits;
                accBits += bits;
                while (accBits >= 8) {
                    out[n++] = acc & 0xFF;
                    acc >>= 8;
                    accBits -= 8;
                }
            }
        }

        if (accBits > 0) out[n] = aal2 ? (acc << (8 - accBits)) & 0xFF : acc & 0xFF;

        return out;
    };

    /**
     * Bits por muestra según el tamaño de la trama: con 0x1003 se conoce el largo en muestras; si no,
     * se asume la trama HiSilicon habitual de 320 muestras (40 ms) o, si no encaja, 160 (20 ms).
     */
    function bitsForFrame(bytes, frameSamples) {
        var candidates = frameSamples ? [frameSamples] : [320, 160];

        for (var i = 0; i < candidates.length; i++) {
            var bits = bytes * 8 / candidates[i];
            if (bits === Math.round(bits) && RATES[bits]) return bits;
        }

        return 4;
    }

    var G726 = {
        createDecoder: function (bits) { return new Codec(bits); },
        createEncoder: function (bits) { return new Codec(bits); },
        bitsForFrame: bitsForFrame
    };

    if (typeof module !== 'undefined' && module.exports) module.exports = G726;
    else root.G726 = G726;
})(this);
