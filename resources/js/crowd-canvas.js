// Landing hero: a crowd of people walking across a strip under the search band.
// Adapted from Skiper UI "Skiper 39 / Crowd Canvas" (skiper-ui.com, @gurvinder-singh02), itself inspired by
// https://codepen.io/zadvorsky/pen/xxwbBQV. Illustrations: Open Peeps by Pablo Stanley (openpeeps.com, CC0).
// Plain canvas and requestAnimationFrame instead of React + GSAP; the page needs no extra libraries.
//
// <canvas data-crowd-canvas data-src="sprite.png" data-rows="15" data-cols="7"></canvas>
// The sprite is a sheet of figures on a transparent background, in rows. Each figure is found from the empty space
// around it, so uneven spacing and wide figures (a market stall, two people at a board) are cut out whole. If that
// fails, the sheet is cut into an even grid: data-rows across, data-cols down (the original component's naming).

const WALK_SECONDS = 10; // time to cross the strip at speed 1
const STEP_SECONDS = 0.25; // half a bob (up or down)
const STEP_HEIGHT = 0.03; // bob height, as a share of a figure's height

const randomRange = (min, max) => min + Math.random() * (max - min);
const takeRandom = (array) => array.splice(Math.floor(Math.random() * array.length), 1)[0];

// Cuts a sprite sheet into figures: rowCount rows, and inside each row the runs of non-empty columns. Gaps smaller
// than a few pixels do not split a figure. Each figure keeps its row's top and height, so everyone in a row stands
// on the same line.
function findFigures(image, rowCount) {
    const width = image.naturalWidth;
    const height = image.naturalHeight;
    const sheet = document.createElement('canvas');
    sheet.width = width;
    sheet.height = height;
    const context = sheet.getContext('2d', { willReadFrequently: true });
    context.drawImage(image, 0, 0);
    let pixels;
    try {
        pixels = context.getImageData(0, 0, width, height).data;
    } catch {
        return [];
    }
    const filled = (x, y) => pixels[(y * width + x) * 4 + 3] > 24;
    const runs = (length, isFilled, minGap, minSize) => {
        const found = [];
        let start = -1;
        let lastFilled = -1;
        for (let i = 0; i < length; i++) {
            if (!isFilled(i)) continue;
            if (start === -1) start = i;
            else if (i - lastFilled > minGap) {
                found.push([start, lastFilled]);
                start = i;
            }
            lastFilled = i;
        }
        if (start !== -1) found.push([start, lastFilled]);
        return found.filter(([from, to]) => to - from + 1 >= minSize);
    };

    // Rows: the sheet has rowCount rows of figures. A hat can reach into the row above, so instead of trusting empty
    // lines, cut at the emptiest line near each expected boundary, then trim empty lines off each row.
    const rowFill = new Array(height).fill(0);
    for (let y = 0; y < height; y++) for (let x = 0; x < width; x++) if (filled(x, y)) rowFill[y]++;
    const cuts = [0];
    for (let k = 1; k < rowCount; k++) {
        const expected = Math.round((k * height) / rowCount);
        const reach = Math.round(height / rowCount / 3);
        let best = expected;
        for (let y = Math.max(cuts[cuts.length - 1] + 1, expected - reach); y <= Math.min(height - 1, expected + reach); y++) {
            if (rowFill[y] < rowFill[best]) best = y;
        }
        cuts.push(best);
    }
    cuts.push(height);
    const bands = [];
    for (let k = 0; k < rowCount; k++) {
        let top = cuts[k];
        let bottom = cuts[k + 1] - 1;
        while (top < bottom && rowFill[top] === 0) top++;
        while (bottom > top && rowFill[bottom] === 0) bottom--;
        if (bottom - top >= 12) bands.push([top, bottom]);
    }

    const figures = [];
    for (const [top, bottom] of bands) {
        const bandHeight = bottom - top + 1;
        const columns = runs(width, (x) => { for (let y = top; y <= bottom; y++) if (filled(x, y)) return true; return false; }, 4, Math.max(8, bandHeight * 0.15));
        for (const [left, right] of columns) {
            figures.push({ sx: left, sy: top, width: right - left + 1, height: bandHeight });
        }
    }
    return figures;
}

