import { execFileSync } from 'node:child_process';

const CONTAINER = 'kamakura-shokusu_web_db';
const ARGS = ['-ukamakuraadm1n', '-phata220424', 'shokusu', '-N', '-B', '-e'];

/** ローカル Docker の MySQL に対して SQL を実行し、タブ区切りの行配列を返す。 */
export function sql(query) {
  const out = execFileSync('docker', ['exec', CONTAINER, 'mysql', ...ARGS, query], {
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  return out.trim() === '' ? [] : out.trim().split('\n').map((l) => l.split('\t'));
}

/** 検証対象の1行を {eat, chg, version} で返す。無ければ null。 */
export function reservationRow({ userId, date, meal, room }) {
  const rows = sql(
    `SELECT eat_flag, i_change_flag, i_version FROM t_individual_reservation_info
     WHERE i_id_user=${userId} AND d_reservation_date='${date}'
       AND i_reservation_type=${meal} AND i_id_room=${room}`
  );
  if (rows.length === 0) return null;
  const [eat, chg, version] = rows[0];
  return { eat: eat === 'NULL' ? null : Number(eat), chg: chg === 'NULL' ? null : Number(chg), version: Number(version) };
}

export function deleteReservations(date) {
  sql(`DELETE FROM t_individual_reservation_info WHERE d_reservation_date='${date}'`);
}

export function insertReservation({ userId, date, meal, room, eat, chg }) {
  sql(
    `INSERT INTO t_individual_reservation_info
      (i_id_user, d_reservation_date, i_reservation_type, i_id_room, eat_flag, i_change_flag, i_version, tenant_id, facility_id, c_create_user, dt_create)
     VALUES (${userId}, '${date}', ${meal}, ${room}, ${eat}, ${chg}, 1, 1, 1, 'e2e', NOW())`
  );
}

/** 今日からの相対日付 (Y-m-d) */
export function dateFromToday(offsetDays) {
  const [y, m, d] = sql('SELECT CURDATE()')[0][0].split('-').map(Number);
  const dt = new Date(Date.UTC(y, m - 1, d));
  dt.setUTCDate(dt.getUTCDate() + offsetDays);
  return dt.toISOString().slice(0, 10);
}
