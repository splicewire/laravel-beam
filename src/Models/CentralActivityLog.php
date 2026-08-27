<?php

namespace Splicewire\Beam\Models;

use Spatie\Activitylog\Models\Activity;

/**
 * The central audit trail — a spatie Activity pinned to the central connection, where
 * central-scoped subjects (personal access tokens, users, tenants) live. The default Activity model
 * would land on the tenant-swapped connection and that tenant's own rows; this one is deliberately
 * central and generic (`log_name` names the domain, e.g. 'tokens').
 *
 * There is no longer a separately-named `central_activity_log` table. `activity_log` is ONE shared/
 * migration run in both the central pass and every tenant pass, so "central" here is a CONNECTION
 * distinction, not a table one — this model is the central schema's copy, the default Activity is
 * whichever tenant is bootstrapped. The table carries the old central shape (STRING morph ids), which
 * dominates: it holds a bigint token id, a uuid user id, and a string tenant slug alike.
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

    // NO $table pin, deliberately. `activity_log` is now ONE shared/ migration run in both the central
    // and every tenant pass, so the central trail is the central schema's copy of that table rather
    // than a separately-named `central_activity_log`. Leaving $table unset means this model resolves
    // its table exactly the way the configured activity model does, which is what stops the model and
    // the schema disagreeing. HOW that happens differs by major, and beam declares `^4.0|^5.0`:
    // 4.x's {@see Activity::__construct()} calls setTable(config('activitylog.table_name')), while 5.x
    // deleted that config key and hardcodes `protected $table = 'activity_log'`. Either way the value
    // is spatie's, not ours. The migration stub reaches the same answer from the other side — it asks
    // `activitylog.activity_model` for getTable() — so a host that renames the table still gets one
    // table instead of two, under both majors.
    //
    // The one gap, stated because it is invisible: a host that renames by pointing
    // `activitylog.activity_model` at its OWN subclass moves the migration but not this class, which
    // extends Activity directly. No host does that today.
    //
    // The CONNECTION pin above is what still makes this central.
}
