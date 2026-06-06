<?php

declare(strict_types=1);

use Switon\Dict\Attribute\DictValue;
use Switon\Validating\Attribute\EqualTo;

return [
    DictValue::class => '{field} 不是有效的字典值',
    EqualTo::class => '{field} 与 {otherField} 不一致',
    'labels' => [
        'AssetController::scene' => '场景',
        'DepartmentController::status' => '状态',
        'PositionController::status' => '状态',
        'GroupController::status' => '状态',
        'TypeController::value_type' => '值类型',
        'GroupController::type' => '分组类型',
        'PasswordChangeInput::old_password' => '旧密码',
        'PasswordChangeInput::new_password' => '新密码',
        'PasswordChangeInput::new_password_confirm' => '确认密码',
    ],
];
