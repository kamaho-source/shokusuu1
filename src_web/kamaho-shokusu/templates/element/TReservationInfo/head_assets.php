<?php
/** @var array $jsConfigVars */
?>
<meta charset="UTF-8">
<title>食数予約</title>
<meta name="csrfToken" content="<?= h($this->request->getAttribute('csrfToken')) ?>">
<!-- サーバ側の値を JS に安全に出力 -->
<?= $this->element('TReservationInfo/js_config', $jsConfigVars) ?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">

<?= $this->Html->css('pages/treservation_index.css') ?>
