# Authentication

How people sign in to Elimu Pass Admin and Elimu Pass Portal, how long sessions last, and
how to set up Continue with Google. Permissions (what someone may do once signed
in) are in `docs/permissions.md`, and emailed links in `docs/email.md`.

## Ways to sign in

| Method | Endpoint | Notes |
|---|---|---|
| Username or email + password | `POST /auth/login` | No school code. Unknown account and wrong password give the same 422. |
| Continue with Google | `POST /auth/google` `{id_token}` | Existing accounts only (below). |
| Invitation link + chosen password | `POST /auth/invitations/{token}/accept` | |
| Invitation link + Google | `POST /auth/invitations/{token}/google` `{id_token}` | The Google email must be the invited address. |
| Password reset link | `POST /auth/reset-password/{token}` | Ends every other session. |

All of them go through `App\Domain\People\Sessions\SignIn`:
- a deactivated account or suspended school is refused (403);
- `last_login_at` is updated;
- the session records the device.

Each accepts `device_name`, `app` (`admin`/`portal`) and `platform`
(`web`/`android`/`ios`). Unknown values are dropped, not refused, so older app
builds keep working.

`must_change_password` still applies after any method. The `password.changed`
middleware allows only the auth routes until a new password is set, because an
administrator knows the temporary one.

## Continue with Google

```
app ──Google account chooser──▶ Google ID token (JWT)
app ──POST /auth/google {id_token}──▶ API
        GoogleIdTokens::verify   signature (Google's JWKS), iss, aud ∈ our clients, exp, email_verified
        find the account         users.google_sub = sub, else users.email = verified email
        SignIn                   active? school not suspended? → session token
```

- **No account is ever created.** A Google account whose email is not on an Elimu Pass
  account gets 422: "No Elimu Pass account uses …". Accounts still come only from
  administrators and invitations.
- **Connecting:**
  - The first Google sign-in finds the account by email and stores Google's
    `sub` in `users.google_sub` (unique). From then on, the `sub` finds the
    account, even if the person's Google email changes.
  - An account already connected to a different Google account is not taken
    over.
- **Disconnecting:**
  - Changing the account's email (Staff screen) clears `google_sub`, so the old
    Google account stops working.
  - People can also disconnect Google themselves (`DELETE /auth/google`, Account
    page).
- **Invitations:** accepting with Google leaves the password as an unknown
  random one. The person can set one later with "Forgot password".
- **Passwords always keep working.** Google is an additional way in.
- **Switched off by default:** with no `GOOGLE_*_CLIENT_ID` set, the Google
  routes answer 404 and the apps hide the button.

Google's signing keys are cached for as long as Google's response allows, and
fetched again when a token names a key not seen yet.

### Setting up Google (Google Cloud Console)

1. **Project and consent screen.**
   - Create or choose a project.
   - Configure the OAuth consent screen with the app name, support email and the
     `email`, `profile` and `openid` scopes.
   - Until it is published, only listed test users can sign in.
2. **Web client** ("OAuth client ID" → Web application). This one client serves
   the browsers and Android, for both apps.
   - Authorised JavaScript origins: `http://localhost:5173`,
     `http://localhost:5174`, and each production app origin.
   - Authorised redirect URIs: `http://localhost:5173/auth/google/callback`,
     `http://localhost:5174/auth/google/callback`, and
     `https://<each app domain>/auth/google/callback`.
3. **Android clients**, one per app:
   - package `ke.codepass.educa.admin` / `ke.codepass.educa.portal`;
   - the SHA-1 of every signing key that installs it: debug keystore, release
     key, and the Play App Signing key.
   - Android sign-in asks for the **web** client ID. The Android client only
     proves the package and signature.
4. **iOS clients**, one per app, with bundle ID `ke.codepass.educa.admin` /
   `ke.codepass.educa.portal`. In each app's `ios/App/App/Info.plist`, add the
   client's *reversed client ID* (`com.googleusercontent.apps.…`) as a URL
   scheme under `CFBundleURLTypes`.
5. **API** (`.env`):
   - `GOOGLE_WEB_CLIENT_ID`;
   - `GOOGLE_IOS_CLIENT_ID` when the iOS apps are built. Depending on the SDK
     version, iOS tokens may carry the iOS client as their audience; listing
     both is safe.
   - `GOOGLE_ANDROID_CLIENT_ID` is not needed: Android tokens carry the web
     client.
6. **Apps** (`.env.local`, then rebuild): `VITE_GOOGLE_WEB_CLIENT_ID`, plus
   `VITE_GOOGLE_IOS_CLIENT_ID` for iOS builds. Then run `npx cap sync`.

The Google account must have the same email address as the Elimu Pass account.
Workspace addresses such as `@school.ac.ke` work if the school's domain is on
Google Workspace.

**Android troubleshooting:** if Google sign-in fails or reports "cancelled" right
after an account is picked, the SHA-1 or package name does not match. Filter
Logcat for `GoogleProvider`.

## Sessions

A session is a Sanctum personal access token. It also records `app`,
`platform`, `sign_in_method`, `ip_address` and `user_agent`
(`App\Domain\People\Models\PersonalAccessToken`).

| Rule | Setting | Default |
|---|---|---|
| Ends this long after sign-in, however much it is used | `SANCTUM_EXPIRATION` (minutes) | 90 days |
| Ends after this long unused | `SESSION_IDLE_DAYS` | 30 days |

- **Idle rule:** checked in `Sanctum::authenticateAccessTokensUsing`
  (`AppServiceProvider`). That runs before Sanctum stamps `last_used_at`.
- **After expiry:** the request gets 401, and the apps return to the sign-in
  page with "Your session has ended".
- **Pruning:** `sessions:prune` (daily) deletes sessions past either limit.

### Ending sessions

| Who | How |
|---|---|
| The person | Sign out (`POST /auth/logout`); on the Account page, sign out one device (`DELETE /auth/sessions/{id}`) or every other device (`DELETE /auth/sessions`) |
| An administrator | **Sign out everywhere** on the Staff screen (`POST /staff/{id}/sign-out`, permission `sign_out_staff`). The account keeps working. |
| Automatically | Password change (other devices), password reset, deactivation, removal, and the expiry rules above |

`GET /auth/sessions` lists the live sessions, the current one first. The staff
list shows each account's `last_active_at`, the latest use of any session.

## Tests

- `GoogleSignInTest` signs tokens with a throwaway RSA key and serves its public
  half as Google's key set, so the real verification runs.
- `SessionsTest` travels in time across both limits.
