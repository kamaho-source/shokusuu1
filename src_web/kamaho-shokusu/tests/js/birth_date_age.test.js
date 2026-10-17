'use strict';

const B = require('../../webroot/js/birth-date-age.js');

const day = (y, m, d) => new Date(y, m - 1, d);

describe('birth-date-age.js', () => {
    describe('fromBirthDate: 年代は4月2日区切りの学年で決まる', () => {
        const today = day(2026, 10, 17);

        test.each([
            // [生年月日, 学年, 年代]
            [[2018, 4, 1], '小3', 3],   // 早生まれ扱い → 中学年
            [[2018, 4, 2], '小2', 2],   // 低学年
            [[2019, 4, 2], '小1', 2],
            [[2019, 4, 1], '小2', 2],
            [[2020, 4, 2], '年長', 1],  // 3~5才
            [[2015, 4, 2], '小5', 4],   // 高学年
            [[2013, 4, 2], '中1', 5],
            [[2010, 4, 2], '高1', 6],
            [[2008, 4, 2], '高3', 6],
            [[2008, 4, 1], '高3の次', 7], // 大人
        ])('%j は %s → 年代 %i', ([y, m, d], _grade, group) => {
            expect(B.fromBirthDate(y, m, d, today).ageGroup).toBe(group);
        });
    });

    test('年度の境目（3月31日と4月1日）で学年が1つ上がる', () => {
        // 2019-04-02 生まれ: 2026年3月は年長（3~5才）、2026年4月から小1（低学年）
        expect(B.fromBirthDate(2019, 4, 2, day(2026, 3, 31)).ageGroup).toBe(1);
        expect(B.fromBirthDate(2019, 4, 2, day(2026, 4, 1)).ageGroup).toBe(2);
    });

    test('年齢は今日時点の満年齢（誕生日の前日・当日）', () => {
        expect(B.fromBirthDate(2010, 10, 18, day(2026, 10, 17)).age).toBe(15);
        expect(B.fromBirthDate(2010, 10, 17, day(2026, 10, 17)).age).toBe(16);
    });

    test('うるう日生まれ', () => {
        expect(B.isValidDate(2020, 2, 29)).toBe(true);
        expect(B.isValidDate(2021, 2, 29)).toBe(false);
        expect(B.fromBirthDate(2020, 2, 29, day(2026, 2, 28)).age).toBe(5);
        expect(B.fromBirthDate(2020, 2, 29, day(2026, 3, 1)).age).toBe(6);
    });

    test('存在しない日付・未来の日付はエラー', () => {
        expect(B.fromBirthDate(2020, 2, 30, day(2026, 10, 17)).error).toBeDefined();
        expect(B.fromBirthDate(2020, 13, 1, day(2026, 10, 17)).error).toBeDefined();
        expect(B.fromBirthDate(2026, 10, 18, day(2026, 10, 17)).error).toBeDefined();
        expect(B.fromBirthDate(2026, 10, 17, day(2026, 10, 17)).error).toBeUndefined();
    });

    test('月の日数（日の選択肢の末日に使う）', () => {
        expect(B.daysInMonth(2024, 2)).toBe(29);
        expect(B.daysInMonth(2026, 2)).toBe(28);
        expect(B.daysInMonth(2026, 4)).toBe(30);
        expect(B.daysInMonth(2026, 12)).toBe(31);
    });
});
