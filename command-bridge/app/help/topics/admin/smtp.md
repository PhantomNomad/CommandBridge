# Email delivery

Command Bridge sends email when an administrator creates a user (their username and PIN), resets a PIN, or uses
**Email user** on the Users page. All of it goes out through the one method chosen on this page.
Only system administrators can change these settings.

> Nothing is sent until you press **Save and send test email** and the test arrives. Do that after every change.

## Which method should I use?

| Your email system | Use | Why |
|---|---|---|
| Microsoft 365 | **Microsoft Graph** | Most secure; no SMTP sign-in to keep working. Recommended. |
| Microsoft 365, but Graph is not allowed | **SMTP OAuth2** | Modern sign-in over SMTP. More setup steps than Graph. |
| Google Workspace or Gmail | **SMTP username and password** with an *app password* | Google no longer accepts the normal account password. |
| Your internet or web hosting provider | **SMTP username and password** | Use the settings your provider publishes. |
| A mail relay running on this same server | **No authentication** | Only safe when the relay is on this server. |

> **Warning:** Microsoft is switching off password sign-in for SMTP in Microsoft 365 (turned off by default from
> December 2026, with a final removal date still to be announced). If you use Microsoft 365, choose Graph or OAuth2,
> not "username/password".

All methods need this server to reach the internet: outbound HTTPS (port 443) to `login.microsoftonline.com` and
`graph.microsoft.com` for Graph and OAuth2, or outbound port 587 or 465 to your SMTP server.

## Microsoft Graph

Command Bridge signs in as an *app* (no user password) and sends through the Microsoft Graph API. You need someone who
can register apps in Microsoft Entra and run Exchange Online PowerShell (an Exchange or Global Administrator).

### 1. Choose the mailbox to send from

Use a **shared mailbox** such as `commandbridge@example.ca`. Shared mailboxes need no licence, and replies from
people go somewhere your team can read. Create it in the Exchange admin center if it does not exist.

### 2. Register the app

