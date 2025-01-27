<?php

namespace App\Jobs\Domains;

use App\Jobs\AbstractSyncDomainResource;
use App\Models\Domain;
use App\Services\Factories\DomainServiceFactory;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class Route53SyncJob extends AbstractSyncDomainResource
{
    public function handle(DomainServiceFactory $serviceFactory): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }
        $credentials = [$this->credential];
        foreach ($credentials as $credential) {
            try {
                $this->service = $serviceFactory->make($credential);
                $this->credential = $credential;
                $this->sync();
            } catch (UnauthorizedHttpException $e) {
                // There are some times when we have a different credential for the same uuid.
                // this would be when things are duplicated, the uuid stays the same, but the credentials themselves and what they access
                // change. So we need to try all our credentials of a uuid to ensure that we're syncing everything available.
            }
        }
    }

    public function sync(): void
    {
        $page = 1;
        $dispatcher = Model::getEventDispatcher();
        Model::unsetEventDispatcher();
        do {
            $domains = $this->service->getDomains(100, $page++);
            foreach ($domains as $domain) {
                // In order for domain jobs to be able to run, we need the domain to exist from a registrar.
                $localDomain = Domain::where('name', $domain['domain'])->first();

                if (empty($localDomain)) {
                    info('No local domain for the provided credentials', [
                        'domain' => $domain['domain'],
                        'credential' => $localDomain,
                    ]);

                    // If we don't have the domain in question synced via registrars we don't want to touch it.
                    continue;
                }

                if ($localDomain->isDirty()) {
                    $localDomain->save();
                }

                $dnsResults = $this->service->getDns($domain['id']);
                foreach ($dnsResults as $dnsRecord) {
                    $localDomain->records()->firstOrCreate([
                        'type' => $dnsRecord['type'],
                        'name' => $dnsRecord['name'],
                    ], [
                        'record_id' => $dnsRecord['id'],
                        'ttl' => $dnsRecord['ttl'],
                        'value' => is_array($dnsRecord['content']) ? json_encode($dnsRecord['content']) : $dnsRecord['content'],
                        'priority' => $dnsRecord['priority'],
                        'proxied_through_cloudflare' => false,
                    ]);

                    if (!$localDomain->wasRecentlyCreated) {
                        $localDomain->update([
                            'ttl' => $dnsRecord['ttl'],
                            'value' => is_array($dnsRecord['content']) ? json_encode($dnsRecord['content']) : $dnsRecord['content'],
                            'priority' => $dnsRecord['priority'],
                            'updated_at'  => now(),
                        ]);
                    }
                }

                $localDomain->records()
                    // Anything we didn't recently update is probably gone.
                    ->where('updated_at', '<=', now()->subHours(5))
                    ->delete();
            }
        } while ($domains->hasMorePages());
        Model::setEventDispatcher($dispatcher);
    }
}