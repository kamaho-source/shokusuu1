<?php

namespace App\Controller\Component;

use Cake\Controller\Component;

class JsonResponseComponent extends Component
{
    public function success(string $message, array $data = [], ?string $redirect = null)
    {
        return [
            'status' => 'success',
            'message' => $message,
            'data' => $data,
            'redirect' => $redirect,
        ];
    }

    public function error(string $message, array $data = [])
    {
        return [
            'status' => 'error',
            'message' => $message,
            'data' => $data,
        ];
    }
}
