const MINIMUM_SCORE = 0.6;
const MINIMUM_FACE_SIZE = 160;
const DETECTION_INTERVAL = 200;

class DtsFaceCheck extends HTMLElement {
    connectedCallback() {
        this.video = this.querySelector('[data-face-video]');
        this.status = this.querySelector('[data-face-status]');
        this.startButton = this.querySelector('[data-face-start]');
        this.skipButton = this.querySelector('[data-face-skip]');
        this.startButton.addEventListener('click', () => this.start());
        this.skipButton.addEventListener('click', () => this.finish(true));
        this.onVisibilityChange = () => {
            if (document.hidden && this.stream) this.reset();
        };
        document.addEventListener('visibilitychange', this.onVisibilityChange);
        this.reset();
    }

    disconnectedCallback() {
        document.removeEventListener('visibilitychange', this.onVisibilityChange);
        this.stopCamera();
    }

    reset() {
        this.runId = (this.runId || 0) + 1;
        this.complete = false;
        this.blinkStartedAt = 0;
        this.blinkDetected = false;
        this.stopCamera();
        this.video.hidden = true;
        this.startButton.hidden = false;
        this.startButton.disabled = false;
        this.skipButton.disabled = false;
        this.setStatus('Use the camera for a quick presence check, or continue with your signature only.');
    }

    async start() {
        if (!navigator.mediaDevices?.getUserMedia || this.startButton.disabled) {
            this.setStatus('This browser cannot use the camera. Continue with your signature only.', true);
            return;
        }

        const runId = ++this.runId;
        this.startedAt = performance.now();
        this.startButton.disabled = true;
        this.setStatus('Starting the front camera…');

        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                audio: false,
                video: {
                    facingMode: 'user',
                    width: { ideal: 640 },
                    height: { ideal: 480 },
                },
            });
            if (runId !== this.runId) {
                stream.getTracks().forEach((track) => track.stop());
                return;
            }

            this.stream = stream;
            this.video.srcObject = this.stream;
            this.video.hidden = false;
            await this.video.play();
            this.setStatus('Loading the on-device face check…');
            const detector = await this.detector();
            if (runId !== this.runId) return;
            await detector.load();
            if (runId !== this.runId) return;

            this.setStatus('Center your face, look at the camera, then blink once.');
            await this.detect(runId);
        } catch (error) {
            if (runId !== this.runId) return;
            this.fail(error?.name === 'NotAllowedError'
                ? 'Camera permission was not granted. Continue with your signature only.'
                : 'The camera check could not start. Try again or continue with your signature only.');
        }
    }

    async detector() {
        if (this.human) return this.human;

        const { Human } = await import('@vladmandic/human');
        this.human = new Human({
            modelBasePath: this.dataset.modelPath,
            cacheSensitivity: 0.7,
            filter: { enabled: true, autoBrightness: true },
            face: {
                enabled: true,
                detector: {
                    rotation: true,
                    maxDetected: 2,
                    minConfidence: MINIMUM_SCORE,
                    minSize: MINIMUM_FACE_SIZE,
                    skipFrames: 0,
                    skipTime: DETECTION_INTERVAL,
                },
                mesh: { enabled: true },
                iris: { enabled: true },
                description: { enabled: false },
                emotion: { enabled: false },
                antispoof: { enabled: true, skipFrames: 0, skipTime: 500 },
                liveness: { enabled: true, skipFrames: 0, skipTime: 500 },
            },
            body: { enabled: false },
            hand: { enabled: false },
            object: { enabled: false },
            segmentation: { enabled: false },
            gesture: { enabled: true },
        });

        return this.human;
    }

    async detect(runId) {
        while (runId === this.runId && !this.complete) {
            if (performance.now() - this.startedAt > 30000) {
                this.fail('The camera check timed out. Try again or continue with your signature only.');
                return;
            }

            const result = await this.human.detect(this.video);
            if (runId !== this.runId) return;

            if (this.evaluate(result)) {
                this.finish(false);
                return;
            }

            await new Promise((resolve) => setTimeout(resolve, DETECTION_INTERVAL));
        }
    }

    evaluate(result) {
        if (result.face.length !== 1) {
            this.setStatus(result.face.length > 1 ? 'Only one person should be in view.' : 'Center your face in the camera.');
            return false;
        }

        const face = result.face[0];
        const gestures = Object.values(result.gesture || {}).map(({ gesture }) => gesture);
        const blinking = gestures.includes('blink left eye') || gestures.includes('blink right eye');

        if (blinking && !this.blinkStartedAt) this.blinkStartedAt = performance.now();
        if (!blinking && this.blinkStartedAt) {
            const duration = performance.now() - this.blinkStartedAt;
            this.blinkDetected ||= duration >= 30 && duration <= 1000;
            this.blinkStartedAt = 0;
        }

        if (Math.min(face.box[2], face.box[3]) < MINIMUM_FACE_SIZE) {
            this.setStatus('Move a little closer to the camera.');
        } else if ((face.faceScore || face.boxScore || 0) < MINIMUM_SCORE) {
            this.setStatus('Face the camera in better lighting.');
        } else if (!gestures.includes('facing center')) {
            this.setStatus('Look straight toward the camera.');
        } else if (!this.blinkDetected) {
            this.setStatus('Blink once while looking at the camera.');
        } else if ((face.real || 0) < MINIMUM_SCORE || (face.live || 0) < MINIMUM_SCORE) {
            this.setStatus('Keep looking at the camera while the presence check finishes.');
        } else {
            return true;
        }

        return false;
    }

    finish(skipped) {
        this.runId = (this.runId || 0) + 1;
        this.complete = true;
        this.stopCamera();
        this.video.hidden = true;
        this.startButton.hidden = true;
        this.skipButton.disabled = true;
        this.setStatus(skipped ? 'Continuing with signature only.' : 'Camera check complete. No image was saved.');
        this.dispatchEvent(new CustomEvent('face-check-complete', { bubbles: true, detail: { skipped } }));
    }

    fail(message) {
        this.runId = (this.runId || 0) + 1;
        this.stopCamera();
        this.video.hidden = true;
        this.startButton.disabled = false;
        this.setStatus(message, true);
    }

    stopCamera() {
        this.stream?.getTracks().forEach((track) => track.stop());
        this.stream = null;
        if (this.video) this.video.srcObject = null;
    }

    setStatus(message, isError = false) {
        this.status.textContent = message;
        this.status.classList.toggle('text-red-700', isError);
        this.status.classList.toggle('text-slate-700', !isError);
    }
}

if (!customElements.get('dts-face-check')) customElements.define('dts-face-check', DtsFaceCheck);