function startCrowd(canvas) {
    const ctx = canvas.getContext('2d');
    if (!ctx) return;

    const rows = Number(canvas.dataset.rows) || 15;
    const cols = Number(canvas.dataset.cols) || 7;
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const image = new Image();
    const stage = { width: 0, height: 0, scale: 1 };
    const allPeeps = [];
    let available = [];
    let crowd = [];
    let frame = 0;
    let lastTime = 0;
    let onScreen = true;

    const resetPeep = (peep) => {
        const direction = Math.random() > 0.5 ? 1 : -1;
        const width = peep.width * stage.scale;
        const height = peep.height * stage.scale;
        // Most people stand near the front edge; a few further back (the original's power2.in spread).
        // Depth: from 15% higher (further back) to 35% lower (in front, cut off by the strip's bottom edge).
        const offsetY = (0.35 - 0.5 * Math.random() ** 2) * height;
        peep.anchorY = stage.height - height + offsetY;
        peep.startX = direction === 1 ? -width : stage.width + width;
        peep.endX = direction === 1 ? stage.width : 0;
        peep.scaleX = direction;
        peep.speed = randomRange(0.5, 1.5);
        peep.elapsed = 0;
    };

    const addToCrowd = (progress = 0) => {
        const peep = takeRandom(available);
        resetPeep(peep);
        peep.elapsed = progress * WALK_SECONDS;
        crowd.push(peep);
        crowd.sort((a, b) => a.anchorY - b.anchorY);
    };

    const fillCrowd = () => {
        crowd = [];
        available = [...allPeeps];
        // Fewer people on narrow screens so the strip stays readable.
        const count = Math.min(allPeeps.length, Math.max(8, Math.round(stage.width / 28)));
        for (let i = 0; i < count; i++) addToCrowd(Math.random());
    };

    const draw = () => {
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.setTransform(devicePixelRatio, 0, 0, devicePixelRatio, 0, 0);
        for (const peep of crowd) {
            const t = Math.min(peep.elapsed / WALK_SECONDS, 1);
            const x = peep.startX + (peep.endX - peep.startX) * t;
            // Up and down every STEP_SECONDS, eased like a footstep.
            const bob = (1 - Math.cos((peep.elapsed / STEP_SECONDS) * Math.PI)) / 2;
            const y = peep.anchorY - bob * STEP_HEIGHT * peep.height * stage.scale;
            ctx.save();
            ctx.translate(x, y);
            ctx.scale(peep.scaleX, 1);
            ctx.drawImage(image, peep.sx, peep.sy, peep.width, peep.height, 0, 0, peep.width * stage.scale, peep.height * stage.scale);
            ctx.restore();
        }
    };

    const tick = (time) => {
        const seconds = lastTime ? Math.min((time - lastTime) / 1000, 0.1) : 0;
        lastTime = time;
        for (const peep of [...crowd]) {
            peep.elapsed += seconds * peep.speed;
            if (peep.elapsed >= WALK_SECONDS) {
                crowd.splice(crowd.indexOf(peep), 1);
                available.push(peep);
                addToCrowd();
            }
        }
        draw();
        frame = requestAnimationFrame(tick);
    };

    const play = () => {
        if (frame || reduceMotion.matches || !onScreen || document.hidden) return;
        lastTime = 0;
        frame = requestAnimationFrame(tick);
    };

    const pause = () => {
        cancelAnimationFrame(frame);
        frame = 0;
    };

    const resize = () => {
        stage.width = canvas.clientWidth;
        stage.height = canvas.clientHeight;
        canvas.width = stage.width * devicePixelRatio;
        canvas.height = stage.height * devicePixelRatio;
        // A figure is about four fifths of the strip's height.
        // A typical figure is nine tenths of the strip's height.
        stage.scale = (stage.height * 0.9) / (allPeeps[0]?.rowHeight || allPeeps[0]?.height || 1);
        fillCrowd();
        draw();
    };

    image.onload = () => {
        allPeeps.push(...findFigures(image, cols));
        if (allPeeps.length === 0) {
            const width = image.naturalWidth / rows;
            const height = image.naturalHeight / cols;
            for (let i = 0; i < rows * cols; i++) {
                allPeeps.push({ sx: (i % rows) * width, sy: Math.floor(i / rows) * height, width, height });
            }
        }
        // Size everyone by the typical (median) row height, so one tall row does not shrink the rest.
        const heights = allPeeps.map((peep) => peep.height).sort((a, b) => a - b);
        const typical = heights[Math.floor(heights.length / 2)];
        allPeeps.forEach((peep) => { peep.rowHeight = typical; });
        resize();
        play();
    };
    image.src = canvas.dataset.src;

    new ResizeObserver(() => allPeeps.length && resize()).observe(canvas);
    new IntersectionObserver(([entry]) => {
        onScreen = entry.isIntersecting;
        if (onScreen) play();
        else pause();
    }).observe(canvas);
    document.addEventListener('visibilitychange', () => (document.hidden ? pause() : play()));
    // Reduced motion: the crowd stands still (one drawn frame) instead of walking.
    reduceMotion.addEventListener('change', () => (reduceMotion.matches ? pause() : play()));
}

document.querySelectorAll('[data-crowd-canvas]').forEach(startCrowd);
