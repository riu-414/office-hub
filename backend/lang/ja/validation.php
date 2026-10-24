<?php

return [
    'required' => ':attributeを入力してください。',

    'attributes' => [
        'email' => 'メールアドレス',
        'password' => 'パスワード',
    ],

    'email' => ':attributeは正しい形式で入力してください。',

    'string' => ':attributeは文字列で入力してください。',

    'max' => [
        'array' => ':attributeは:max個以下にしてください。',
        'file' => ':attributeは:maxKB以下のファイルにしてください。',
        'numeric' => ':attributeは:max以下の数値で入力してください。',
        'string' => ':attributeは:max文字以内で入力してください。',
    ],
];
