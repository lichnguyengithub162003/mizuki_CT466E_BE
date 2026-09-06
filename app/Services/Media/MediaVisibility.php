<?php

namespace App\Services\Media;

enum MediaVisibility: string
{
    case Public = 'public';
    case Private = 'private';
}
