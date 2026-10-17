// Timelines (Fast Track "Document movement", the staff document page, and the document window's Activity history):
// as each step scrolls into view, in either direction, its dot pops in and the line to the next step draws down. Steps
// added later (Refresh, "Load older activity", a reopened document window) are picked up as they appear. Without
// JavaScript, or for people who turn off animations, the timeline simply shows as it is.

const STEP_SELECTOR = '.dts-step-timeline > li, .dts-workspace-timeline-entry';

if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches && 'IntersectionObserver' in window) {
    // Ancestors that scroll (the history panels) clip what counts as visible, so steps reveal as the panel scrolls too.
    // A step resets once it is fully out of view, so it animates again whether the reader scrolls down or back up.
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.intersectionRatio >= 0.35) entry.target.classList.add('is-revealed');
            else if (!entry.isIntersecting) entry.target.classList.remove('is-revealed');
        });
    }, { threshold: [0, 0.35] });

    const watch = (root) => {
        const steps = root.matches?.(STEP_SELECTOR) ? [root] : [...(root.querySelectorAll?.(STEP_SELECTOR) ?? [])];
        steps.forEach((step) => {
            if (step.classList.contains('dts-reveal-step')) return;
            step.classList.add('dts-reveal-step');
            observer.observe(step);
        });
    };

    watch(document);
    new MutationObserver((mutations) => {
        mutations.forEach((mutation) => mutation.addedNodes.forEach((node) => {
            if (node.nodeType === Node.ELEMENT_NODE) watch(node);
        }));
    }).observe(document.documentElement, { childList: true, subtree: true });
}
