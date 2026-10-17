'use strict';

const B = require('../../webroot/js/birth-date-age.js');

const day = (y, m, d) => new Date(y, m - 1, d);
// UserProfileOptions::AGE_GROUP_FROM_AGE と同じ表（年度更新バッチと同じ境目）
const FROM_AGE = { 7: 2, 10: 3, 12: 4, 13: 5, 16: 6, 19: 7 };

describe('birth-date-age.js', () => {
    test.each([
        [1, 1], [6, 1],          // 3~5才
        [7, 2], [9, 2],          // 低学年
        [10, 3], [11, 3],        // 中学年
        [12, 4],                 // 高学年
        [13, 5], [15, 5],        // 中学生
        [16, 6], [18, 6],        // 高校生
        [19, 7], [80, 7],        // 大人
    ])('年齢 %i → 年代 %i（年度更新バッチと同じ境目）', (age, group) => {
        expect(B.ageGroupForAge(age, FROM_AGE)).toBe(group);
    });

    test('生年月日から満年齢と、その年齢の年代を選ぶ', () => {
        const today = day(2026, 10, 17);
        expect(B.fromBirthDate(2018, 5, 1, today, FROM_AGE)).toEqual({ age: 8, ageGroup: 2 });
        expect(B.fromBirthDate(2016, 10, 17, today, FROM_AGE)).toEqual({ age: 10, ageGroup: 3 });  // 誕生日当日
        expect(B.fromBirthDate(2016, 10, 18, today, FROM_AGE)).toEqual({ age: 9, ageGroup: 2 });   // 誕生日の前日
    });

    test('うるう日生まれ', () => {
        expect(B.isValidDate(2020, 2, 29)).toBe(true);
        expect(B.isValidDate(2021, 2, 29)).toBe(false);
        expect(B.fromBirthDate(2020, 2, 29, day(2026, 2, 28), FROM_AGE).age).toBe(5);
        expect(B.fromBirthDate(2020, 2, 29, day(2026, 3, 1), FROM_AGE).age).toBe(6);
    });

    test('存在しない日付・未来の日付・1900年より前はエラー', () => {
        const today = day(2026, 10, 17);
        expect(B.fromBirthDate(2020, 2, 30, today, FROM_AGE).error).toBeDefined();
        expect(B.fromBirthDate(2020, 13, 1, today, FROM_AGE).error).toBeDefined();
        expect(B.fromBirthDate(2026, 10, 18, today, FROM_AGE).error).toBeDefined();
        expect(B.fromBirthDate(1899, 1, 1, today, FROM_AGE).error).toMatch('1900');
        expect(B.fromBirthDate(2026, 10, 17, today, FROM_AGE).error).toBeUndefined();
    });

    test('月の日数（入力途中の短い年でも 1900 年代に読み替えない）', () => {
        expect(B.daysInMonth(2024, 2)).toBe(29);
        expect(B.daysInMonth(2026, 2)).toBe(28);
        expect(B.daysInMonth(2026, 4)).toBe(30);
        expect(B.daysInMonth(0, 2)).toBe(29);   // 西暦0年はうるう年。new Date(0, ..) だと平年の 1900 年扱いで 28 になる
    });
});
