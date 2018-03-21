<?php
/**
 * Created by PhpStorm.
 * User: zhangyizhe
 * Date: 2018/3/21
 * Time: 下午2:58
 */

namespace app\models;

use Yii;
use yii\base\Model;

class EntryForm extends Model
{
    public $name;
    public $email;

    public function rules()
    {
        return [
            [['name', 'email'], 'required'],
            ['email', 'email'],
        ];
    }
}