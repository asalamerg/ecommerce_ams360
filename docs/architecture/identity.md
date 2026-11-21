# Identity and access

The rules behind sign-in, registration, and access to the admin panel.

## Entry points

| Page | Who it is for | Notes |
| --- | --- | --- |
| `/login` | Customers and staff | Staff are sent to the admin panel after signing in. |
| `/register` | Customers | Always creates a `customer`. A role in the request is ignored. |
| `/admin/login` | Staff only | Refuses customers with the generic failure message. |
| `/admin/*` as a guest | | Redirects to `/admin/login`. |
| `/dashboard` | Any signed-in user | Redirects to that user's home. Fortify's `home` points here. |

`App\Support\AccountHome` is the only place that maps a role to a landing page. Password, two-factor, passkey, registration, and email-verification responses all go through `RedirectsToAccountHome`. That trait honours the intended URL, except that a non-staff user is never returned to an `/admin` URL.

## Creating staff accounts

Staff roles are never granted by a web form that a customer can reach. Use a trusted shell:

```powershell
php artisan store:create-admin owner@example.com
php artisan store:grant-role existing@example.com product_manager
```

`store:create-admin` prompts for the name and password, so they never appear in shell history. Inside the panel, only an owner can grant `owner` or `administrator`.

## Security controls

- **One failure message.** `AuthenticateStoreUser` returns null for a wrong password, an unknown email, a disabled account, and a customer at the staff login. All of them answer with `auth.failed`, and an unknown email still runs a bcrypt check, so neither the message nor the timing reveals the reason.
- **Disabled accounts.** `users.is_active` blocks sign-in, and `EnsureAccountIsActive` ends any session already open on its next request, including passkey and remember-me sessions. Nobody can disable their own account, and only an owner can disable an owner or administrator.
- **Staff two-factor.** `RequireStaffTwoFactor` sends staff to `/settings/security` until two-factor is enabled. It is on by default in production and controlled by `STORE_REQUIRE_STAFF_2FA`.
- **Rate limits.** Login allows 5 attempts a minute per email and IP, and 30 per IP. Both login forms share this limit. Registration and password-reset requests allow 5 a minute and 30 an hour per IP. Fortify does not throttle those two, so `routes/web.php` replaces its routes.
- **Account enumeration.** The password-reset form answers the same whether or not the email exists. Registration still reports a taken email, because the customer needs to know.
- **Sign-in log.** `RecordAuthEvent` writes logins, logouts, failures, password resets, and verifications to `auth_events`, and never stores the submitted credentials. Administrators see it on `/admin/activity`. Records older than 180 days are pruned daily by `model:prune`, which needs the scheduler running in production.

## Language

`SetStoreLocale` runs on every web request. The order is the `{locale}` route segment, then `?lang=`, then the session, then Arabic. Interface text lives in `lang/{ar,en,he}/ui.php` and reaches React through the `ui` shared prop and the `useTranslations` hook. `app.tsx` updates `<html lang dir>` on each Inertia visit, because client-side navigation does not reload the document.

The admin panel uses the same chain. Its text lives in `lang/ar.json` and `lang/he.json`, keyed by the English string, and reaches React through the `adminText` shared prop and the `useAdminText` hook. English needs no file. `/settings/*` stays English and LTR until it is translated.

## Not built yet

- Saved customer addresses. They belong with checkout, in stage 7.
- Forcing a sign-out of other devices after a password change.
- Translated `/settings` pages.
