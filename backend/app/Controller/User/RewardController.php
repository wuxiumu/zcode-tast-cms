<?php

declare(strict_types=1);

namespace App\Controller\User;

use App\Service\RewardService;
use App\Support\Resp;

final class RewardController
{
    public static function list(array $req): void
    {
        Resp::ok(['list' => RewardService::listByUser($req['ctx']['uid'])]);
    }
}
