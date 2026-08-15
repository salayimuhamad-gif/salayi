import { inflateSync } from 'node:zlib';

/*
 * Minimal PNG reader for pixel assertions on Playwright screenshots.
 *
 * The geometry-fidelity tests must prove that a hole is genuinely PUNCHED
 * OUT of a rendered boundary — an absence of fill — and a bounding-box or
 * visibility assertion cannot see that. Playwright screenshots are
 * non-interlaced 8-bit truecolor PNGs (RGB or RGBA), which is the one shape
 * this reader supports; anything else fails loudly rather than sampling
 * garbage. Node's own zlib does the heavy lifting — no dependency is added.
 */

export interface Rgb {
    r: number;
    g: number;
    b: number;
}

export interface DecodedPng {
    width: number;
    height: number;
    pixelAt(x: number, y: number): Rgb;
}

export function decodePng(buffer: Buffer): DecodedPng {
    const signature = [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a];

    for (let i = 0; i < signature.length; i++) {
        if (buffer[i] !== signature[i]) {
            throw new Error('not a PNG');
        }
    }

    let offset = 8;
    let width = 0;
    let height = 0;
    let bitDepth = 0;
    let colorType = 0;
    let interlace = 0;
    const idat: Buffer[] = [];

    while (offset + 8 <= buffer.length) {
        const length = buffer.readUInt32BE(offset);
        const type = buffer.toString('ascii', offset + 4, offset + 8);
        const data = buffer.subarray(offset + 8, offset + 8 + length);

        if (type === 'IHDR') {
            width = data.readUInt32BE(0);
            height = data.readUInt32BE(4);
            bitDepth = data[8];
            colorType = data[9];
            interlace = data[12];
        } else if (type === 'IDAT') {
            idat.push(data);
        } else if (type === 'IEND') {
            break;
        }

        offset += 12 + length;
    }

    if (bitDepth !== 8 || (colorType !== 2 && colorType !== 6) || interlace !== 0) {
        throw new Error(`unsupported PNG shape: depth ${bitDepth}, color ${colorType}, interlace ${interlace}`);
    }

    const channels = colorType === 6 ? 4 : 3;
    const stride = width * channels;
    const raw = inflateSync(Buffer.concat(idat));
    const pixels = Buffer.alloc(height * stride);

    /*
     * Per-scanline unfiltering, PNG spec §9: each row starts with one filter
     * byte, and every reconstruction is relative to already-reconstructed
     * bytes (left, above, above-left).
     */
    const paeth = (a: number, b: number, c: number): number => {
        const p = a + b - c;
        const pa = Math.abs(p - a);
        const pb = Math.abs(p - b);
        const pc = Math.abs(p - c);

        if (pa <= pb && pa <= pc) return a;

        return pb <= pc ? b : c;
    };

    for (let y = 0; y < height; y++) {
        const filter = raw[y * (stride + 1)];
        const rowIn = raw.subarray(y * (stride + 1) + 1, (y + 1) * (stride + 1));
        const rowOut = pixels.subarray(y * stride, (y + 1) * stride);
        const prev = y > 0 ? pixels.subarray((y - 1) * stride, y * stride) : null;

        for (let x = 0; x < stride; x++) {
            const left = x >= channels ? rowOut[x - channels] : 0;
            const above = prev ? prev[x] : 0;
            const aboveLeft = prev && x >= channels ? prev[x - channels] : 0;

            let value = rowIn[x];

            if (filter === 1) value += left;
            else if (filter === 2) value += above;
            else if (filter === 3) value += Math.floor((left + above) / 2);
            else if (filter === 4) value += paeth(left, above, aboveLeft);
            else if (filter !== 0) throw new Error(`unsupported PNG filter ${filter}`);

            rowOut[x] = value & 0xff;
        }
    }

    return {
        width,
        height,
        pixelAt(x: number, y: number): Rgb {
            const px = Math.round(x);
            const py = Math.round(y);

            if (px < 0 || py < 0 || px >= width || py >= height) {
                throw new Error(`pixel (${px}, ${py}) outside ${width}x${height}`);
            }

            const at = py * stride + px * channels;

            return { r: pixels[at], g: pixels[at + 1], b: pixels[at + 2] };
        },
    };
}

/** Manhattan distance between two sampled colours. */
export function colourDelta(a: Rgb, b: Rgb): number {
    return Math.abs(a.r - b.r) + Math.abs(a.g - b.g) + Math.abs(a.b - b.b);
}
