# Email delivery setup

Command Bridge uses one active method for every email: Microsoft Graph, SMTP with OAuth2 (Microsoft 365), SMTP with a
username and password, or an SMTP relay without authentication. Choose it in **Settings > Email / SMTP** and use
**Save and send test email**.

Step-by-step setup for each method (Microsoft 365, Google Workspace/Gmail, hosting providers, local relays) and the
meaning of each error message are in the app: press **? Help** on that page. The same text is in
[app/help/topics/admin/smtp.md](../app/help/topics/admin/smtp.md).

The application connects directly to the chosen service and does not call PHP `mail()` or rely on a local web-server
mail transport.
