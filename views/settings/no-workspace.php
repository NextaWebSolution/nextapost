<h1 class="text-xl font-semibold">Create a workspace</h1>
<p class="mt-1 text-sm text-muted">You’re not a member of any workspace. Create one to start planning posts.</p>
<form method="post" action="<?= e(url('/workspaces')) ?>" class="mt-6 space-y-4">
  <?= csrf_field() ?>
  <div><label class="label" for="name">Workspace name</label><input id="name" name="name" class="input" required maxlength="150" placeholder="e.g. My Brand"></div>
  <button class="btn btn-primary w-full">Create workspace</button>
</form>
<form method="post" action="<?= e(url('/logout')) ?>" class="mt-4 text-center"><?= csrf_field() ?><button class="text-sm text-muted hover:underline">Sign out</button></form>
