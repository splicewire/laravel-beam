<?php

namespace Splicewire\Beam\Models;

use Spatie\Activitylog\Models\Activity;

/**
 * The central audit trail — a spatie Activity pinned to the central connection, where
 * central-scoped subjects (personal access tokens, users, tenants) live. The default Activity
 * model would land on the tenant-swapped connection and the tenant-local `activity_log`; this
 * one is deliberately central and generic (`log_name` names the domain, e.g. 'tokens').
 *
 * Homed in beam CORE rather than any one engine: its subjects span beam-accounts (personal access
 * tokens, users) and beam-tenancy (tenants), and its consumers span tower (the operator dashboards,
 * `ActivityQuery`, `CentralActivityData`) and beam-workflows (the ADR-0098 Display status timeline
 * resolves it through the `beam.workflows.activity_models` seam). Core is the only tier all of them
 * already depend on. It previously lived in beam-tenancy, which made a shared audit model reachable
 * only by declaring a tenancy dependency you may not otherwise need.
 *
 * The pin is unavoidable here, unlike a relation-written model: spatie's logger resolves
 * `activitylog.activity_model` and instantiates it directly, so there is no parent whose connection
 * Eloquent could inherit. See the note on `$connection` below.
 *
 * Entries are retained even after their subject is hard-deleted — nothing here cascades — so the
 * `properties` snapshot is the durable record. Morph id columns are strings so a bigint token id,
 * a uuid user id, and a string tenant id can all be a subject/causer.
 */
class CentralActivityLog extends Activity
{
    /**
     * @central-floor auth — the audit trail follows its subjects, and those subjects are floor
     * records: personal access tokens and users (auth floor) plus tenants (isolation record).
     * The trail must survive any one tenant's churn and stay readable in one central join —
     * a tenant-local log could not durably record the floor's own lifecycle.
     */
    protected $connection = 'central';

    protected $table = 'central_activity_log';
}
