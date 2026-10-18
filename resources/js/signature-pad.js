// Release signing on a canvas (mouse, finger or stylus): on the staff document page and workspace, and on the kiosk
// laptop outside the Records Unit (kiosk.blade.php).
// Strokes are kept in CSS pixels so a resize redraws them; the hidden field holds a PNG data URL of the signature, or
// nothing until a real stroke exists.
class SignaturePad extends HTMLElement {
    connectedCallback() {
        if (this.canvas) return;
        this.canvas = this.querySelector('canvas');
        this.input = this.querySelector('[data-signature-value]');
        this.clearButton = this.querySelector('[data-signature-clear]');
        this.hint = this.querySelector('[data-signature-hint]');
        this.hintText = this.hint?.textContent ?? '';
        this.strokes = [];
        this.stroke = null;
        this.canvas.style.touchAction = 'none';
        this.canvas.addEventListener('pointerdown', (event) => this.start(event));
        this.canvas.addEventListener('pointermove', (event) => this.move(event));
        ['pointerup', 'pointercancel'].forEach((type) => this.canvas.addEventListener(type, () => this.end()));
        this.clearButton?.addEventListener('click', () => this.clear());
        this.input.addEventListener('invalid', () => this.showError());
        new ResizeObserver(() => this.resize()).observe(this.canvas);
        this.syncValue(false);
    }

    resize() {
        const { width, height } = this.canvas.getBoundingClientRect();
        if (!width || !height) return;
        const ratio = Math.min(window.devicePixelRatio || 1, 2);
        this.canvas.width = Math.round(width * ratio);
        this.canvas.height = Math.round(height * ratio);
        this.redraw();
    }

    point(event) {
        const box = this.canvas.getBoundingClientRect();
        return { x: event.clientX - box.left, y: event.clientY - box.top, p: event.pressure || 0.5 };
    }

    start(event) {
        if (event.button > 0) return;
        event.preventDefault();
        if (this.received) {
            this.received = null;
            this.querySelector('.dts-signature-received')?.remove();
        }
        this.canvas.setPointerCapture(event.pointerId);
        this.stroke = [this.point(event)];
        this.strokes.push(this.stroke);
        this.redraw();
    }

    move(event) {
        if (!this.stroke) return;
        const events = event.getCoalescedEvents?.() ?? [event];
        (events.length ? events : [event]).forEach((item) => this.stroke.push(this.point(item)));
        this.redraw();
    }

    end() {
        if (!this.stroke) return;
        this.stroke = null;
        this.syncValue();
    }

    clear() {
        this.strokes = [];
        this.received = null;
        this.querySelector('.dts-signature-received')?.remove();
        this.redraw();
        this.syncValue();
    }

    // A signature drawn somewhere else (the kiosk outside the Records Unit): shown as an image and sent as is. Drawing
    // on the pad, or Clear, replaces it.
    loadSignature(dataUrl) {
        this.strokes = [];
        this.redraw();
        this.received = dataUrl;
        let image = this.querySelector('.dts-signature-received');
        if (!image) {
            image = document.createElement('img');
            image.className = 'dts-signature-received';
            image.alt = 'Signature received from the kiosk';
            this.querySelector('.dts-signature-box').append(image);
        }
        image.src = dataUrl;
        this.syncValue();
    }

    draw(context, scale) {
        context.lineCap = 'round';
        context.lineJoin = 'round';
        context.strokeStyle = '#111827';
        context.fillStyle = '#111827';
        this.strokes.forEach((stroke) => {
            if (stroke.length === 1) {
                context.beginPath();
                context.arc(stroke[0].x * scale, stroke[0].y * scale, 1.6 * scale, 0, Math.PI * 2);
                context.fill();
                return;
            }
            for (let index = 1; index < stroke.length; index += 1) {
                const from = stroke[index - 1];
                const to = stroke[index];
                context.lineWidth = (1.6 + to.p * 1.8) * scale;
                context.beginPath();
                context.moveTo(from.x * scale, from.y * scale);
                context.lineTo(to.x * scale, to.y * scale);
                context.stroke();
            }
        });
    }

    redraw() {
        const context = this.canvas.getContext('2d');
        const width = this.canvas.getBoundingClientRect().width;
        context.clearRect(0, 0, this.canvas.width, this.canvas.height);
        if (width) this.draw(context, this.canvas.width / width);
    }

    hasSignature() {
        if (this.received) return true;
        // A tap is not a signature: at least one stroke has to actually move.
        return this.strokes.some((stroke) => stroke.length > 1
            && stroke.some((point) => Math.hypot(point.x - stroke[0].x, point.y - stroke[0].y) > 4));
    }

    // Cropped to the ink (plus a margin) so the signature fills its small spot on the printed label, and exported at
    // CSS-pixel size (at most 1000 px wide) so the PNG stays well inside the 100 KB limit.
    exportPng() {
        const { width, height } = this.canvas.getBoundingClientRect();
        const points = this.strokes.flat();
        const margin = 10;
        const crop = points.length
            ? {
                x: Math.max(0, Math.min(...points.map((point) => point.x)) - margin),
                y: Math.max(0, Math.min(...points.map((point) => point.y)) - margin),
                right: Math.min(width, Math.max(...points.map((point) => point.x)) + margin),
                bottom: Math.min(height, Math.max(...points.map((point) => point.y)) + margin),
            }
            : { x: 0, y: 0, right: width, bottom: height };
        const cropWidth = crop.right - crop.x;
        const scale = Math.min(1, 1000 / cropWidth);
        const output = document.createElement('canvas');
        output.width = Math.max(1, Math.round(cropWidth * scale));
        output.height = Math.max(1, Math.round((crop.bottom - crop.y) * scale));
        const context = output.getContext('2d');
        context.scale(scale, scale);
        context.translate(-crop.x, -crop.y);
        this.draw(context, 1);

        return output.toDataURL('image/png');
    }

    syncValue(notify = true) {
        const signed = this.hasSignature();
        this.input.value = signed ? (this.received ?? this.exportPng()) : '';
        this.input.setCustomValidity(signed || !this.input.required ? '' : (this.dataset.requiredMessage || 'Sign inside the box first.'));
        if (this.clearButton) this.clearButton.disabled = !this.strokes.length && !this.received;
        this.classList.toggle('is-signed', signed);
        if (signed) this.resetHint();
        if (notify) this.input.dispatchEvent(new Event('input', { bubbles: true }));
    }

    showError() {
        this.classList.add('is-invalid');
        if (this.hint) this.hint.textContent = this.dataset.requiredMessage || 'Signature needed: sign inside the box first.';
        this.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }

    resetHint() {
        this.classList.remove('is-invalid');
        if (this.hint) this.hint.textContent = this.hintText;
    }
}

if (!customElements.get('dts-signature-pad')) customElements.define('dts-signature-pad', SignaturePad);
