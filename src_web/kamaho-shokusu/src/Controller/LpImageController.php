<?php
declare(strict_types=1);

namespace App\Controller;

use App\Model\Table\MLpImageTable;
use Authorization\Exception\ForbiddenException;
use Cake\Event\EventInterface;
use Cake\Http\Response;
use Psr\Http\Message\UploadedFileInterface;

/**
 * LP画像管理コントローラー
 *
 * 管理者（i_admin=1 または i_admin=3）専用。
 * LP（ランディングページ）に掲載する画像のアップロード・表示切替・削除を提供する。
 * アップロードした画像ファイルは webroot/img/lp/uploads/ に保存し、
 * メタ情報を m_lp_image テーブルで管理する。
 */
class LpImageController extends AppController
{
    /** アップロードを許可する MIME タイプと拡張子の対応 */
    private const ALLOWED_MIME_TYPES = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    /** アップロード上限サイズ（5MB） */
    private const MAX_FILE_SIZE = 5 * 1024 * 1024;

    /**
     * FormProtection::startup() より前に実行されるため、ここで unlockedFields を設定する。
     * ファイル入力・select はフォーム改ざんチェックの対象から外す。
     */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->FormProtection->setConfig('unlockedFields', ['c_title', 'c_section', 'i_sort', 'image_file']);
    }

    /**
     * LP画像一覧・アップロードフォーム
     */
    public function index(): ?Response
    {
        $table    = $this->fetchTable('MLpImage');
        $resource = $table->newEmptyEntity();

        try {
            $this->Authorization->authorize($resource, 'index');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('この機能は管理者のみ利用できます。'));
            return $this->redirect(['controller' => 'Pages', 'action' => 'dashboard']);
        }

        $images = $table->find()
            ->orderByAsc('c_section')
            ->orderByAsc('i_sort')
            ->orderByAsc('i_id')
            ->all();

        $sections = MLpImageTable::SECTIONS;

        $this->set(compact('images', 'sections'));
        return null;
    }

    /**
     * LP画像アップロード
     *
     * @throws \Cake\Http\Exception\MethodNotAllowedException POST以外のリクエスト時
     */
    public function add(): ?Response
    {
        $this->request->allowMethod(['post']);

        $table    = $this->fetchTable('MLpImage');
        $resource = $table->newEmptyEntity();

        try {
            $this->Authorization->authorize($resource, 'add');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('この機能は管理者のみ利用できます。'));
            return $this->redirect(['controller' => 'Pages', 'action' => 'dashboard']);
        }

        $file = $this->request->getData('image_file');
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            $this->Flash->error('画像ファイルを選択してください。');
            return $this->redirect(['action' => 'index']);
        }

        if ((int)$file->getSize() > self::MAX_FILE_SIZE) {
            $this->Flash->error('画像サイズは5MB以下にしてください。');
            return $this->redirect(['action' => 'index']);
        }

        // クライアント申告のMIMEではなく実ファイルの内容から判定する
        $tmpPath = (string)$file->getStream()->getMetadata('uri');
        $mime    = (string)(new \finfo(FILEINFO_MIME_TYPE))->file($tmpPath);
        if (!isset(self::ALLOWED_MIME_TYPES[$mime])) {
            $this->Flash->error('PNG・JPEG・WebP・GIF形式の画像のみアップロードできます。');
            return $this->redirect(['action' => 'index']);
        }

        // 推測されにくいランダムなファイル名で保存する
        $fileName = sprintf('lp_%s_%s.%s', date('YmdHis'), bin2hex(random_bytes(4)), self::ALLOWED_MIME_TYPES[$mime]);
        $saveDir  = WWW_ROOT . 'img' . DS . 'lp' . DS . 'uploads';
        if (!is_dir($saveDir)) {
            mkdir($saveDir, 0755, true);
        }
        $file->moveTo($saveDir . DS . $fileName);

        $entity = $table->newEntity([
            'c_title'     => trim((string)$this->request->getData('c_title')),
            'c_section'   => (string)$this->request->getData('c_section', 'gallery'),
            'c_file_path' => 'img/lp/uploads/' . $fileName,
            'i_display'   => 1,
            'i_sort'      => (int)$this->request->getData('i_sort', 0),
        ]);

        if (!$table->save($entity)) {
            // 保存に失敗した場合はアップロード済みファイルを残さない
            @unlink($saveDir . DS . $fileName);
            $errors = $entity->getErrors();
            $message = $errors ? implode(' ', array_map(fn($e) => implode(' ', (array)$e), $errors)) : '保存に失敗しました。';
            $this->Flash->error($message);
            return $this->redirect(['action' => 'index']);
        }

        $this->Flash->success('LP画像を追加しました。');
        return $this->redirect(['action' => 'index']);
    }

    /**
     * LP表示のON/OFF切替
     *
     * @throws \Cake\Http\Exception\MethodNotAllowedException POST以外のリクエスト時
     * @throws \Cake\Datasource\Exception\RecordNotFoundException 対象画像が存在しない場合
     */
    public function toggle(int $id): ?Response
    {
        $this->request->allowMethod(['post']);

        $table = $this->fetchTable('MLpImage');
        /** @var \App\Model\Entity\MLpImage $image */
        $image = $table->get($id);

        try {
            $this->Authorization->authorize($image, 'edit');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('この機能は管理者のみ利用できます。'));
            return $this->redirect(['controller' => 'Pages', 'action' => 'dashboard']);
        }

        $image->i_display = $image->i_display === 1 ? 0 : 1;

        if (!$table->save($image)) {
            $this->Flash->error('表示状態の変更に失敗しました。');
            return $this->redirect(['action' => 'index']);
        }

        $this->Flash->success($image->i_display === 1 ? 'LPに表示するよう変更しました。' : 'LPに表示しないよう変更しました。');
        return $this->redirect(['action' => 'index']);
    }

    /**
     * LP画像の削除（レコードとファイルの両方を削除する）
     *
     * @throws \Cake\Http\Exception\MethodNotAllowedException POST/DELETE以外のリクエスト時
     * @throws \Cake\Datasource\Exception\RecordNotFoundException 対象画像が存在しない場合
     */
    public function delete(int $id): ?Response
    {
        $this->request->allowMethod(['post', 'delete']);

        $table = $this->fetchTable('MLpImage');
        /** @var \App\Model\Entity\MLpImage $image */
        $image = $table->get($id);

        try {
            $this->Authorization->authorize($image, 'delete');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('この機能は管理者のみ利用できます。'));
            return $this->redirect(['controller' => 'Pages', 'action' => 'dashboard']);
        }

        if (!$table->delete($image)) {
            $this->Flash->error('削除に失敗しました。');
            return $this->redirect(['action' => 'index']);
        }

        // アップロードディレクトリ配下のファイルのみ削除する（パス改ざん対策）
        $uploadsDir = realpath(WWW_ROOT . 'img' . DS . 'lp' . DS . 'uploads');
        $filePath   = realpath(WWW_ROOT . $image->c_file_path);
        if ($uploadsDir !== false && $filePath !== false && str_starts_with($filePath, $uploadsDir . DS)) {
            @unlink($filePath);
        }

        $this->Flash->success('LP画像を削除しました。');
        return $this->redirect(['action' => 'index']);
    }
}
