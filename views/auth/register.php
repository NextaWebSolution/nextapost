<h1 class="text-xl font-semibold"><?= e(__('Create your account')) ?></h1>
<p class="mt-1 text-sm text-muted"><?= e(__('You’ll get your own workspace to connect channels and schedule posts.')) ?></p>
<form method="post" action="<?= e(url('/register')) ?>" class="mt-6 space-y-4" x-data="{busy:false, tz: Intl.DateTimeFormat().resolvedOptions().timeZone}" @submit="busy=true">
  <?= csrf_field() ?>
  <input type="hidden" name="timezone" :value="tz" value="Asia/Kolkata">
  <div>
    <label class="label" for="name"><?= e(__('Your name')) ?></label>
    <input id="name" name="name" class="input" autocomplete="name" required maxlength="120" value="<?= e(old('name')) ?>">
  </div>
  <div>
    <label class="label" for="company"><?= e(__('Company or brand')) ?> <span class="font-normal text-muted"><?= e(__('(optional)')) ?></span></label>
    <input id="company" name="company" class="input" autocomplete="organization" maxlength="150" value="<?= e(old('company')) ?>">
  </div>
  <div>
    <label class="label" for="email"><?= e(__('Work email')) ?></label>
    <input id="email" name="email" type="email" class="input" autocomplete="email" required value="<?= e(old('email')) ?>">
  </div>
  <div>
    <label class="label" for="password"><?= e(__('Password')) ?></label>
    <input id="password" name="password" type="password" class="input" autocomplete="new-password" required minlength="8">
    <p class="help"><?= e(__('At least 8 characters.')) ?></p>
  </div>
  <button class="btn btn-primary w-full" :disabled="busy"><?= e(__('Create account')) ?></button>
</form>
<p class="mt-6 text-center text-sm text-muted"><?= e(__('Already have an account?')) ?> <a class="font-semibold text-brand-strong hover:underline dark:text-violet-300" href="<?= e(url('/login')) ?>"><?= e(__('Sign in')) ?></a></p>