1. In the [Microsoft Entra admin center](https://entra.microsoft.com), open **App registrations** > **New registration**.
2. Name it, for example *Command Bridge email*. Leave *Accounts in this organizational directory only*. No redirect URI.
3. On the app's **Overview**, copy the **Application (client) ID** and **Directory (tenant) ID**.
4. Open **Certificates & secrets** > **New client secret**. Pick an expiry (24 months at most) and copy the
   **Value** straight away. It is shown only once. The *Secret ID* is not the secret.

> Put the secret's expiry date in your calendar. When it expires, email stops with "Microsoft token request failed".

### 3. Allow the app to send from that one mailbox

**Recommended: limit the app to the one mailbox** (Exchange "RBAC for Applications"). Do *not* add the Mail.Send
permission in Entra for this option; if it is there, the limit does nothing.

You need two IDs from **Enterprise applications** (not App registrations, which shows different values): open
*Enterprise applications*, find the app, and copy its **Application ID** and **Object ID**. Then, in PowerShell with
the ExchangeOnlineManagement module:

```
Connect-ExchangeOnline
New-ServicePrincipal -AppId <Application ID> -ObjectId <Object ID> -DisplayName "Command Bridge email"
New-ManagementScope -Name "Command Bridge sender" -RecipientRestrictionFilter "PrimarySmtpAddress -eq 'commandbridge@example.ca'"
New-ManagementRoleAssignment -App <Application ID> -Role "Application Mail.Send" -CustomResourceScope "Command Bridge sender"
Test-ServicePrincipalAuthorization -Identity <Application ID> -Resource commandbridge@example.ca
```

The last command should show `InScope` as `True`. It can take **30 minutes to 2 hours** before Microsoft applies the
new permission. Until then the test email fails with HTTP 403.

**Quicker but broader:** in the app registration open **API permissions** > **Add a permission** > **Microsoft
Graph** > **Application permissions** > **Mail.Send**, then **Grant admin consent**. This lets the app send as *any*
mailbox in your organization, so anyone holding the secret could too. Use the limited option above if you can.

### 4. Fill in this page

| Field | Value |
|---|---|
| Delivery method | Microsoft Graph API |
| Sender email address | the mailbox from step 1 |
| Tenant ID | Directory (tenant) ID |
| Application (client) ID | Application (client) ID |
| Application client secret | the secret **Value** |
| Sending mailbox | the mailbox from step 1 |

With Graph, the name people see is the mailbox's own display name; *Sender display name* is not used.
Press **Save and send test email**.

## SMTP OAuth2 (Microsoft 365)

Command Bridge signs in to Microsoft 365's SMTP server with an app token instead of a password. This is Microsoft's
supported way to keep using SMTP. It needs the same kind of administrator as Graph.

1. Choose the mailbox to send from (a shared mailbox is fine) and register the app with a client secret, exactly as in
   steps 1 and 2 of [Microsoft Graph](#microsoft-graph).
2. In the app registration open **API permissions** > **Add a permission** > **APIs my organization uses** >
   **Office 365 Exchange Online** > **Application permissions** > **SMTP.SendAsApp**. Then **Grant admin consent**.
3. Copy the app's **Application ID** and **Object ID** from **Enterprise applications**, and run:

```
Connect-ExchangeOnline
New-ServicePrincipal -AppId <Application ID> -ObjectId <Object ID> -DisplayName "Command Bridge email"
Add-MailboxPermission -Identity commandbridge@example.ca -User <Object ID> -AccessRights FullAccess
Set-CASMailbox -Identity commandbridge@example.ca -SmtpClientAuthenticationDisabled $false
```

The last line makes sure SMTP sign-in is allowed for that mailbox. If your organization has turned SMTP sign-in off
for everyone, an Exchange administrator has to allow it for this mailbox.

> **Warning:** For SMTP, do not *also* set up the RBAC for Applications role from the Graph section for the same app.
> Microsoft then checks the wrong permission and sign-in fails with `535 5.7.3`.

4. Fill in this page:

| Field | Value |
|---|---|
| Delivery method | SMTP relay — OAuth2 / XOAUTH2 |
| SMTP host / Port / Encryption | `smtp.office365.com` / `587` / STARTTLS |
| Username / mailbox | the mailbox address |
| Sender email address | the same mailbox address |
| Tenant ID, Application (client) ID, client secret | from the app registration |
| OAuth scope | leave `https://outlook.office365.com/.default` |

New permissions can take up to an hour to work. A brand-new mailbox can take longer.

## SMTP username and password

Command Bridge signs in to an SMTP server with a username and password (`AUTH LOGIN`), always over an encrypted
connection unless the server is this machine.

### Google Workspace and Gmail

Google no longer accepts the normal account password from apps. You need an **app password**:

1. Turn on **2-Step Verification** for the Google account that will send.
2. Create an app password at [myaccount.google.com/apppasswords](https://myaccount.google.com/apppasswords).
   Google shows a 16-character code once. If the option is missing, your Workspace administrator has blocked app passwords.
3. Fill in this page:

| Field | Value |
|---|---|
| SMTP host | `smtp.gmail.com` |
| Port / Encryption | `587` / STARTTLS (or `465` / SSL/TLS) |
| Username / mailbox | the full Google address |
| Password | the app password (the spaces do not matter) |
| Sender email address | the same address, or an alias set up in Gmail |

Google limits how many messages an account can send per day. That is far more than Command Bridge normally sends.

### Your internet or web hosting provider

Use the "outgoing mail (SMTP)" settings your provider publishes. Almost always: port `587` with STARTTLS, or `465` with
SSL/TLS; the username is the full email address. The sender address must usually be that same mailbox.

### Microsoft 365 with a password

Works only while Microsoft still allows it (see the warning at the top), needs SMTP sign-in enabled for the mailbox,
and fails for accounts protected by multi-factor sign-in. Use [Microsoft Graph](#microsoft-graph) instead.

## SMTP relay without authentication

Command Bridge hands email to a relay without signing in. The relay must then decide who is allowed to use it, usually by
IP address. That is only safe when nobody else can reach it.

- **A relay on this server** (for example Postfix set to accept mail only from this machine): host `localhost`, port
  `25`, encryption *None*. This is the one case where "no authentication" is a good choice.
- **A relay elsewhere** (for example an on-premises Exchange receive connector that accepts this server's IP address):
  Command Bridge asks you to tick a box confirming the relay accepts mail only from this server. If any other computer
  can also send through it, anyone on your network can send email that looks like it came from you.

> **Warning:** Never point "no authentication" at your organization's public mail server, or at a relay that accepts
> connections from a whole network.

## Testing and error messages

**Save and send test email** saves the settings and sends one message. "Test email accepted for delivery" means the
mail server accepted it. If it does not arrive within a few minutes, check the recipient's junk folder.

| Message | What it usually means |
|---|---|
| Unable to connect to the configured SMTP relay | Wrong host or port, a firewall blocks outbound mail ports from this server, or port 465 with STARTTLS (465 needs SSL/TLS). |
| Unable to establish TLS with SMTP relay | The server's certificate is self-signed, expired, or not issued for the host name you typed. Use the name on the certificate. |
| SMTP relay rejected the request (535) | Sign-in failed: wrong username or password, Google needs an app password, SMTP sign-in is turned off for the mailbox, or OAuth2 permissions are not finished. |
| SMTP relay rejected the request (550 or 554) | The server refused the sender or recipient, often because the account may not send as the *Sender email address*. |
| Microsoft token request failed (HTTP 400 or 401) | Tenant ID, client ID or secret is wrong, or the secret has expired. |
| Microsoft Graph rejected the email (HTTP 403) | The app may not send from that mailbox yet: the RBAC role is still taking effect (up to 2 hours), or the permission or scope is missing. |
| Microsoft Graph rejected the email (HTTP 404) | The *Sending mailbox* address does not exist in your Microsoft 365 organization. |
| Microsoft Graph settings are incomplete | A Graph field is empty. Saved secrets show as "Saved — leave blank to retain". |

More detail is written to the server's error log (`/var/log/apache2/commandbridge-error.log`), on lines starting
`Command Bridge email:`.

## Good practice

- Send from a dedicated or shared mailbox, not a person's own mailbox.
- Record who created the app registration and when its secret expires.
- Secrets are stored in the Command Bridge database and are never shown again on this page. To replace one, type the
  new value and save; leaving the field blank keeps the saved one.
