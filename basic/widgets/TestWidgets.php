<?php
/**
 * Created by PhpStorm.
 * User: zhangyizhe
 * Date: 2018/3/22
 * Time: 上午9:37
 */

namespace app\widgets;

use yii\base\Widget;
use yii\helpers\Html;

class TestWidgets extends Widget
{
    public $message;

    public function init()
    {
        parent::init(); // TODO: Change the autogenerated stub
        if ($this->message === null) {
            $this->message = 'Hello World';
        }
    }

    public function run()
    {
        return Html::encode($this->message);
    }
}