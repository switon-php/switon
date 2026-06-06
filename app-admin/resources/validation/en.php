<?php

declare(strict_types=1);

use Switon\Dict\Attribute\DictValue;
use Switon\Validating\Attribute\EqualTo;

return [
    DictValue::class => 'The {field} must be a valid dict value.',
    EqualTo::class => 'The {field} must match {otherField}.',
    'labels' => [
        'AssetController::scene' => 'Scene',
        'DepartmentController::status' => 'Status',
        'PositionController::status' => 'Status',
        'GroupController::status' => 'Status',
        'TypeController::value_type' => 'Value Type',
        'GroupController::type' => 'Group Type',
        'PasswordChangeInput::old_password' => 'Current Password',
        'PasswordChangeInput::new_password' => 'New Password',
        'PasswordChangeInput::new_password_confirm' => 'Confirm Password',
    ],
];
