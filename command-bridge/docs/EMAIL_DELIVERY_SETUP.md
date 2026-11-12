# Email delivery setup

Command Bridge uses one active method for every email. In **Admin > Settings > Email Delivery**, select SMTP relay (no authentication, username/password, or OAuth2) or Microsoft Graph API. Save the settings and use **Save and send test email**.

The application connects directly to the chosen service and does not call PHP `mail()` or rely on a local web-server mail transport.
