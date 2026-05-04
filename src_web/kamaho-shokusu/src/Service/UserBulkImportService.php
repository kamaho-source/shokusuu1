<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;

/**
 * ユーザー一括インポートサービス
 *
 * importJson エンドポイント向けに、JSONレコード配列を受け取り
 * バリデーション・正規化・保存を一括処理する。
 */
class UserBulkImportService
{
    /**
     * @param array  $records     クライアントから送られてきたレコード配列
     * @param string $createUser  作成者ユーザー名
     * @return array{processed: int, created: int, skipped: int, failed: int, errors: array}
     */
    public function import(array $records, string $createUser): array
    {
        $userInfoTable  = TableRegistry::getTableLocator()->get('MUserInfo');
        $userGroupTable = TableRegistry::getTableLocator()->get('MUserGroup');
        $roomInfoTable  = TableRegistry::getTableLocator()->get('MRoomInfo');

        $maxDispNoRow = $userInfoTable->find()
            ->select(['max_no' => $userInfoTable->find()->func()->max('i_disp_no')])
            ->first();
        $nextDispNo = ($maxDispNoRow && $maxDispNoRow->max_no !== null)
            ? ((int)$maxDispNoRow->max_no + 1)
            : 1;

        $results = [
            'processed' => 0,
            'created'   => 0,
            'skipped'   => 0,
            'failed'    => 0,
            'errors'    => [],
        ];

        $conn = $userInfoTable->getConnection();
        $conn->begin();

        try {
            foreach ($records as $rec) {
                $rowNo         = (int)($rec['_row'] ?? 0);
                $loginId       = trim((string)($rec['login_id'] ?? ''));
                $name          = trim((string)($rec['name'] ?? ''));
                $roleRaw       = (string)($rec['role'] ?? '');
                $passRaw       = (string)($rec['password'] ?? '');
                $staffId       = trim((string)($rec['staff_id'] ?? ''));
                $ageRaw        = (string)($rec['age'] ?? '');
                $genderInput   = (string)($rec['i_user_gender'] ?? ($rec['gender'] ?? ''));
                $ageGroupInput = (string)($rec['age_group'] ?? '');
                $roomName1     = trim((string)($rec['room_name1'] ?? ''));
                $roomName2     = trim((string)($rec['room_name2'] ?? ''));

                if ($loginId === '' || $name === '' || $roleRaw === '') {
                    $results['failed']++;
                    $results['processed']++;
                    $results['errors'][$rowNo][] = '必須項目（login_id, name, role）のいずれかが空です。';
                    continue;
                }

                if ($userInfoTable->exists(['c_login_account' => $loginId])) {
                    $results['skipped']++;
                    $results['processed']++;
                    $results['errors'][$rowNo][] = 'c_login_account が既に存在します。';
                    continue;
                }

                $level = $this->normalizeRole($roleRaw);
                if ($level === null) {
                    $results['failed']++;
                    $results['processed']++;
                    $results['errors'][$rowNo][] = 'role の値が不正です（職員/児童/その他 または 0/1/3 を指定してください）。';
                    continue;
                }

                if ($level === 0 && $staffId === '') {
                    $results['failed']++;
                    $results['processed']++;
                    $results['errors'][$rowNo][] = 'role=職員 のため staff_id が必須です。';
                    continue;
                }

                if ($passRaw === '') {
                    $passRaw = bin2hex(random_bytes(6));
                }

                $ageVal = null;
                if ($ageRaw !== '') {
                    $ageInt = (int)$ageRaw;
                    if ($ageInt > 0) {
                        $ageVal = $ageInt;
                    }
                }

                $genderVal = null;
                if ($genderInput !== '') {
                    $g = mb_strtolower(trim($genderInput), 'UTF-8');
                    if (is_numeric($g)) {
                        $gi = (int)$g;
                        if (in_array($gi, [1, 2], true)) {
                            $genderVal = $gi;
                        }
                    } else {
                        if (in_array($g, ['1', '男', '男性', 'male', 'm'], true)) {
                            $genderVal = 1;
                        }
                        if (in_array($g, ['2', '女', '女性', 'female', 'f'], true)) {
                            $genderVal = 2;
                        }
                    }
                }

                $ageGroupCode = $ageGroupInput !== '' ? $this->normalizeAgeGroup($ageGroupInput) : null;

                $newData = [
                    'c_login_account' => $loginId,
                    'c_user_name'     => $name,
                    'i_user_level'    => $level,
                    'c_login_passwd'  => $passRaw,
                    'i_del_flag'      => 0,
                    'i_enable'        => 0,
                    'i_disp_no'       => $nextDispNo++,
                    'dt_create'       => date('Y-m-d H:i:s'),
                    'c_create_user'   => $createUser,
                ];
                if ($ageVal !== null) {
                    $newData['i_user_age'] = $ageVal;
                }
                if ($genderVal !== null) {
                    $newData['i_user_gender'] = $genderVal;
                }
                if ($ageGroupCode !== null) {
                    $newData['i_user_rank'] = $ageGroupCode;
                }
                if ($level === 0) {
                    $newData['i_id_staff'] = $staffId;
                }

                $entity = $userInfoTable->newEntity($newData);

                if ($entity->getErrors()) {
                    $results['failed']++;
                    foreach ($entity->getErrors() as $field => $msgs) {
                        foreach ($msgs as $msg) {
                            $results['errors'][$rowNo][] = "{$field}: {$msg}";
                        }
                    }
                    $results['processed']++;
                    continue;
                }

                if ($userInfoTable->save($entity)) {
                    $results['created']++;
                    $userId    = $entity->i_id_user;
                    $roomNames = array_filter([$roomName1, $roomName2], fn($n) => $n !== '');

                    foreach ($roomNames as $roomName) {
                        $room = $roomInfoTable->find()->where(['c_room_name' => $roomName])->first();
                        if ($room && $userId) {
                            $userGroup = $userGroupTable->newEntity([
                                'i_id_user'     => $userId,
                                'i_id_room'     => $room->i_id_room,
                                'active_flag'   => 0,
                                'dt_create'     => date('Y-m-d H:i:s'),
                                'c_create_user' => $createUser,
                            ]);
                            $userGroupTable->save($userGroup);
                        } else {
                            $results['errors'][$rowNo][] = "部屋名 '{$roomName}' が見つかりません";
                        }
                    }
                } else {
                    $results['failed']++;
                    $results['errors'][$rowNo][] = '保存に失敗しました。';
                }

                $results['processed']++;
            }

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        return $results;
    }

    /**
     * 役割名 → i_user_level（0:職員, 1:児童, 3:その他）へ正規化
     */
    private function normalizeRole(string $raw): ?int
    {
        $v = trim($raw);
        if ($v === '') {
            return null;
        }
        if (function_exists('mb_convert_kana')) {
            $v = mb_convert_kana($v, 'nas', 'UTF-8');
        }
        $vLower = mb_strtolower($v, 'UTF-8');

        if (is_numeric($vLower)) {
            $n = (int)$vLower;
            return in_array($n, [0, 1, 3], true) ? $n : null;
        }

        $exactMap = [
            'staff' => 0, '職員' => 0, 'スタッフ' => 0, '教職員' => 0,
            'child' => 1, 'user' => 1, '利用者' => 1, '児童' => 1,
            'こども' => 1, '子ども' => 1, '子供' => 1, '生徒' => 1, 'ユーザー' => 1,
            'other' => 3, 'その他' => 3, '外部' => 3, 'ゲスト' => 3, '臨時' => 3, 'ボランティア' => 3,
        ];
        if (array_key_exists($vLower, $exactMap)) {
            return $exactMap[$vLower];
        }

        $containsAny = function (string $haystack, array $needles): bool {
            foreach ($needles as $needle) {
                if ($needle !== '' && mb_strpos($haystack, $needle, 0, 'UTF-8') !== false) {
                    return true;
                }
            }
            return false;
        };

        if ($containsAny($vLower, ['職員', 'スタッフ', '教職員', 'staff'])) return 0;
        if ($containsAny($vLower, ['児童', '子ども', '子供', 'こども', '生徒', '利用者', 'ユーザー', 'child', 'user'])) return 1;
        if ($containsAny($vLower, ['その他', '外部', 'ゲスト', '臨時', 'other'])) return 3;

        return null;
    }

    /**
     * 年代（age_group）表記 → コード（1..7）へ正規化
     * 1:3~5才, 2:低学年, 3:中学年, 4:高学年, 5:中学生, 6:高校生, 7:大人
     */
    private function normalizeAgeGroup(string $raw): ?int
    {
        $v = trim($raw);
        if ($v === '') {
            return null;
        }

        if (is_numeric($v)) {
            $n = (int)$v;
            return ($n >= 1 && $n <= 7) ? $n : null;
        }

        if (function_exists('mb_convert_kana')) {
            $v = mb_convert_kana($v, 'as', 'UTF-8');
        }
        $v      = str_replace(['歳', '才', '　'], ['', '', ''], $v);
        $vLower = mb_strtolower($v, 'UTF-8');

        $pairs = [
            ['3~5', 1], ['3-5', 1], ['3〜5', 1], ['3～5', 1],
            ['低学年', 2],
            ['中学年', 3],
            ['高学年', 4],
            ['中学生', 5],
            ['高校生', 6],
            ['大人',   7], ['成人', 7],
        ];
        foreach ($pairs as [$key, $code]) {
            if (mb_strpos($vLower, $key, 0, 'UTF-8') !== false) {
                return $code;
            }
        }

        return null;
    }
}
