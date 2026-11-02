/*
 * Flow player: replays the recording step by step, without running the request again.
 * Each call gives two steps, the call and the return.
 */
(function () {
    'use strict';

    // Animation time of a step at 1×: real gap × TIME_SCALE, clamped to stay readable.
    const TIME_SCALE = 40;
    const MIN_STEP_MS = 450;
    const MAX_STEP_MS = 2400;

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, (char) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#39;'})[char]);
    }

    /** Steps in execution order: a call, its nested calls, then its return. */
    function buildSteps(calls) {
        const children = new Map();
        const roots = [];
        calls.forEach((call) => {
            if (call.parentId === null) {
                roots.push(call);
            } else {
                if (!children.has(call.parentId)) {
                    children.set(call.parentId, []);
                }
                children.get(call.parentId).push(call);
            }
        });

        const steps = [];
        const visit = (call) => {
            steps.push({kind: 'enter', call, time: call.startMs});
            (children.get(call.id) || []).forEach(visit);
            steps.push({kind: 'leave', call, time: call.endMs});
        };
        roots.forEach(visit);

        return steps;
    }

    class FlowPlayer {
        constructor(root) {
            this.root = root;
            this.flow = JSON.parse(root.querySelector('[data-role="data"]').textContent);
            this.nodes = new Map(this.flow.nodes.map((node) => [node.id, node]));
            this.steps = buildSteps(this.flow.calls);
            this.index = -1;
            this.playing = false;
            // Current playback number: an interrupted playback loop no longer touches the state.
            this.run = 0;
            this.speed = parseFloat(root.querySelector('[data-action="speed"]').value) || 0.5;
            this.breakpointHit = null;
            this.breakpoints = new window.TimelineGraph.Breakpoints();
            this.banner = root.querySelector('[data-role="banner"]');
            this.playButton = root.querySelector('[data-action="play"]');
            this.scrub = root.querySelector('[data-action="scrub"]');
            this.view = new window.TimelineGraph.FlowView(root.querySelector('[data-role="canvas"]'), this.flow, this.breakpoints, (id) => this.toggleBreakpoint(id));
        }

        async init() {
            try {
                await this.view.render();
            } catch (error) {
                this.root.querySelector('[data-role="canvas"]').textContent = 'Unable to lay out the graph: ' + error.message;

                return;
            }
            this.scrub.max = String(this.steps.length);
            this.bindControls();
            this.update();
        }

        bindControls() {
            this.root.querySelectorAll('button[data-action]').forEach((button) => {
                button.addEventListener('click', () => {
                    button.blur();
                    ({
                        restart: () => this.restart(),
                        back: () => this.stepBack(),
                        play: () => this.toggle(),
                        forward: () => this.stepForward(),
                    })[button.dataset.action]();
                });
            });
            this.root.querySelector('[data-action="speed"]').addEventListener('change', (event) => {
                this.speed = parseFloat(event.target.value) || 0.5;
            });
            this.scrub.addEventListener('input', () => {
                this.pause();
                this.seek(parseInt(this.scrub.value, 10) - 1);
            });

            document.addEventListener('keydown', (event) => {
                // Only when the panel is shown and focus is not in a form field.
                if (this.root.offsetParent === null || event.ctrlKey || event.metaKey || event.altKey || ['INPUT', 'SELECT', 'TEXTAREA'].includes(event.target.tagName)) {
                    return;
                }
                if (event.key === ' ') {
                    // Space on a focused button already clicks it.
                    if (event.target.tagName === 'BUTTON') {
                        return;
                    }
                    event.preventDefault();
                    this.toggle();
                } else if (event.key === 'ArrowRight') {
                    event.preventDefault();
                    this.stepForward();
                } else if (event.key === 'ArrowLeft') {
                    event.preventDefault();
                    this.stepBack();
                }
            });
        }

        toggle() {
            if (this.playing) {
                this.pause();
            } else {
                this.play();
            }
        }

        async play() {
            if (this.steps.length === 0) {
                return;
            }
            if (this.index >= this.steps.length - 1) {
                this.seek(-1);
            }
            const run = ++this.run;
            this.playing = true;
            this.breakpointHit = null;
            this.update();

            while (this.playing && this.index < this.steps.length - 1) {
                const done = await this.animate(this.index + 1);
                if (run !== this.run) {
                    return;
                }
                if (!done || !this.playing) {
                    break;
                }
                this.index++;
                const step = this.steps[this.index];
                if (step.kind === 'enter' && this.breakpoints.has(step.call.to)) {
                    this.playing = false;
                    this.breakpointHit = step.call.to;
                    this.view.flashNode(step.call.to);
                }
                this.update();
            }
            this.playing = false;
            this.update();
        }

        pause() {
            this.run++;
            this.playing = false;
            this.view.cancelAnimation();
            this.update();
        }

        async stepForward() {
            if (this.index >= this.steps.length - 1) {
                return;
            }
            this.pause();
            this.breakpointHit = null;
            // The step is taken right away: fast key presses move on without waiting for the animation.
            const target = this.index + 1;
            this.index = target;
            this.update();
            if (await this.animate(target)) {
                this.update();
            }
        }

        stepBack() {
            this.pause();
            this.seek(this.index - 1);
        }

        restart() {
            this.pause();
            this.seek(-1);
            this.play();
        }

        seek(index) {
            this.view.cancelAnimation();
            this.breakpointHit = null;
            this.index = Math.max(-1, Math.min(index, this.steps.length - 1));
            this.update();
        }

        animate(index) {
            const step = this.steps[index];
            const previous = index > 0 ? this.steps[index - 1].time : 0;
            const gap = Math.max(0, step.time - previous);
            const duration = Math.min(MAX_STEP_MS, Math.max(MIN_STEP_MS, gap * TIME_SCALE)) / this.speed;
            const call = step.call;
            this.view.setState(this.state(index, true));
            this.view.reveal(step.kind === 'enter' ? call.to : call.from);

            return this.view.animateToken(call.from, call.to, step.kind === 'leave', duration, call.error !== null && step.kind === 'leave');
        }

        toggleBreakpoint(id) {
            this.view.setBreakpoint(id, this.breakpoints.toggle(id));
        }

        /** Graph state after step index (or during its animation if moving). */
        state(index, moving = false) {
            const visitedNodes = new Set();
            const visitedEdges = new Set();
            const stack = [];
            const last = moving ? index - 1 : index;
            for (let i = 0; i <= last; i++) {
                const step = this.steps[i];
                if (step.kind === 'enter') {
                    visitedNodes.add(step.call.from).add(step.call.to);
                    visitedEdges.add(window.TimelineGraph.FlowView.edgeKey(step.call.from, step.call.to));
                    stack.push(step.call);
                } else {
                    stack.pop();
                }
            }

            const current = index >= 0 ? this.steps[index] : null;
            const activeNodes = new Set(stack.map((call) => call.to));
            if (index >= 0) {
                activeNodes.add('request');
                visitedNodes.add('request');
            }
            if (current && moving) {
                visitedNodes.add(current.call.from).add(current.call.to);
                visitedEdges.add(window.TimelineGraph.FlowView.edgeKey(current.call.from, current.call.to));
            }

            return {
                started: index >= 0,
                visitedNodes,
                visitedEdges,
                activeNodes,
                currentNode: current ? (current.kind === 'enter' ? current.call.to : current.call.from) : null,
                currentEdge: current ? window.TimelineGraph.FlowView.edgeKey(current.call.from, current.call.to) : null,
            };
        }

        update() {
            this.view.setState(this.state(this.index));
            this.playButton.textContent = this.playing ? '⏸︎ Pause' : '▶︎ Play';
            this.playButton.classList.toggle('is-playing', this.playing);
            this.scrub.value = String(this.index + 1);
            this.renderBanner();
        }

        label(id) {
            const node = this.nodes.get(id);

            return node ? node.label : id;
        }

        renderBanner() {
            const total = this.steps.length;
            if (this.index < 0) {
                this.banner.innerHTML = '<span class="tg-banner-step">0 / ' + total + '</span>'
                    + '<span class="tg-banner-text">Ready: ' + this.flow.calls.length + ' calls, ' + total + ' steps. Space to play, ← → to step.</span>';

                return;
            }

            const step = this.steps[this.index];
            const call = step.call;
            const enter = step.kind === 'enter';
            const from = enter ? call.from : call.to;
            const to = enter ? call.to : call.from;
            const parts = [
                '<span class="tg-banner-step">' + (this.index + 1) + ' / ' + total + '</span>',
                '<span class="tg-banner-direction ' + (enter ? 'is-enter' : 'is-leave') + '">' + (enter ? 'call' : 'return') + '</span>',
                '<span class="tg-banner-text"><b>' + escapeHtml(this.label(from)) + '</b> → <b>' + escapeHtml(this.label(to)) + '</b></span>',
                '<code>' + escapeHtml(call.method) + '()</code>',
                '<span class="tg-banner-duration">' + window.TimelineGraph.FlowView.formatMs(call.durationMs) + '</span>',
            ];
            if (call.detail) {
                parts.push('<span class="tg-banner-detail">' + escapeHtml(call.detail) + '</span>');
            }
            if (call.error && !enter) {
                parts.push('<span class="tg-banner-error">' + escapeHtml(call.error) + '</span>');
            }
            if (this.breakpointHit) {
                parts.unshift('<span class="tg-banner-breakpoint">● Breakpoint</span>');
            }
            this.banner.innerHTML = parts.join('');
        }
    }

    window.TimelineGraph = window.TimelineGraph || {};
    window.TimelineGraph.FlowPlayer = FlowPlayer;

    document.querySelectorAll('[data-timeline-graph]').forEach((root) => {
        new FlowPlayer(root).init();
    });
})();
