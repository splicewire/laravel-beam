<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Http\UngatedWrites;
use Splicewire\Beam\Testing\AssertsUngatedWrites;

/**
 * `http.ungated-write` (app-walkthrough APP-08, APP-11): the hand-written trio write routes nothing gates (see
 * {@see UngatedWrites} for what counts as a gate). Advisory: which routes a host mounts is a fact about the host, so
 * this never fails the exit code, but it warns until the count is zero. A host holds the count down with its own
 * ratchet test ({@see AssertsUngatedWrites}), each entry naming its owner.
 */
class UngatedWriteAudit implements DoctorAudit
{
    public const CHECK = 'http.ungated-write';

    public function __construct(private UngatedWrites $writes) {}

    public function run(): array
    {
        $found = $this->writes->find();

        if ($found === []) {
            return [Finding::pass(self::CHECK, 'Every write route outside the particle surface and the framework list declares a gate (can:/require.*, a handler authorization call, or a request Data or FormRequest authorize()).')];
        }

        $public = array_keys(array_filter($found, fn (array $write) => $write['public']));
        $authenticated = array_keys(array_filter($found, fn (array $write) => ! $write['public']));

        return [Finding::warn(self::CHECK, sprintf(
            '%d write route%s declare%s no gate. Authenticated, so any signed-in principal may call it (%d): %s. '
            .'Public, no authentication at all (%d): %s. Declare `can:`/`require.*` on the route, authorize in the '
            .'handler, or give the request Data an `authorize()`.',
            count($found),
            count($found) === 1 ? '' : 's',
            count($found) === 1 ? 's' : '',
            count($authenticated),
            $authenticated === [] ? 'none' : implode('; ', $authenticated),
            count($public),
            $public === [] ? 'none' : implode('; ', $public),
        ))];
    }
}
