<?php

return [
    'required' => 'El campo :attribute es obligatorio.',
    'email' => 'El campo :attribute debe ser un correo electrónico válido.',
    'string' => 'El campo :attribute debe ser texto.',
    'confirmed' => 'La confirmación de :attribute no coincide.',
    'unique' => 'El valor de :attribute ya está registrado.',
    'current_password' => 'La contraseña actual no es correcta.',
    'min' => ['string' => 'El campo :attribute debe tener al menos :min caracteres.'],
    'max' => ['string' => 'El campo :attribute no debe superar :max caracteres.'],
    'password' => [
        'letters' => 'La contraseña debe contener al menos una letra.',
        'numbers' => 'La contraseña debe contener al menos un número.',
        'mixed' => 'La contraseña debe incluir mayúsculas y minúsculas.',
        'symbols' => 'La contraseña debe incluir al menos un símbolo.',
        'uncompromised' => 'Esta contraseña apareció en una filtración. Elige otra.',
    ],
    'attributes' => [
        'name' => 'nombre', 'email' => 'correo electrónico', 'password' => 'contraseña',
        'current_password' => 'contraseña actual', 'password_confirmation' => 'confirmación de contraseña',
        'code' => 'código', 'recovery_code' => 'código de recuperación',
    ],
];
