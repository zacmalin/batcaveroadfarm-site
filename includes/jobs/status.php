<?php
// includes/jobs/status.php
// Application statuses (8, decided 2026-10-23) and the status-history log.
// The pipeline screens (pack P4) add moves between statuses on top of this.

require_once __DIR__ . '/repo.php';

if (!function_exists('jobs_status_labels')) {

    function jobs_status_labels(): array
    {
        return [
            'new'          => 'New',
            'reviewing'    => 'Reviewing',
            'interview'    => 'Interview',
            'offer'        => 'Offer',
            'hired'        => 'Hired',
            'not_selected' => 'Not Selected',
            'withdrawn'    => 'Withdrawn',
            'talent_pool'  => 'Talent Pool',
        ];
    }

    /** Statuses that end an application (retention clock starts at decided_at). */
    function jobs_closed_statuses(): array { return ['hired', 'not_selected', 'withdrawn']; }

    /** Write one status-history row. $byName '' = the website (e.g. auto knockout). */
    function jobs_add_history(PDO $pdo, int $appId, ?string $from, string $to, ?string $note = null, ?int $byUserId = null, string $byName = ''): void
    {
        $pdo->prepare("INSERT INTO application_status_history (application_id, from_status, to_status, changed_by_user_id, changed_by_name, changed_at, note)
                       VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$appId, $from, $to, $byUserId, mb_substr($byName !== '' ? $byName : 'Website', 0, 100), jobs_now(), $note !== null ? mb_substr($note, 0, 500) : null]);
    }
}
