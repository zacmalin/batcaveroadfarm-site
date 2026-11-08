<?php
// Roost Rewards attendee identity foundation. Schema only: no automatic visit awards.
// A check-in can represent a group, so awards must target verified individual attendees.
// Never infer attendance from the purchaser, passholder, or party size.
return function (PDO $pdo): void {
    $cs = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS reward_verified_attendance (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        check_in_id BIGINT UNSIGNED NOT NULL,
        customer_id INT NOT NULL,
        business_date DATE NOT NULL COMMENT 'farm local operating date; must match check_ins.business_date',
        verified_by_staff_id INT NOT NULL COMMENT 'authenticated staff actor, never customer supplied',
        verified_at DATETIME(3) NOT NULL COMMENT 'UTC',
        created_at DATETIME(3) NOT NULL COMMENT 'UTC',
        UNIQUE KEY uq_attendee_checkin (check_in_id, customer_id),
        UNIQUE KEY uq_attendee_operating_day (customer_id, business_date),
        KEY idx_attendance_checkin (check_in_id),
        CONSTRAINT fk_reward_attendance_checkin FOREIGN KEY (check_in_id) REFERENCES check_ins(id),
        CONSTRAINT fk_reward_attendance_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
    ) $cs");
};
