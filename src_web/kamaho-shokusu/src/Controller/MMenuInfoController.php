<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Controller\Controller;
use Cake\Event\EventInterface;
use Cake\Http\Client;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\ServiceUnavailableException;

class MMenuInfoController extends AppController
{
    public function initialize(): void
    {
        parent::initialize();
        $this->loadComponent('Authentication.Authentication');
        $this->viewBuilder()->setLayout('default');
    }

    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);
    }

    public function index()
    {
        // メニュー情報は全認証ユーザーが参照可能な共通情報のため認可チェックをスキップする
        $this->Authorization->skipAuthorization();
    }

    public function generateMenuChat()
    {
        // AI チャット生成は全認証ユーザーが利用可能な共通機能のため認可チェックをスキップする
        $this->Authorization->skipAuthorization();
        $this->autoRender = false;
    }
}
