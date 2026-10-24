// Hover text for [data-tooltip] buttons, drawn as one floating box instead of a CSS ::after.
// A CSS tooltip is cut off by anything that clips its content (dialogs, scrolling tables, card edges); this box sits
// on top of the page (inside the open dialog, so it stays above the dialog's backdrop), flips below when there is no
// room above, and stays inside the screen. Buttons keep their aria-label, so the box is hidden from screen readers.

const GAP = 6;
const EDGE = 4;

let tip = null;
let current = null;

function box() {
    if (!tip) {
        tip = document.createElement('div');
        tip.className = 'dts-floating-tooltip';
        tip.setAttribute('aria-hidden', 'true');
    }

    return tip;
}

function hide() {
    current = null;
    tip?.remove();
}

function show(target) {
    const text = target.dataset.tooltip;

    // An open menu covers the tooltip's spot (same rule as the CSS version).
    if (!text || target.closest('details[open]')?.querySelector(':scope > summary') === target) {
        hide();

        return;
    }

    const el = box();
    current = target;
    el.textContent = text;

    // Inside an open modal dialog, anything outside it sits under the backdrop, so the box goes into the dialog.
    (target.closest('dialog[open]') ?? document.body).appendChild(el);

    // A transformed ancestor moves position: fixed; measure where (0, 0) really lands and correct for it.
    el.style.left = '0px';
    el.style.top = '0px';
    const origin = el.getBoundingClientRect();
    const anchor = target.getBoundingClientRect();
    const width = origin.width;
    const height = origin.height;

    const above = anchor.top - GAP - height;
    const below = anchor.bottom + GAP;
    const preferBelow = target.hasAttribute('data-tooltip-below');
    let top = preferBelow ? below : above;

    if (!preferBelow && above < EDGE) top = below;
    if (preferBelow && below + height > window.innerHeight - EDGE) top = above;

    const left = Math.min(
        Math.max(anchor.left + (anchor.width - width) / 2, EDGE),
        window.innerWidth - width - EDGE,
    );

    el.style.left = `${left - origin.left}px`;
    el.style.top = `${top - origin.top}px`;
}

document.documentElement.classList.add('dts-js-tooltips');

// Checked after the event's other handlers have run: some buttons only get their text then (row buttons in dts.js).
document.addEventListener('pointerover', (event) => {
    if (event.pointerType === 'touch') return;

    queueMicrotask(() => {
        const target = event.target.closest?.('[data-tooltip]');

        if (target && target !== current) show(target);
        else if (!target && current) hide();
    });
});

document.addEventListener('focusin', (event) => {
    queueMicrotask(() => {
        const target = event.target.closest?.('[data-tooltip]');

        // Same as :focus-visible: keyboard focus shows it, a mouse click does not add a second trigger.
        if (target && target.matches(':focus-visible')) show(target);
    });
});

document.addEventListener('focusout', (event) => {
    if (event.target === current) hide();
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') hide();
});

// The anchor moves on scroll and resize, and a click often opens a dialog or menu over it.
window.addEventListener('scroll', hide, true);
window.addEventListener('resize', hide);
// A click elsewhere closes it; a click on the button itself keeps it, so "Copied" can show.
document.addEventListener('pointerdown', (event) => {
    if (event.target.closest?.('[data-tooltip]') !== current) hide();
});
document.addEventListener('turbo:before-render', hide);

// Some buttons get or change their text after the pointer arrives (row buttons, Copy -> Copied); keep the box in step.
new MutationObserver((records) => {
    for (const { target } of records) {
        if (target === current || target.matches(':hover') || target.matches(':focus-visible')) show(target);
    }

    if (current && !current.isConnected) hide();
}).observe(document.documentElement, { subtree: true, attributes: true, attributeFilter: ['data-tooltip'] });
