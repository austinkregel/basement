<?php

declare(strict_types=1);

namespace App\Services\Factories;

use App\Jobs\Servers\LinodeSyncJob;
use App\Jobs\Servers\OvhCloudSyncJob;
use App\Jobs\Servers\VultrSyncJob;
use App\Models\Credential;
use App\Services\Server\DigitalOceanService;

class ServerServiceFactory
{
    public function make(Credential $credential)
    {
        return match ($credential->service) {
            Credential::DIGITAL_OCEAN => new DigitalOceanService($credential),
        };
    }
}
