/* global window */
(function (global) {
    function normalizeApiPayload(payload) {
        if (!payload || typeof payload !== 'object') {
            return payload;
        }
        if (!Object.prototype.hasOwnProperty.call(payload, 'ok')) {
            return payload;
        }

        var data = Object.prototype.hasOwnProperty.call(payload, 'data') ? payload.data : undefined;
        if (data && typeof data === 'object' && !Array.isArray(data)) {
            return Object.assign({ ok: payload.ok, message: payload.message }, data);
        }

        return {
            ok: payload.ok,
            message: payload.message,
            data: data
        };
    }

    global.normalizeApiPayload = normalizeApiPayload;
})(window);
