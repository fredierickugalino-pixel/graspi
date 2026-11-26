Forgot password setup
=====================

1. In phpMyAdmin, select the `drick` database and run schema.sql once.

2. Set up a Gmail sender account:
   - Turn on 2-Step Verification for that Google account.
   - In Google Account security settings, create an App Password. Use the generated
     App Password here, not your normal Gmail password.

3. Open forgotpassword/smtp-config.php in a text editor. Fill in `username` with your
   Gmail address, `password` with your Google App Password, and `from_email` with your
   Gmail address. Leave the host, port, and encryption values as they are for Gmail.
   Save the file. The folder's .htaccess blocks direct browser access to this file,
   and its .gitignore keeps the credentials out of Git commits.

4. Open http://localhost/new%20online/forgotpassword/index.php and request a reset PIN.
   The 6-digit PIN expires after 10 minutes and can be used once.

If sending still fails, check C:\xampp\apache\logs\error.log for the detailed SMTP
error. Never share your App Password in chat or commit it to the project.
