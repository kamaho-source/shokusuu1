/**
 * 生年月日から年齢と年代を求める。ユーザー新規登録で使う。
 * 生年月日は保存しない。ここで求めた年齢・年代だけをフォームに入れる。
 * 年代の境目は PHP の UserProfileOptions::AGE_GROUP_FROM_AGE（年度更新バッチと同じ）を画面から渡す。
 */
(function (global) {
    'use strict';

    function daysInMonth(year, month) {
        // new Date(2, ...) は 1902 年と解釈されるため、setFullYear で年をそのまま使う
        var d = new Date(2000, 0, 1);
        d.setFullYear(year, month, 0);
        return d.getDate();
    }

    /** 実在する日付か（2月30日などは false） */
    function isValidDate(year, month, day) {
        if (!Number.isInteger(year) || !Number.isInteger(month) || !Number.isInteger(day)) return false;
        if (year < 1900 || month < 1 || month > 12 || day < 1) return false;
        return day <= daysInMonth(year, month);
    }

    /** today 時点の満年齢 */
    function calcAge(year, month, day, today) {
        var age = today.getFullYear() - year;
        var m = today.getMonth() + 1;
        if (m < month || (m === month && today.getDate() < day)) age--;
        return age;
    }

    /**
     * 年齢から年代を返す。
     * @param {Object<string, number>} fromAge 年代が切り替わる年齢 => 年代。これより若ければ 1（3~5才）
     */
    function ageGroupForAge(age, fromAge) {
        var group = 1;
        Object.keys(fromAge).map(Number).sort(function (a, b) { return a - b; }).forEach(function (threshold) {
            if (age >= threshold) group = fromAge[threshold];
        });
        return group;
    }

    /**
     * 生年月日から年齢と年代を求める。
     * @return {{age:number, ageGroup:number}|{error:string}}
     */
    function fromBirthDate(year, month, day, today, fromAge) {
        if (year < 1900) return { error: '1900年以降の年を入力してください。' };
        if (!isValidDate(year, month, day)) return { error: '存在しない日付です。' };
        var birth = new Date(year, month - 1, day);
        var todayStart = new Date(today.getFullYear(), today.getMonth(), today.getDate());
        if (birth > todayStart) return { error: '未来の日付は指定できません。' };
        var age = calcAge(year, month, day, today);
        return { age: age, ageGroup: ageGroupForAge(age, fromAge) };
    }

    var api = { daysInMonth: daysInMonth, isValidDate: isValidDate, calcAge: calcAge,
        ageGroupForAge: ageGroupForAge, fromBirthDate: fromBirthDate };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        global.BirthDateAge = api;
    }
})(this);
