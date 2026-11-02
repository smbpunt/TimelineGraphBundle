/*
 * Player breakpoints, kept in localStorage by class name:
 * they also apply to the next requests.
 */
(function () {
    'use strict';

    const STORAGE_KEY = 'timeline_graph.breakpoints';

    class Breakpoints {
        constructor() {
            this.ids = new Set(this.load());
        }

        has(id) {
            return this.ids.has(id);
        }

        /** Sets or removes the breakpoint, returns true if it is set. */
        toggle(id) {
            if (this.ids.has(id)) {
                this.ids.delete(id);
            } else {
                this.ids.add(id);
            }
            this.save();

            return this.ids.has(id);
        }

        load() {
            try {
                const stored = JSON.parse(window.localStorage.getItem(STORAGE_KEY) || '[]');

                return Array.isArray(stored) ? stored.filter((id) => typeof id === 'string') : [];
            } catch (e) {
                return [];
            }
        }

        save() {
            try {
                window.localStorage.setItem(STORAGE_KEY, JSON.stringify([...this.ids]));
            } catch (e) {
                // Storage unavailable (private browsing): the breakpoint only applies to this page.
            }
        }
    }

    window.TimelineGraph = window.TimelineGraph || {};
    window.TimelineGraph.Breakpoints = Breakpoints;
})();
