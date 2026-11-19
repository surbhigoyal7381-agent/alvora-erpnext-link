<?php

namespace Alvora\ErpnextLink\Support;

use Fleetbase\Models\Company;
use Fleetbase\Models\User;

/**
 * One stock activity-log entry per connection action. The stock observer files an entry under the
 * session company (the admin's own organization, or none in a job), so company_id is set to the
 * client organization afterwards, the way stock CompanyController does for admin actions.
 * Properties carry the host only: never a key, secret, URL path or remote text.
 */
final class Audit
{
    public static function record(Company $company, ?string $actorUuid, ?string $ip, string $event, string $description, array $properties = []): void
    {
        $actor = $actorUuid ? User::where('uuid', $actorUuid)->first() : null;

        $activity = activity('alvora_erpnext')
            ->causedBy($actor)
            ->performedOn($company)
            ->event($event)
            ->withProperties($properties + ['ip' => $ip])
            ->log($description);

        $activity->company_id = $company->uuid;
        $activity->saveQuietly();
    }
}
