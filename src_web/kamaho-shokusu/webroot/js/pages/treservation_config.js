/**
 * 設定初期化モジュール
 * window.__TRESP からページ全体で共有するグローバル変数を設定する。
 * treservation_index.inline.js に存在した3つの重複ブロックをここに集約。
 */
(function () {
    var cfg = window.__TRESP || {};
    window.__BASE_PATH            = cfg.basePath           || window.__BASE_PATH            || '';
    window.GET_USERS_BY_ROOM_TPL  = cfg.getUsersByRoomTpl  || window.GET_USERS_BY_ROOM_TPL  || '';
    window.QUERY_DATE             = cfg.queryDate          || window.QUERY_DATE             || '';
    window.__PRIMARY_ROOM_ID      = cfg.primaryRoomId      || window.__PRIMARY_ROOM_ID      || null;
    window.__IS_STAFF             = !!cfg.isStaff;
    window.__csrfToken            = cfg.csrfToken          || window.__csrfToken            || '';
    window.SERVER_TODAY           = cfg.serverToday        || window.SERVER_TODAY           || '';
    window.TODAY                  = cfg.serverToday        || window.TODAY                  || '';
    window.__USER_INFO = {
        isStaff:   !!cfg.isStaff,
        isChild:   !!cfg.isChild,
        isAdmin:   !!cfg.isAdmin,
        userLevel: cfg.userLevel,
        roomId:    cfg.roomId,
        roomIds:   cfg.roomIds   || [],
        roomCount: cfg.roomCount || 0
    };
})();
