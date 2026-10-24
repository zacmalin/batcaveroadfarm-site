<?php
// admin/views/jobs/_tabs.php
// Tab strip shared by the Hiring screens (Applicants + setup). Expects $target_view in scope.
$__jt = [
    'jobs'            => 'Applicants',
    'jobs_emails'     => 'Emails',
    'jobs_staff'      => 'Staff',
    'jobs_posts'      => 'Jobs',
    'jobs_questions'  => 'Question bank',
    'jobs_categories' => 'Categories',
];
?>
<nav class="flex gap-1 mb-5 border-b border-stone-300 overflow-x-auto" aria-label="Hiring">
  <?php foreach ($__jt as $__v => $__l): $__on = ($target_view ?? '') === $__v; ?>
  <a href="/admin/admin.php?view=<?= $__v ?>"
     class="px-4 py-2 text-sm whitespace-nowrap -mb-px border-b-2 <?= $__on ? 'border-amber-700 text-amber-800 font-semibold' : 'border-transparent text-stone-500 hover:text-stone-800' ?>"
     <?= $__on ? 'aria-current="page"' : '' ?>><?= $__l ?></a>
  <?php endforeach; ?>
</nav>
