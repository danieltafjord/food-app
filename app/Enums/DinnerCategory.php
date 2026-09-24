<?php

namespace App\Enums;

enum DinnerCategory: string
{
    case Meat = 'meat';
    case Fish = 'fish';
    case Vegetarian = 'vegetarian';
    case Other = 'other';
}
