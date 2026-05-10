<?php
declare(strict_types=1);

namespace App\Entity;

enum StorageType: string
{
    case GoogleDrive = 'google_drive';
}
