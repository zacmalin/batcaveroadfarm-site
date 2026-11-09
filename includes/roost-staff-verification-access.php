<?php
// Dedicated policy for the dormant Roost adult-verification workflow.
// A session role alone is insufficient: the caller must recheck the staff
// account in the database and supply the resulting active status.
function bcrf_roost_staff_verification_access(string $role,bool $accountActive): bool {
    return $accountActive && $role === 'administrator';
}
