/*
 * Flow graph: laid out by ELK (one dashed frame per feature, layers from left to right,
 * right-angled arrows), then drawn as SVG.
 */
(function () {
    'use strict';

    const SVG_NS = 'http://www.w3.org/2000/svg';
    const CARD_WIDTH = 212;
    const CARD_HEIGHT = 60;
    const CORNER = 6;

    // Line icons, viewBox 24×24.
    const ICONS = {
        request: '<path d="M3 12h11"/><path d="m10 8 4 4-4 4"/><path d="M15 4h4a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-4"/>',
        controller: '<rect x="3.5" y="3.5" width="17" height="17" rx="3"/><path d="m10 8.5 5 3.5-5 3.5z"/>',
        form: '<rect x="3.5" y="3.5" width="17" height="17" rx="2.5"/><path d="M7.5 8.5h9M7.5 12h9M7.5 15.5h5"/>',
        search: '<circle cx="10.5" cy="10.5" r="6"/><path d="m20 20-5.2-5.2"/>',
        database: '<ellipse cx="12" cy="6" rx="7" ry="3"/><path d="M5 6v12c0 1.7 3.1 3 7 3s7-1.3 7-3V6"/><path d="M5 12c0 1.7 3.1 3 7 3s7-1.3 7-3"/>',
        http: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3c2.8 3.2 2.8 14.8 0 18M12 3c-2.8 3.2-2.8 14.8 0 18"/>',
        ldap: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6.5 6.5-6.5s6.5 2.9 6.5 6.5"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14c2 .9 3.5 3 3.5 6"/>',
        template: '<path d="m8 7-5 5 5 5M16 7l5 5-5 5M13.5 4.5l-3 15"/>',
        listener: '<path d="M13 2.5 4.5 14H11l-1 7.5L18.5 10H12z"/>',
        voter: '<path d="M12 3 19 6v6c0 4.4-3 7.7-7 9-4-1.3-7-4.6-7-9V6z"/><path d="m9 12 2 2 4-4"/>',
        builder: '<rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.5"/>',
        resolver: '<path d="M5 4v6a4 4 0 0 0 4 4h10"/><path d="m15 10 4 4-4 4"/><circle cx="5" cy="4" r="1.5"/>',
        command: '<rect x="2.5" y="4" width="19" height="16" rx="2.5"/><path d="m7 9 3 3-3 3M12.5 15h4.5"/>',
        client: '<path d="M9 2.5v5M15 2.5v5"/><path d="M6.5 7.5h11v3a5.5 5.5 0 0 1-11 0z"/><path d="M12 16v5.5"/>',
        file: '<path d="M6 3h8l5 5v12a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/><path d="M14 3v5h5M8.5 13h7M8.5 16.5h5"/>',
        component: '<path d="M12 3 20 7.5v9L12 21l-8-4.5v-9z"/><path d="m4 7.5 8 4.5 8-4.5M12 12v9"/>',
    };

    // Box type → icon and tint (CSS class).
    const TYPES = {
        Request: ['request', 'request'],
        Controller: ['controller', 'blue'],
        Form: ['form', 'violet'],
        Type: ['form', 'violet'],
        Finder: ['search', 'teal'],
        Repository: ['search', 'teal'],
        Fetcher: ['search', 'teal'],
        Database: ['database', 'orange'],
        Http: ['http', 'pink'],
        Client: ['client', 'pink'],
        Ldap: ['ldap', 'brown'],
        Template: ['template', 'green'],
        Twig: ['template', 'green'],
        Component: ['template', 'green'],
        EventListener: ['listener', 'amber'],
        EventSubscriber: ['listener', 'amber'],
        Listener: ['listener', 'amber'],
        Subscriber: ['listener', 'amber'],
        MessageHandler: ['listener', 'amber'],
        Voter: ['voter', 'red'],
        Security: ['voter', 'red'],
        Builder: ['builder', 'indigo'],
        Factory: ['builder', 'indigo'],
        Resolver: ['resolver', 'indigo'],
        Command: ['command', 'slate'],
        Recorder: ['file', 'slate'],
        Exporter: ['file', 'slate'],
    };

    function el(name, attributes, parent) {
        const node = document.createElementNS(SVG_NS, name);
        for (const [key, value] of Object.entries(attributes || {})) {
            if (value !== null && value !== undefined) {
                node.setAttribute(key, String(value));
            }
        }
        if (parent) {
            parent.appendChild(node);
        }

        return node;
    }

    function edgeKey(from, to) {
        return from + '\u0000' + to;
    }

    function formatPercent(value) {
        return value.toLocaleString('en-US', {maximumFractionDigits: value < 10 ? 1 : 0}) + '%';
    }

    function formatMs(value) {
        return value.toLocaleString('en-US', {maximumFractionDigits: value < 10 ? 2 : 1}) + ' ms';
    }

    // Rough text measurement, to reserve room for arrow labels in the layout.
    function labelSize(text) {
        return {width: Math.ceil(text.length * 6.4) + 12, height: 18};
    }

    // Orthogonal path with rounded corners.
    function roundedPath(points) {
        if (points.length < 2) {
            return '';
        }
        let d = 'M' + points[0].x + ',' + points[0].y;
        for (let i = 1; i < points.length - 1; i++) {
            const prev = points[i - 1];
            const point = points[i];
            const next = points[i + 1];
            const r = Math.min(CORNER, Math.hypot(point.x - prev.x, point.y - prev.y) / 2, Math.hypot(next.x - point.x, next.y - point.y) / 2);
            const before = {x: point.x - Math.sign(point.x - prev.x) * r, y: point.y - Math.sign(point.y - prev.y) * r};
            const after = {x: point.x + Math.sign(next.x - point.x) * r, y: point.y + Math.sign(next.y - point.y) * r};
            d += ' L' + before.x + ',' + before.y + ' Q' + point.x + ',' + point.y + ' ' + after.x + ',' + after.y;
        }
        const last = points[points.length - 1];

        return d + ' L' + last.x + ',' + last.y;
    }

    class FlowView {
        /**
         * @param {HTMLElement} canvas
         * @param {object} flow serialized Flow model
         * @param {{has: function(string): boolean}} breakpoints
         * @param {function(string): void} onNodeClick
         */
        constructor(canvas, flow, breakpoints, onNodeClick) {
            this.canvas = canvas;
            this.flow = flow;
            this.breakpoints = breakpoints;
            this.onNodeClick = onNodeClick;
            this.nodesById = new Map(flow.nodes.map((node) => [node.id, node]));
            this.nodeElements = new Map();
            this.edges = this.aggregateEdges();
            this.edgeElements = new Map();
            this.animation = null;
        }

        /** One arrow per caller → callee pair, with its methods and their call count. */
        aggregateEdges() {
            const edges = new Map();
            this.flow.calls.forEach((call) => {
                const key = edgeKey(call.from, call.to);
                if (!edges.has(key)) {
                    edges.set(key, {key, id: 'e' + edges.size, from: call.from, to: call.to, methods: new Map(), error: false});
                }
                const edge = edges.get(key);
                edge.methods.set(call.method, call.repeat);
                edge.error = edge.error || call.error !== null;
            });
            edges.forEach((edge) => {
                const parts = [...edge.methods].map(([method, repeat]) => method + (repeat > 1 ? ' ×' + repeat : ''));
                edge.label = parts.length > 2 ? parts.slice(0, 2).join(', ') + ' +' + (parts.length - 2) : parts.join(', ');
            });

            return edges;
        }

        async render() {
            if (typeof window.ELK !== 'function') {
                throw new Error('elkjs not loaded from cdn.jsdelivr.net (no internet access?)');
            }
            const layout = await new window.ELK().layout(this.buildGraph());
            this.draw(layout);
        }

        buildGraph() {
            const groups = new Map();
            const topLevel = [];

            this.flow.nodes.forEach((node) => {
                const elkNode = {id: node.id, width: CARD_WIDTH, height: CARD_HEIGHT};
                if (node.group === null) {
                    topLevel.push(elkNode);

                    return;
                }
                if (!groups.has(node.group)) {
                    groups.set(node.group, {
                        id: 'group:' + node.group,
                        layoutOptions: {'elk.padding': '[top=36,left=16,bottom=16,right=16]'},
                        children: [],
                    });
                }
                groups.get(node.group).children.push(elkNode);
            });

            const edges = [...this.edges.values()].map((edge) => ({
                id: edge.id,
                sources: [edge.from],
                targets: [edge.to],
                labels: [{id: edge.id + ':label', text: edge.label, ...labelSize(edge.label)}],
            }));

            return {
                id: 'root',
                layoutOptions: {
                    'elk.algorithm': 'layered',
                    'elk.direction': 'RIGHT',
                    'elk.edgeRouting': 'ORTHOGONAL',
                    'elk.hierarchyHandling': 'INCLUDE_CHILDREN',
                    'elk.json.edgeCoords': 'ROOT',
                    'elk.json.shapeCoords': 'ROOT',
                    'elk.padding': '[top=16,left=16,bottom=16,right=16]',
                    'elk.spacing.nodeNode': '26',
                    'elk.spacing.edgeNode': '18',
                    'elk.spacing.edgeEdge': '12',
                    'elk.spacing.edgeLabel': '4',
                    'elk.layered.spacing.nodeNodeBetweenLayers': '64',
                    'elk.layered.spacing.edgeNodeBetweenLayers': '22',
                    'elk.layered.considerModelOrder.strategy': 'NODES_AND_EDGES',
                    'elk.edgeLabels.placement': 'CENTER',
                },
                children: [...topLevel.slice(0, 1), ...groups.values(), ...topLevel.slice(1)],
                edges,
            };
        }

        draw(layout) {
            this.canvas.textContent = '';
            const width = Math.ceil(layout.width);
            const height = Math.ceil(layout.height);
            const svg = el('svg', {
                class: 'timeline-graph-svg',
                viewBox: '0 0 ' + width + ' ' + height,
                width,
                height,
                role: 'img',
                'aria-label': 'Request flow graph',
            }, this.canvas);
            this.svg = svg;

            const defs = el('defs', {}, svg);
            [['arrow', 'tg-arrow'], ['arrow-error', 'tg-arrow tg-arrow-error'], ['arrow-current', 'tg-arrow tg-arrow-current']].forEach(([id, className]) => {
                const marker = el('marker', {id: 'timeline-graph-' + id, viewBox: '0 0 10 10', refX: 9, refY: 5, markerWidth: 7, markerHeight: 7, orient: 'auto-start-reverse'}, defs);
                el('path', {d: 'M0,1 L9,5 L0,9 z', class: className}, marker);
            });

            const groupLayer = el('g', {class: 'tg-groups'}, svg);
            const edgeLayer = el('g', {class: 'tg-edges'}, svg);
            const nodeLayer = el('g', {class: 'tg-nodes'}, svg);
            const labelLayer = el('g', {class: 'tg-labels'}, svg);
            this.tokenLayer = el('g', {class: 'tg-tokens'}, svg);

            (layout.children || []).forEach((child) => {
                if (child.id.startsWith('group:')) {
                    this.drawGroup(groupLayer, child);
                    (child.children || []).forEach((node) => this.drawNode(nodeLayer, node));
                } else {
                    this.drawNode(nodeLayer, child);
                }
            });

            const layoutEdges = new Map((layout.edges || []).map((edge) => [edge.id, edge]));
            this.edges.forEach((edge) => {
                const layoutEdge = layoutEdges.get(edge.id);
                if (layoutEdge) {
                    this.drawEdge(edgeLayer, labelLayer, edge, layoutEdge);
                }
            });
        }

        drawGroup(layer, group) {
            const g = el('g', {class: 'tg-group'}, layer);
            el('rect', {x: group.x, y: group.y, width: group.width, height: group.height, rx: 12, class: 'tg-group-frame'}, g);
            const title = el('text', {x: group.x + 16, y: group.y + 22, class: 'tg-group-title'}, g);
            title.textContent = group.id.slice('group:'.length);
        }

        drawNode(layer, layoutNode) {
            const node = this.nodesById.get(layoutNode.id);
            if (!node) {
                return;
            }
            const [icon, tint] = TYPES[node.type] || ['component', 'gray'];
            const g = el('g', {
                class: 'tg-node tg-tint-' + tint + (node.hasError ? ' has-error' : '') + (node.kind === 'request' ? ' is-request' : ''),
                transform: 'translate(' + layoutNode.x + ',' + layoutNode.y + ')',
                tabindex: node.kind === 'request' ? null : 0,
                'data-id': node.id,
            }, layer);

            const tooltip = el('title', {}, g);
            tooltip.textContent = [
                node.class || node.label,
                node.callCount + ' call' + (node.callCount > 1 ? 's' : '') + ' · ' + formatMs(node.timeMs),
                node.kind === 'request' ? '' : 'Click: set or remove a breakpoint',
            ].filter(Boolean).join('\n');

            el('rect', {width: CARD_WIDTH, height: CARD_HEIGHT, rx: 10, class: 'tg-card'}, g);
            el('rect', {x: 12, y: 14, width: 32, height: 32, rx: 8, class: 'tg-icon-tile'}, g);
            const iconGroup = el('g', {transform: 'translate(18,20) scale(0.8333)', class: 'tg-icon'}, g);
            iconGroup.innerHTML = ICONS[icon];

            const title = el('text', {x: 54, y: 27, class: 'tg-node-title'}, g);
            title.textContent = node.label;
            const subtitle = el('text', {x: 54, y: 44, class: 'tg-node-subtitle'}, g);
            subtitle.textContent = node.kind === 'request' ? node.detail : node.type + ' · ' + formatPercent(node.share);

            // Share of the request time.
            el('rect', {x: 10, y: CARD_HEIGHT - 4, width: CARD_WIDTH - 20, height: 2, rx: 1, class: 'tg-share-track'}, g);
            el('rect', {x: 10, y: CARD_HEIGHT - 4, width: Math.max(2, (CARD_WIDTH - 20) * Math.min(node.share, 100) / 100), height: 2, rx: 1, class: 'tg-share'}, g);

            el('circle', {cx: CARD_WIDTH - 10, cy: 10, r: 5, class: 'tg-breakpoint'}, g);

            this.nodeElements.set(node.id, g);
            this.fitText(title, CARD_WIDTH - 64);
            this.fitText(subtitle, CARD_WIDTH - 64);
            this.setBreakpoint(node.id, this.breakpoints.has(node.id));

            if (node.kind !== 'request') {
                g.addEventListener('click', () => this.onNodeClick(node.id));
                g.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter') {
                        this.onNodeClick(node.id);
                    }
                });
            }
        }

        drawEdge(edgeLayer, labelLayer, edge, layoutEdge) {
            const section = (layoutEdge.sections || [])[0];
            if (!section) {
                return;
            }
            const points = [section.startPoint, ...(section.bendPoints || []), section.endPoint];
            const g = el('g', {class: 'tg-edge' + (edge.error ? ' has-error' : ''), 'data-key': edge.key}, edgeLayer);
            const path = el('path', {d: roundedPath(points), class: 'tg-edge-path', 'marker-end': 'url(#timeline-graph-' + (edge.error ? 'arrow-error' : 'arrow') + ')'}, g);

            let label = null;
            const layoutLabel = (layoutEdge.labels || [])[0];
            if (layoutLabel && layoutLabel.x !== undefined) {
                label = el('g', {class: 'tg-edge-label' + (edge.error ? ' has-error' : ''), transform: 'translate(' + layoutLabel.x + ',' + layoutLabel.y + ')'}, labelLayer);
                el('rect', {width: layoutLabel.width, height: layoutLabel.height, rx: 9, class: 'tg-edge-label-bg'}, label);
                const text = el('text', {x: layoutLabel.width / 2, y: layoutLabel.height / 2 + 4, 'text-anchor': 'middle'}, label);
                text.textContent = edge.label;
            }

            this.edgeElements.set(edge.key, {group: g, path, label, error: edge.error});
        }

        fitText(text, maxWidth) {
            const full = text.textContent;
            if (typeof text.getComputedTextLength !== 'function' || text.getComputedTextLength() === 0) {
                return;
            }
            let value = full;
            while (value.length > 4 && text.getComputedTextLength() > maxWidth) {
                value = value.slice(0, -1);
                text.textContent = value + '…';
            }
        }

        setBreakpoint(id, enabled) {
            const g = this.nodeElements.get(id);
            if (g) {
                g.classList.toggle('has-breakpoint', enabled);
            }
        }

        /** Scrolls the graph area to the box, if it is not visible. */
        reveal(id) {
            const g = this.nodeElements.get(id);
            if (!g) {
                return;
            }
            const box = g.getBoundingClientRect();
            const view = this.canvas.getBoundingClientRect();
            const margin = 40;
            if (box.left < view.left + margin || box.right > view.right - margin) {
                this.canvas.scrollBy({left: box.left - view.left - view.width / 2 + box.width / 2, behavior: 'smooth'});
            }
            if (box.top < view.top + margin || box.bottom > view.bottom - margin) {
                this.canvas.scrollBy({top: box.top - view.top - view.height / 2 + box.height / 2, behavior: 'smooth'});
            }
        }

        flashNode(id) {
            const g = this.nodeElements.get(id);
            if (!g) {
                return;
            }
            g.classList.remove('is-flash');
            // Restarts the CSS animation.
            void g.getBoundingClientRect();
            g.classList.add('is-flash');
        }

        /**
         * Player state: boxes and arrows already visited, calls in progress, current arrow.
         *
         * @param {{started: boolean, visitedNodes: Set<string>, visitedEdges: Set<string>, activeNodes: Set<string>, currentNode: ?string, currentEdge: ?string}} state
         */
        setState(state) {
            this.svg.classList.toggle('is-started', state.started);
            this.nodeElements.forEach((g, id) => {
                g.classList.toggle('is-visited', state.visitedNodes.has(id));
                g.classList.toggle('is-active', state.activeNodes.has(id));
                g.classList.toggle('is-current', state.currentNode === id);
            });
            this.edgeElements.forEach((edge, key) => {
                const current = state.currentEdge === key;
                edge.group.classList.toggle('is-visited', state.visitedEdges.has(key));
                edge.group.classList.toggle('is-current', current);
                if (edge.label) {
                    edge.label.classList.toggle('is-visited', state.visitedEdges.has(key));
                    edge.label.classList.toggle('is-current', current);
                }
                edge.path.setAttribute('marker-end', 'url(#timeline-graph-' + (current ? 'arrow-current' : (edge.error ? 'arrow-error' : 'arrow')) + ')');
            });
        }

        /**
         * Slides a token along the arrow: from caller to callee on the call, the other way on the return.
         * The promise resolves at the end of the path, or right away if the animation is cancelled.
         */
        animateToken(from, to, reverse, duration, error) {
            this.cancelAnimation();
            const edge = this.edgeElements.get(edgeKey(from, to));
            if (!edge) {
                return Promise.resolve(true);
            }
            const length = edge.path.getTotalLength();
            const token = el('circle', {r: 6, class: 'tg-token' + (error ? ' has-error' : '')}, this.tokenLayer);

            return new Promise((resolve) => {
                const start = performance.now();
                const animation = {cancelled: false, frame: 0, resolve, token};
                this.animation = animation;
                const tick = (now) => {
                    if (animation.cancelled) {
                        return;
                    }
                    const progress = Math.min(1, (now - start) / duration);
                    const eased = progress < 0.5 ? 4 * progress ** 3 : 1 - (-2 * progress + 2) ** 3 / 2;
                    const point = edge.path.getPointAtLength(length * (reverse ? 1 - eased : eased));
                    token.setAttribute('cx', point.x);
                    token.setAttribute('cy', point.y);
                    if (progress < 1) {
                        animation.frame = requestAnimationFrame(tick);
                    } else {
                        token.remove();
                        this.animation = null;
                        resolve(true);
                    }
                };
                animation.frame = requestAnimationFrame(tick);
            });
        }

        cancelAnimation() {
            if (!this.animation) {
                return;
            }
            const animation = this.animation;
            this.animation = null;
            animation.cancelled = true;
            cancelAnimationFrame(animation.frame);
            animation.token.remove();
            animation.resolve(false);
        }
    }

    FlowView.edgeKey = edgeKey;
    FlowView.formatMs = formatMs;

    window.TimelineGraph = window.TimelineGraph || {};
    window.TimelineGraph.FlowView = FlowView;
})();
