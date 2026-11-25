<?php
return ['default'=>'daily','channels'=>[
    'daily'=>['driver'=>'daily','path'=>storage_path('logs/laravel/error.log'),'level'=>'warning','days'=>30,'replace_placeholders'=>true],
    'emergency'=>['path'=>storage_path('logs/laravel/emergency.log')],
]];