/**
 * 生年月日から年齢と年代（学年）を求める。ユーザー新規登録で使う。
 * 生年月日は保存しない。ここで求めた年齢・年代だけをフォームに入れる。
 */
(function (global) {
    'use strict';

    // 年代（i_user_rank）の値
    var AGE_GROUP = { PRESCHOOL: 1, LOWER: 2, MIDDLE: 3, UPPER: 4, JUNIOR_HIGH: 5, HIGH: 6, ADULT: 7 };

    function daysInMonth(year, month) {
        return new Date(year, month, 0).getDate();
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
     * 学年の判定用の年齢（今の年度の4月1日時点の年齢）。
     * 4月1日生まれは、早生まれとして前の学年に入るため、その日に1つ年を取ったものとして扱う。
     */
    function calcSchoolAge(year, month, day, today) {
        var m = today.getMonth() + 1;
        var schoolYear = m >= 4 ? today.getFullYear() : today.getFullYear() - 1;
        var bornAfterApril1 = month > 4 || (month === 4 && day >= 2);
        return schoolYear - year - (bornAfterApril1 ? 1 : 0);
    }

    /** 学年の判定用の年齢から年代を返す（6〜7 小1〜2、8〜9 小3〜4、10〜11 小5〜6 …） */
    function ageGroupFromSchoolAge(schoolAge) {
        if (schoolAge <= 5) return AGE_GROUP.PRESCHOOL;
        if (schoolAge <= 7) return AGE_GROUP.LOWER;
        if (schoolAge <= 9) return AGE_GROUP.MIDDLE;
        if (schoolAge <= 11) return AGE_GROUP.UPPER;
        if (schoolAge <= 14) return AGE_GROUP.JUNIOR_HIGH;
        if (schoolAge <= 17) return AGE_GROUP.HIGH;
        return AGE_GROUP.ADULT;
    }

    /**
     * 生年月日から年齢と年代を求める。
     * @return {{age:number, ageGroup:number}|{error:string}}
     */
    function fromBirthDate(year, month, day, today) {
        if (!isValidDate(year, month, day)) return { error: '存在しない日付です。' };
        var birth = new Date(year, month - 1, day);
        var todayStart = new Date(today.getFullYear(), today.getMonth(), today.getDate());
        if (birth > todayStart) return { error: '未来の日付は指定できません。' };
        return {
            age: calcAge(year, month, day, today),
            ageGroup: ageGroupFromSchoolAge(calcSchoolAge(year, month, day, today)),
        };
    }

    var api = { daysInMonth: daysInMonth, isValidDate: isValidDate, calcAge: calcAge,
        calcSchoolAge: calcSchoolAge, ageGroupFromSchoolAge: ageGroupFromSchoolAge, fromBirthDate: fromBirthDate };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        global.BirthDateAge = api;
    }
})(this);
