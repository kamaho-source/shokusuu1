/**
 * 予約画面の JS は、食事区分の表示名を window.MEAL_LABELS（サーバーの MealType::LABEL_SETS）から読む。
 * テストでは treservation_config.js を通さないため、同じ値を MealType.php から読んで用意する。
 */
const fs = require('fs');
const path = require('path');

const src = fs.readFileSync(path.join(__dirname, '../../src/Domain/ValueObject/MealType.php'), 'utf8');
const labels = {};
for (const m of src.matchAll(/self::LABEL_(\w+)\s*=>\s*\[([^\]]*)\]/g)) {
    labels[m[1].toLowerCase()] = Object.fromEntries(
        [...m[2].matchAll(/(\d+)\s*=>\s*'([^']*)'/g)].map((p) => [p[1], p[2]])
    );
}
if (!labels.full || !labels.tiny_night) {
    throw new Error('MealType::LABEL_SETS を読めませんでした');
}
window.MEAL_LABELS = labels;
