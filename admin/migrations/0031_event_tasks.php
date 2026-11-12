<?php
// admin/migrations/0031_event_tasks.php — Farm OS Phase 3 slice 3: checklist tasks for confirmed parties and bookings
// (Nov 11, 2026; 12 › Starter rules "Event checklist"; includes/event_tasks.php).
//   event_task_steps   the steps for each kind of event: plan 'party' (private event inquiries) or 'booking:<type id>'.
//                      Each step is like a chore (checklist, questions, photo, who) with a time relative to the event.
//   tasks.event_step_id  which step made a task; unique per step and event, so the sync can run any number of times.
// Seeds (only once, only when there are no steps yet), from the farm's party rules: $10 a guest paid on arrival, a souvenir
// cup with feed for each child, a table but no chairs, rain or shine, every child with an adult around the animals.
// Group visits get three steps on every active booking type except day admission. Edit them on Tasks › Event checklists.
// Safe to repeat.
return function (PDO $pdo): void {
    $cs = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS event_task_steps (
        id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        plan              VARCHAR(40) NOT NULL COMMENT 'party, or booking:<booking_types.id>',
        title             VARCHAR(150) NOT NULL,
        description       TEXT NULL,
        checklist_json    TEXT NULL,
        form_json         TEXT NULL,
        photo_rule        VARCHAR(10) NOT NULL DEFAULT 'none',
        followup_unticked TINYINT(1) NOT NULL DEFAULT 0,
        priority          VARCHAR(10) NOT NULL DEFAULT 'normal',
        assignee_user_id  INT NULL,
        assignee_role     VARCHAR(50) NULL,
        day_offset        SMALLINT NOT NULL DEFAULT 0 COMMENT 'days from the event: -7 = a week before',
        anchor            VARCHAR(10) NOT NULL DEFAULT 'time' COMMENT 'time, opening, closing, start, end (bookings)',
        at_time           TIME NULL,
        minute_offset     SMALLINT NOT NULL DEFAULT 0 COMMENT 'start/end: minutes before (-) or after',
        sort_order        SMALLINT NOT NULL DEFAULT 0,
        active            TINYINT(1) NOT NULL DEFAULT 1,
        created_at        DATETIME NOT NULL,
        created_by        INT NULL,
        updated_at        DATETIME NULL,
        KEY idx_plan (plan, active)
    ) $cs");

    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'tasks' AND column_name = 'event_step_id'");
    $st->execute();
    if (!(int)$st->fetchColumn()) {
        $pdo->exec("ALTER TABLE tasks ADD COLUMN event_step_id INT UNSIGNED NULL COMMENT 'event_task_steps row that made this task',
                    ADD UNIQUE KEY uq_event_step (event_step_id, related_type, related_id)");
    }

    if ((int)$pdo->query("SELECT COUNT(*) FROM event_task_steps")->fetchColumn() > 0) return;   // seeded before, or edited: leave alone
    $ins = $pdo->prepare("INSERT INTO event_task_steps (plan, title, description, checklist_json, form_json, photo_rule, priority, day_offset, anchor, at_time, minute_offset, sort_order, created_at)
                          VALUES (?, ?, ?, ?, ?, 'none', ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())");
    $q = fn(array $f) => json_encode($f, JSON_UNESCAPED_UNICODE);
    $l = fn(array $x) => json_encode($x, JSON_UNESCAPED_UNICODE);
    $party = [
        ['Confirm the party', 'Reply to the host (sign "Zac, BCRF").', [
            'Date and rough arrival time agreed',
            'Guest count checked: $10 a guest, paid on arrival (cash, PayPal, Venmo or Cash App)',
            'Told them: bring their own food, cake and decorations; we have a table but no chairs',
            'Told them: open rain or shine; they can move it up to two weeks if the weather is bad',
        ], null, 'high', -7, 'time', '10:00:00', 0, 1],
        ['Party prep', null, [
            'Souvenir cups with animal feed ready, one per child',
            'Party table cleaned and set out',
            'Hand-wash station stocked',
            'Weather checked; host messaged if it looks bad',
        ], null, 'normal', -1, 'closing', null, 0, 2],
        ['Party day', null, [
            'Table ready before they arrive',
            'Greet the host; count guests',
            'Payment collected ($10 a guest)',
            'Souvenir cups handed out to the children',
            'Reminded the adults: every child stays with an adult around the animals',
        ], [
            ['k' => 'q1', 'q' => 'Guests who came', 'type' => 'number', 'options' => [], 'unit' => 'guests', 'required' => true, 'followup' => ''],
            ['k' => 'q2', 'q' => 'Paid in full?', 'type' => 'yesno', 'options' => [], 'unit' => '', 'required' => true, 'followup' => 'no'],
        ], 'high', 0, 'opening', null, 0, 3],
        ['Party clean-up', null, [
            'Table wiped and put away',
            'Trash and decorations cleared',
            'Pens and gates checked after the party',
        ], null, 'normal', 0, 'closing', null, 0, 4],
    ];
    foreach ($party as [$t, $desc, $list, $form, $prio, $off, $anc, $time, $min, $sort]) {
        $ins->execute(['party', $t, $desc, $l($list), $form ? $q($form) : null, $prio, $off, $anc, $time, $min, $sort]);
    }
    $group = [
        ['Group visit prep', null, ['Headcount and arrival time confirmed with the leader', 'Visitor feed stocked for the group', 'Hand-wash station stocked', 'Weather checked'], 'normal', -1, 'closing', null, 0, 1],
        ['Group arriving', null, ['Meet the leader at the entrance', 'Safety talk: hands washed, one adult per child, gates closed behind you', 'Payment or booking checked'], 'high', 0, 'start', null, -30, 2],
        ['Group visit wrap-up', null, ['Group out; gates latched', 'Hand-wash station restocked', 'Note anything to tell the leader or fix next time'], 'normal', 0, 'end', null, 30, 3],
    ];
    try {
        $types = $pdo->query("SELECT id FROM booking_types WHERE is_active = 1 AND type_key NOT LIKE '%admission%'")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { $types = []; }
    foreach ($types as $tid) {
        foreach ($group as [$t, $desc, $list, $prio, $off, $anc, $time, $min, $sort]) {
            $ins->execute(['booking:' . (int)$tid, $t, $desc, $l($list), null, $prio, $off, $anc, $time, $min, $sort]);
        }
    }
};
