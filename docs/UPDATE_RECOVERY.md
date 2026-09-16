# Update button stuck? Start here

On some older versions, clicking **Update** starts a counter but never sends the
update command. **Reboot** and **Shutdown** may also appear to do nothing. This
browser-side bug was fixed in **v2.8.1** and the fix is included in later releases.

A counter appearing briefly is normal. These instructions are for the known
failure where the counter keeps running and the update never starts, not every
slow or failed update. If an update is already running, let it finish; do not
start another one.

## Start the update from your browser

Open your BirdNET-Pi, then paste this into your browser's address bar:

```text
http://birdnetpi.local/views.php?submit=update_birdnet.sh
```

If your BirdNET-Pi has a different address, change only the `http://birdnetpi.local`
part. Leave everything after it unchanged. You can find your station's address
in the address bar when you open BirdNET-Pi; keep `https://` if that is what it uses.

**Opening this address starts the update immediately, without a confirmation
dialog.** Sign in with your station's administrator credentials if prompted.

## Alternatively, use SSH or Web Terminal

Run these commands as your station's normal user:

```bash
cd ~/BirdNET-Pi
./scripts/update_birdnet.sh
```

Both methods run the existing updater, which normally switches to the `main`
branch and restarts services. Save any custom source-code edits first: the
updater resets local tracked-file changes. Detection and recording services
will be briefly interrupted. A current backup is always recommended.

Once it finishes, refresh the page (hard-refresh if necessary). You can use the
normal Update button for future updates. **Do not use Clear or Restore to fix
this problem; no manual database changes are needed.**

If neither method works, save the update output and report what happened in a
[GitHub issue](https://github.com/zach7036/BirdNET-Pi-Enhanced-Version/issues).
Remove passwords, tokens, and other private information before sharing logs.

See the [latest release](https://github.com/zach7036/BirdNET-Pi-Enhanced-Version/releases/latest)
for current release notes. This is recovery guidance for older installations,
not a request to reinstall or erase your station.
