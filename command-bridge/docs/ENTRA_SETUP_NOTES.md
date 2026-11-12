# Entra/M365 SSO setup

In the app, go to Admin > Microsoft Entra Settings.

## Redirect URI

Use the Redirect URI shown on the Entra Settings page exactly as displayed. It should normally end with:

`/auth/entra_callback.php`

Add that value in Microsoft Entra admin center under:

App registrations > your app > Authentication > Platform configurations > Web > Redirect URIs

## App registration steps

1. Create a new App Registration in Microsoft Entra.
2. Use single-tenant unless other agencies need access.
3. Add the app's Web redirect URI exactly as shown in the ICS app.
4. Create a client secret and copy the secret value into the ICS app.
5. Copy the Directory tenant ID and Application client ID into the ICS app.
6. Click Save and Validate Settings.

## User setup

For each organization user account using Microsoft sign-in:

- Set `auth_provider` to `entra`.
- Enter the user's Entra Object ID when possible.
- Enter the user's Entra User Principal Name, usually their M365/email sign-in.
- Assign normal ICS role/position in the Users/Roles screen.
- Entra authenticates the person; ICS role permissions are still managed in this app.

Local PIN users remain supported.
