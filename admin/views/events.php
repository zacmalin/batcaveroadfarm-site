<?php
// admin/views/events.php — manage farm events (list + add/edit).
require_once __DIR__ . '/../../includes/events.php';
bcrf_events_ensure_schema($pdo);

$csrf = $_SESSION['csrf_token'] ?? '';
$editId = (int)($_GET['edit'] ?? 0);
$edit = null;
if ($editId > 0) {
    try { $st = $pdo->prepare("SELECT * FROM events WHERE id = ?"); $st->execute([$editId]); $edit = $st->fetch(PDO::FETCH_ASSOC) ?: null; }
    catch (Throwable $e) { $edit = null; }
}
try { $rows = $pdo->query("SELECT * FROM events ORDER BY start_datetime DESC")->fetchAll(PDO::FETCH_ASSOC) ?: []; }
catch (Throwable $e) { $rows = []; }

$v = function ($k, $d = '') use ($edit) { return htmlspecialchars((string)($edit[$k] ?? $d), ENT_QUOTES); };
$dtv = function ($k) use ($edit) {
    $raw = trim((string)($edit[$k] ?? ''));
    if ($raw === '' || strpos($raw, '0000') === 0) return '';
    return htmlspecialchars(str_replace(' ', 'T', substr($raw, 0, 16)), ENT_QUOTES);
};
$recDays = array_filter(array_map('trim', explode(',', (string)($edit['recur_days'] ?? ''))), 'strlen');
$dows = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
$curRec = (string)($edit['recurrence'] ?? 'none');
?>
<div class="max-w-5xl">
  <h1 class="text-2xl font-bold text-stone-800 mb-1">📅 Farm Events</h1>
  <p class="text-sm text-stone-500 mb-6">Add seasonal, recurring, or one-off events. Published events appear on the public <a href="/events/" target="_blank" class="text-amber-700 underline">events page</a>; past one-off events drop off automatically.</p>

  <!-- Add / Edit form -->
  <form method="POST" action="/admin/admin.php?view=events" class="bg-white border border-stone-200 rounded-lg p-5 mb-8 shadow-sm">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
    <input type="hidden" name="event_action" value="save">
    <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
    <h2 class="font-semibold text-stone-700 mb-4"><?= $edit ? 'Edit event' : 'Add a new event' ?></h2>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div class="md:col-span-2">
        <label class="block text-xs font-semibold text-stone-600 mb-1">Title *</label>
        <input type="text" name="title" required value="<?= $v('title') ?>" class="w-full p-2 border border-stone-300 rounded bg-stone-50">
      </div>
      <div>
        <label class="block text-xs font-semibold text-stone-600 mb-1">Start *</label>
        <input type="datetime-local" name="start_datetime" required value="<?= $dtv('start_datetime') ?>" class="w-full p-2 border border-stone-300 rounded bg-stone-50">
      </div>
      <div>
        <label class="block text-xs font-semibold text-stone-600 mb-1">End (optional)</label>
        <input type="datetime-local" name="end_datetime" value="<?= $dtv('end_datetime') ?>" class="w-full p-2 border border-stone-300 rounded bg-stone-50">
      </div>
      <div class="flex items-center gap-2">
        <input type="checkbox" id="ev-allday" name="all_day" value="1" <?= !empty($edit['all_day']) ? 'checked' : '' ?>>
        <label for="ev-allday" class="text-sm text-stone-600">All-day event</label>
      </div>
      <div>
        <label class="block text-xs font-semibold text-stone-600 mb-1">Recurrence</label>
        <select id="ev-recurrence" name="recurrence" class="w-full p-2 border border-stone-300 rounded bg-stone-50" onchange="bcrfRecToggle()">
          <?php foreach (['none'=>'One-time','range'=>'Date range (season)','weekly'=>'Weekly','annual'=>'Annual'] as $rv=>$rl): ?>
          <option value="<?= $rv ?>" <?= $curRec===$rv?'selected':'' ?>><?= $rl ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div id="ev-weekdays" class="md:col-span-2" style="<?= $curRec==='weekly'?'':'display:none' ?>">
        <label class="block text-xs font-semibold text-stone-600 mb-1">Repeats on</label>
        <div class="flex flex-wrap gap-3">
          <?php foreach ($dows as $i=>$dn): ?>
          <label class="flex items-center gap-1 text-sm text-stone-600"><input type="checkbox" name="recur_days[]" value="<?= $i ?>" <?= in_array((string)$i,$recDays,true)?'checked':'' ?>> <?= $dn ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div id="ev-until" class="md:col-span-2" style="<?= in_array($curRec,['weekly','annual'],true)?'':'display:none' ?>">
        <label class="block text-xs font-semibold text-stone-600 mb-1">Repeats until (optional series end)</label>
        <input type="date" name="recur_until" value="<?= $v('recur_until') ?>" class="w-full md:w-64 p-2 border border-stone-300 rounded bg-stone-50">
      </div>
      <div class="md:col-span-2">
        <label class="block text-xs font-semibold text-stone-600 mb-1">Schedule note (shown to visitors, e.g. "Every Saturday in October")</label>
        <input type="text" name="recurrence_note" value="<?= $v('recurrence_note') ?>" class="w-full p-2 border border-stone-300 rounded bg-stone-50">
      </div>

      <div class="flex items-center gap-2">
        <input type="checkbox" id="ev-free" name="is_free" value="1" <?= (!$edit || !empty($edit['is_free'])) ? 'checked' : '' ?>>
        <label for="ev-free" class="text-sm text-stone-600">Free event</label>
      </div>
      <div>
        <label class="block text-xs font-semibold text-stone-600 mb-1">Price (if not free)</label>
        <input type="number" step="0.01" min="0" name="price" value="<?= $v('price') ?>" class="w-full p-2 border border-stone-300 rounded bg-stone-50">
      </div>
      <div class="md:col-span-2">
        <label class="block text-xs font-semibold text-stone-600 mb-1">Image URL (optional)</label>
        <input type="text" name="image_url" value="<?= $v('image_url') ?>" class="w-full p-2 border border-stone-300 rounded bg-stone-50" placeholder="https://batcaveroadfarm.com/img/...">
      </div>
      <div class="md:col-span-2">
        <label class="block text-xs font-semibold text-stone-600 mb-1">Description</label>
        <textarea name="description" rows="3" class="w-full p-2 border border-stone-300 rounded bg-stone-50"><?= htmlspecialchars((string)($edit['description'] ?? ''), ENT_QUOTES) ?></textarea>
      </div>
      <div>
        <label class="block text-xs font-semibold text-stone-600 mb-1">Status</label>
        <select name="status" class="w-full p-2 border border-stone-300 rounded bg-stone-50">
          <option value="published" <?= (($edit['status'] ?? 'published')==='published')?'selected':'' ?>>Published</option>
          <option value="draft" <?= (($edit['status'] ?? '')==='draft')?'selected':'' ?>>Draft</option>
        </select>
      </div>
    </div>

    <div class="mt-5 flex gap-3">
      <button type="submit" class="bg-amber-700 hover:bg-amber-800 text-white font-semibold px-5 py-2 rounded text-sm"><?= $edit ? 'Update event' : 'Add event' ?></button>
      <?php if ($edit): ?><a href="/admin/admin.php?view=events" class="px-5 py-2 rounded text-sm border border-stone-300 text-stone-600 hover:bg-stone-100">Cancel</a><?php endif; ?>
    </div>
  </form>

  <!-- Existing events -->
  <h2 class="font-semibold text-stone-700 mb-3">All events (<?= count($rows) ?>)</h2>
  <div class="overflow-x-auto">
    <table class="w-full text-sm bg-white border border-stone-200 rounded-lg overflow-hidden" data-admin-list data-list-name-col="0" data-list-date-col="1">
      <thead class="bg-stone-800 text-stone-100 text-xs uppercase tracking-wide">
        <tr><th class="text-left p-2">Title</th><th class="text-left p-2">When</th><th class="text-left p-2">Recurs</th><th class="text-left p-2">Status</th><th class="p-2">Actions</th></tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
        <tr><td colspan="5" class="p-4 text-center text-stone-400">No events yet — add your first one above.</td></tr>
        <?php else: foreach ($rows as $r): ?>
        <tr class="border-t border-stone-100">
          <td class="p-2 font-medium text-stone-700"><?= htmlspecialchars((string)$r['title']) ?></td>
          <td class="p-2 text-stone-500"><?= htmlspecialchars(date('M j, Y', strtotime((string)$r['start_datetime']))) ?></td>
          <td class="p-2 text-stone-500"><?= htmlspecialchars($r['recurrence'] === 'none' ? '—' : $r['recurrence']) ?></td>
          <td class="p-2"><span class="text-xs px-2 py-0.5 rounded <?= $r['status']==='published'?'bg-green-100 text-green-700':'bg-stone-200 text-stone-600' ?>"><?= htmlspecialchars((string)$r['status']) ?></span></td>
          <td class="p-2 text-center whitespace-nowrap">
            <a href="/admin/admin.php?view=events&edit=<?= (int)$r['id'] ?>" class="text-amber-700 hover:underline mr-2">Edit</a>
            <form method="POST" action="/admin/admin.php?view=events" class="inline">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
              <input type="hidden" name="event_action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button type="submit" class="text-stone-500 hover:underline mr-2"><?= $r['status']==='published'?'Unpublish':'Publish' ?></button>
            </form>
            <form method="POST" action="/admin/admin.php?view=events" class="inline" >
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
              <input type="hidden" name="event_action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button type="submit" class="text-red-600 hover:underline">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function bcrfRecToggle(){
  var r = document.getElementById('ev-recurrence').value;
  document.getElementById('ev-weekdays').style.display = (r === 'weekly') ? '' : 'none';
  document.getElementById('ev-until').style.display = (r === 'weekly' || r === 'annual') ? '' : 'none';
}
</script>
