<?php
// includes/jobs/default_templates.php
// Default wording for the Jobs email templates. Used once, by the database setup
// (includes/jobs/schema.php), to seed email_templates, and by "Reset to default"
// in Hiring > Emails > Templates. Edit the live wording there, not here.
//
// Bodies are the INNER part of the email; the farm header and footer are added
// when sending (bcf_send_mail). Merge tags look like {first_name}.

if (!function_exists('jobs_default_templates')) {
    function jobs_default_templates(): array
    {
        $h1  = fn(string $t) => '<h1 style="font-family:Rye,Georgia,serif;color:#e8b84b;font-size:24px;margin:0 0 14px;">' . $t . '</h1>';
        $p   = fn(string $t) => '<p style="margin:0 0 16px;">' . $t . '</p>';
        $sm  = fn(string $t) => '<p style="margin:0;font-size:14px;color:rgba(245,234,214,0.8);">' . $t . '</p>';
        $btn = fn(string $href, string $label) => '<p style="margin:0 0 24px;"><a href="' . $href . '" style="display:inline-block;background:#c9923a;color:#3b2010;font-family:Lora,Georgia,serif;font-weight:bold;text-decoration:none;padding:13px 28px;border-radius:8px;">' . $label . '</a></p>';
        $box = fn(string $t) => '<div style="background:rgba(0,0,0,0.25);border:1px solid rgba(201,146,58,0.5);border-radius:8px;padding:14px 18px;margin:0 0 18px;">' . $t . '</div>';

        return [
            'received' => [
                'name'    => 'Application received (to applicant)',
                'subject' => 'We got your application for {position}',
                'body'    => $h1('Thanks, {first_name}!')
                    . $p('We received your application for <strong>{position}</strong> at Bat Cave Road Farm.')
                    . $p('Zac or Casey will read it and get back to you. If we\'d like to talk, we\'ll email or call to set up a time.')
                    . $sm('Questions? Just reply to this email, or call {farm_phone}.'),
            ],
            'admin_new_applicant' => [
                'name'    => 'New applicant (to Zac)',
                'subject' => 'New applicant: {applicant_name} for {position}',
                'body'    => $h1('New applicant')
                    . $p('<strong>{applicant_name}</strong> applied for <strong>{position}</strong> ({category}).')
                    . $p('{flags}')
                    . $btn('{application_link}', 'Open the application'),
            ],
            'interview' => [
                'name'    => 'Interview scheduled',
                'subject' => 'Interview at Bat Cave Road Farm: {interview_datetime}',
                'body'    => $h1('Let\'s meet, {first_name}')
                    . $p('We\'d like to talk with you about the <strong>{position}</strong> position.')
                    . $box('<strong>When:</strong> {interview_datetime}<br><strong>Where:</strong> {interview_location}')
                    . $p('A calendar invite is attached. Directions: <a href="https://batcaveroadfarm.com/directions/" style="color:#e8b84b;">batcaveroadfarm.com/directions</a>')
                    . $sm('Need a different time? Reply to this email or call {farm_phone}.'),
            ],
            'offer' => [
                'name'    => 'Offer',
                'subject' => 'An offer from Bat Cave Road Farm',
                'body'    => $h1('Good news, {first_name}')
                    . $p('We\'d like to offer you the <strong>{position}</strong> position at Bat Cave Road Farm.')
                    . $box('{offer_details}')
                    . $p('Please reply to this email to accept, or if you have any questions.')
                    . $sm('Bat Cave Road Farm · {farm_address} · {farm_phone}'),
            ],
            'hired' => [
                'name'    => 'Hired — welcome + set password',
                'subject' => 'Welcome to Bat Cave Road Farm, {first_name}',
                'body'    => $h1('Welcome to the farm!')
                    . $p('Hi {first_name}, we\'re happy to have you as our new <strong>{position}</strong>. Your start date is <strong>{start_date}</strong>.')
                    . $p('You\'ll use your Bat Cave Road Farm account to clock in and out. Tap below to set your password:')
                    . $btn('{set_password_link}', 'Set my password')
                    . $sm('This link works for 7 days. If it runs out, just ask us for a new one.'),
            ],
            'not_selected' => [
                'name'    => 'Not selected',
                'subject' => 'Your application for {position}',
                'body'    => $h1('Thank you, {first_name}')
                    . $p('Thank you for applying for <strong>{position}</strong> at Bat Cave Road Farm. We\'ve decided to move forward with other applicants this time.')
                    . $p('We really appreciate your interest in the farm, and we hope you\'ll apply again in a future season.')
                    . $sm('Bat Cave Road Farm · {farm_address}'),
            ],
            'alert_confirm' => [
                'name'    => 'Job alerts — confirm signup',
                'subject' => 'Confirm your Bat Cave Road Farm job alerts',
                'body'    => $h1('One more step')
                    . $p('Tap below to start getting an email when Bat Cave Road Farm posts a job that matches what you picked.')
                    . $btn('{confirm_link}', 'Yes, send me job alerts')
                    . $sm('If you didn\'t sign up, ignore this email and nothing will happen.'),
            ],
            'alert_new_job' => [
                'name'    => 'Job alert — new opening',
                'subject' => 'Now hiring: {position}',
                'body'    => $h1('Now hiring: {position}')
                    . $p('Bat Cave Road Farm just opened a position that matches your job alerts.')
                    . $box('<strong>{position}</strong> · {category}<br>{pay_summary}<br>Apply by: {closes_date}')
                    . $btn('{job_link}', 'See the job'),
            ],
            'talent_pool_reopen' => [
                'name'    => 'Talent Pool — job reopened',
                'subject' => '{position} is open again at Bat Cave Road Farm',
                'body'    => $h1('Hi {first_name}, we\'re hiring again')
                    . $p('You applied for <strong>{position}</strong> before, and we kept your application on file. The position is open again for a new season.')
                    . $btn('{job_link}', 'See the job and apply')
                    . $sm('Questions? Reply to this email or call {farm_phone}.'),
            ],
        ];
    }
}
