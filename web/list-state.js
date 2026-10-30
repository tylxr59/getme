// Serializes user writes and exposes guards for refreshing the optimistic view.
(function (root) {
    class ListState {
        constructor(onSettled) {
            this.writeQueue = Promise.resolve();
            this.pendingWrites = 0;
            this.localRevision = 0;
            this.onSettled = onSettled;
        }
        enqueue(work) {
            this.localRevision++;
            this.pendingWrites++;
            const result = this.writeQueue.then(work);
            this.writeQueue = result.catch(() => {});
            const settled = () => { this.pendingWrites--; this.onSettled(); };
            result.then(settled, settled);
            return result;
        }
    }
    root.ListState = ListState;
    if (typeof module !== 'undefined') module.exports = ListState;
})(typeof window === 'undefined' ? globalThis : window);
