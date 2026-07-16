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
        $this->FormProtection->setConfig('unlockedFields', ['image_file', 'image_source', 'existing_image_id']);
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
     * LP画像の追加
     *
     * 新しい画像ファイルのアップロードに加え、データベースに登録済みの画像を
     * 選択して別セクション・別タイトルで追加（再利用）できる。
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

        $useExisting = (string)$this->request->getData('image_source', 'upload') === 'existing';

        $filePath = $useExisting
            ? $this->resolveExistingImagePath($table)
            : $this->saveUploadedImage();

        if ($filePath === null) {
            return $this->redirect(['action' => 'index']);
        }

        $entity = $table->newEntity([
            'c_title'     => trim((string)$this->request->getData('c_title')),
            'c_section'   => (string)$this->request->getData('c_section', 'gallery'),
            'c_file_path' => $filePath,
            'i_display'   => 1,
            'i_sort'      => (int)$this->request->getData('i_sort', 0),
        ]);

        if (!$table->save($entity)) {
            // 新規アップロード時のみ、保存に失敗したファイルを残さない（既存画像の再利用時は消さない）
            if (!$useExisting) {
                @unlink(WWW_ROOT . str_replace('/', DS, $filePath));
            }
            $errors = $entity->getErrors();
            $message = $errors ? implode(' ', array_map(fn($e) => implode(' ', (array)$e), $errors)) : '保存に失敗しました。';
            $this->Flash->error($message);
            return $this->redirect(['action' => 'index']);
        }

        $this->Flash->success('LP画像を追加しました。');
        return $this->redirect(['action' => 'index']);
    }

    /**
     * データベースに登録済みの画像から、選択された画像のファイルパスを解決する。
     *
     * 失敗した場合は Flash にエラーメッセージを設定して null を返す。
     *
     * @param \App\Model\Table\MLpImageTable $table LP画像テーブル
     * @return string|null 選択された画像の相対パス（webroot 起点）
     */
    private function resolveExistingImagePath(MLpImageTable $table): ?string
    {
        $existingId = (int)$this->request->getData('existing_image_id');

        /** @var \App\Model\Entity\MLpImage|null $existing */
        $existing = $table->find()->where(['i_id' => $existingId])->first();
        if ($existing === null) {
            $this->Flash->error('登録済みの画像を選択してください。');
            return null;
        }

        return $existing->c_file_path;
    }

    /**
     * アップロードされた画像ファイルを検証して webroot/img/lp/uploads/ に保存する。
     *
     * 失敗した場合は Flash にエラーメッセージを設定して null を返す。
     *
     * @return string|null 保存した画像の相対パス（webroot 起点）
     */
    private function saveUploadedImage(): ?string
    {
        $file = $this->request->getData('image_file');
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            $this->Flash->error('画像ファイルを選択してください。');
            return null;
        }

        if ((int)$file->getSize() > self::MAX_FILE_SIZE) {
            $this->Flash->error('画像サイズは5MB以下にしてください。');
            return null;
        }

        // クライアント申告のMIMEではなく実ファイルの内容から判定する
        $tmpPath = (string)$file->getStream()->getMetadata('uri');
        $mime    = (string)(new \finfo(FILEINFO_MIME_TYPE))->file($tmpPath);
        if (!isset(self::ALLOWED_MIME_TYPES[$mime])) {
            $this->Flash->error('PNG・JPEG・WebP・GIF形式の画像のみアップロードできます。');
            return null;
        }

        // 推測されにくいランダムなファイル名で保存する
        $fileName = sprintf('lp_%s_%s.%s', date('YmdHis'), bin2hex(random_bytes(4)), self::ALLOWED_MIME_TYPES[$mime]);
        $saveDir  = WWW_ROOT . 'img' . DS . 'lp' . DS . 'uploads';
        if (!is_dir($saveDir)) {
            mkdir($saveDir, 0755, true);
        }
        $file->moveTo($saveDir . DS . $fileName);

        return 'img/lp/uploads/' . $fileName;
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

        // 同じファイルを参照する別レコード（既存画像の再利用）が残っている間はファイルを消さない
        // delete() 後なので、他に同じパスを持つレコードがなければ count() は 0 になる
        $stillReferenced = $table->find()->where(['c_file_path' => $image->c_file_path])->count() > 0;

        // アップロードディレクトリ配下のファイルのみ削除する（パス改ざん対策）
        $uploadsDir = realpath(WWW_ROOT . 'img' . DS . 'lp' . DS . 'uploads');
        $filePath   = realpath(WWW_ROOT . $image->c_file_path);
        if (!$stillReferenced && $uploadsDir !== false && $filePath !== false && str_starts_with($filePath, $uploadsDir . DS)) {
            @unlink($filePath);
        }

        $this->Flash->success('LP画像を削除しました。');
        return $this->redirect(['action' => 'index']);
    }
}
