<?php

namespace Splicewire\Beam\Authorization;

/*
 * Moved to the permission cascade (credential-scope 02; fleet ADR 0006): the gate is
 * `Rushing\PermissionCascade\Authorization\CredentialScopeGate`, and beam keeps only its registration
 * (`BeamServiceProvider::registerCredentialScopeBefore()`). This alias keeps a consumer that still imports the old name
 * (tower `ConduitPolicy`) resolving to the same class until it imports the cascade's. Add no code here.
 *
 * @deprecated import Rushing\PermissionCascade\Authorization\CredentialScopeGate
 */
class_alias(\Rushing\PermissionCascade\Authorization\CredentialScopeGate::class, CredentialScopeGate::class);
